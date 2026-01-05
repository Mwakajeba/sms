<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use App\Services\SystemSettingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscriptionStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip check for API routes
        if ($request->is('api/*')) {
            return $next($request);
        }

        // Skip check for guest users
        if (!Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();
        $companyId = $user->company_id;

        // Skip check for super admin - they have access regardless of subscription
        if ($user->hasRole('super-admin')) {
            return $next($request);
        }

        // Skip check if user is already suspended/inactive or has no company
        if (in_array($user->status, ['suspended', 'inactive']) || !$companyId) {
            if (in_array($user->status, ['suspended', 'inactive'])) {
                Auth::logout();
                return redirect()->route('subscription.expired');
            }
            return $next($request);
        }

        // First, check if there's any paid subscription for this company
        $anySubscription = Subscription::where('company_id', $companyId)
            ->where('payment_status', 'paid')
            ->orderBy('end_date', 'desc')
            ->first();

        // If no subscription at all, allow access to subscription management routes only
        if (!$anySubscription) {
            $allowedRoutes = [
                'subscriptions.*',
                'logout',
                'login',
                'password.*',
                'verification.*',
                'subscription.expired',
                'change-branch', // Allow branch selection
            ];

            $currentRoute = $request->route() ? $request->route()->getName() : null;
            $currentPath = $request->path();

            // Check if current route is in allowed routes
            $isAllowed = false;
            if ($currentRoute) {
                foreach ($allowedRoutes as $allowedRoute) {
                    if (str_contains($allowedRoute, '*')) {
                        $pattern = str_replace('*', '.*', $allowedRoute);
                        if (preg_match('/^' . $pattern . '$/', $currentRoute)) {
                            $isAllowed = true;
                            break;
                        }
                    } elseif ($currentRoute === $allowedRoute) {
                        $isAllowed = true;
                        break;
                    }
                }
            }

            // Also check path for common auth routes
            if (!$isAllowed && in_array($currentPath, ['login', 'logout', 'subscription-expired', 'change-branch'])) {
                $isAllowed = true;
            }

            if ($isAllowed) {
                return $next($request);
            }

            // Log the blocked access attempt
            \Log::warning("Blocked access - no subscription", [
                'user_id' => $user->id,
                'company_id' => $companyId,
                'route' => $currentRoute,
                'path' => $currentPath
            ]);

            return redirect()->route('subscription.expired');
        }

        // Check if subscription is expired based on end_date (primary check)
        // Parse end_date to Carbon for proper comparison
        $endDate = Carbon::parse($anySubscription->end_date);
        $now = Carbon::now();
        
        // A subscription is expired if:
        // 1. The end_date has passed (end_date < now), OR
        // 2. The status is explicitly set to 'expired'
        $isExpired = $endDate->lt($now) || $anySubscription->status === 'expired';

        if ($isExpired) {
            // Log the expired subscription detection
            \Log::warning("Expired subscription detected - blocking access", [
                'user_id' => $user->id,
                'company_id' => $companyId,
                'subscription_id' => $anySubscription->id,
                'end_date' => $anySubscription->end_date,
                'end_date_parsed' => $endDate->toDateTimeString(),
                'now' => $now->toDateTimeString(),
                'status' => $anySubscription->status,
                'payment_status' => $anySubscription->payment_status,
                'days_until_expiry' => $anySubscription->daysUntilExpiry(),
                'route' => $request->route() ? $request->route()->getName() : null,
                'path' => $request->path()
            ]);

            // Lock all users for this company (except super-admin)
            $this->lockCompanyUsers($companyId);
            
            // Logout and redirect to expired page
            Auth::logout();
            return redirect()->route('subscription.expired');
        }

        // If end_date is in the future, check payment status
        // If payment_status is not 'paid', block access
        if ($anySubscription->payment_status !== 'paid') {
            \Log::warning("Subscription not paid - blocking access", [
                'user_id' => $user->id,
                'company_id' => $companyId,
                'subscription_id' => $anySubscription->id,
                'payment_status' => $anySubscription->payment_status,
                'status' => $anySubscription->status,
            ]);
            
            $this->lockCompanyUsers($companyId);
            Auth::logout();
            return redirect()->route('subscription.expired');
        }

        // If end_date is in the future AND payment_status is 'paid', allow access
        // Note: We don't require status === 'active' if end_date is in the future
        // This allows subscriptions that may have been incorrectly marked as non-active
        // but still have a valid end_date in the future
        $activeSubscription = $anySubscription;
        
        // If status is not 'active' but end_date is in the future, update status to 'active'
        if ($activeSubscription->status !== 'active' && $endDate->gte($now)) {
            \Log::info("Auto-correcting subscription status to 'active'", [
                'subscription_id' => $activeSubscription->id,
                'old_status' => $activeSubscription->status,
                'end_date' => $activeSubscription->end_date,
            ]);
            $activeSubscription->update(['status' => 'active']);
        }

        // Calculate days until expiry for notifications
        $daysUntilExpiry = $activeSubscription->daysUntilExpiry();

        // Get notification days - check subscription-specific first, then system settings
        $subscriptionNotificationDays = null;
        if ($activeSubscription->features && isset($activeSubscription->features['notification_days'])) {
            $subscriptionNotificationDays = (int)$activeSubscription->features['notification_days'];
        }
        
        // Fall back to system settings if not set in subscription
        $notificationDays = $subscriptionNotificationDays ?? SystemSettingService::get('subscription_notification_days_30', 30);
        $notificationDays20 = SystemSettingService::get('subscription_notification_days_20', 20);

        // Check if subscription is expiring soon and show notification
        if ($daysUntilExpiry >= 0 && $daysUntilExpiry <= $notificationDays) {
            $notificationShown = $request->session()->get('subscription_notification_shown', false);
            
            if (!$notificationShown) {
                $message = "Your subscription will expire in {$daysUntilExpiry} day(s). Please renew to avoid service interruption.";
                
                // Different message for 20 days or less
                if ($daysUntilExpiry <= $notificationDays20) {
                    $message = "URGENT: Your subscription will expire in {$daysUntilExpiry} day(s). Please renew immediately to avoid service interruption.";
                }
                
                $request->session()->flash('warning', $message);
                $request->session()->put('subscription_notification_shown', true);
            }
        }

        return $next($request);
    }

    /**
     * Suspend all users for a company (except super-admin) due to expired subscription
     */
    private function lockCompanyUsers(int $companyId): void
    {
        $users = \App\Models\User::where('company_id', $companyId)
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'super-admin');
            })
            ->get();

        foreach ($users as $user) {
            $user->update([
                'status' => 'suspended', // Use 'suspended' instead of 'locked' (valid enum value)
                'is_active' => 'no',
            ]);
        }

        \Log::info("Suspended {$users->count()} users for company ID: {$companyId} due to expired subscription (super-admin users excluded)");
    }
}