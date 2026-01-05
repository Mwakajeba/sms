<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Company;
use App\Jobs\CheckSubscriptionExpiryJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $subscriptions = Subscription::with('company')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('subscriptions.index', compact('subscriptions'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $companies = Company::all();

        return view('subscriptions.create', compact('companies'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'end_date' => 'required|date|after:today',
            'notification_days' => 'required|integer|min:1|max:365',
        ]);

        $companyId = $request->company_id;

        // Check if there's already an active subscription
        $activeSubscription = Subscription::where('company_id', $companyId)
            ->where('status', 'active')
            ->first();

        if ($activeSubscription) {
            return redirect()->back()
                ->withErrors(['error' => 'There is already an active subscription for this company.'])
                ->withInput();
        }

        // Get company name for plan name
        $company = Company::findOrFail($companyId);

        // Set default values for removed fields
        $subscription = Subscription::create([
            'company_id' => $companyId,
            'plan_name' => 'Manual Subscription - ' . $company->name,
            'plan_description' => 'Manual subscription created by administrator',
            'amount' => 0,
            'currency' => 'TZS',
            'billing_cycle' => 'monthly',
            'start_date' => Carbon::now(),
            'end_date' => $request->end_date,
            'status' => 'active', // Always active for manual subscriptions
            'payment_status' => 'paid', // Always paid for manual subscriptions
            'features' => [
                'notification_days' => (int)$request->notification_days
            ],
        ]);

        // Unlock users for this company
        $this->unlockCompanyUsers($subscription->company_id);

        // Dispatch subscription expiry check job
        CheckSubscriptionExpiryJob::dispatch();

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'Subscription created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Subscription $subscription)
    {
        $subscription->load('company');
        return view('subscriptions.show', compact('subscription'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Subscription $subscription)
    {
        $subscription->load('company');
        $companies = Company::all();
        return view('subscriptions.edit', compact('subscription', 'companies'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Subscription $subscription)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'end_date' => 'required|date',
            'notification_days' => 'required|integer|min:1|max:365',
        ]);

        // Store the old status to check if we need to unlock users
        $oldStatus = $subscription->status;

        // Get current features or create new array
        $features = $subscription->features ?? [];
        $features['notification_days'] = (int)$request->notification_days;

        // Prepare update data
        $updateData = [
            'company_id' => $request->company_id,
            'end_date' => $request->end_date,
            'features' => $features,
        ];

        // Auto-update status based on end date and payment status
        $endDate = Carbon::parse($request->end_date);
        $now = Carbon::now();

        if ($subscription->payment_status === 'paid') {
            if ($endDate->isFuture()) {
                // Subscription is paid and not expired - make it active
                $updateData['status'] = 'active';
            } else {
                // Subscription is paid but expired - keep as expired
                $updateData['status'] = 'expired';
            }
        } else {
            // Not paid - keep current status or set to pending
            $updateData['status'] = $subscription->status ?? 'pending';
        }

        $subscription->update($updateData);

        // If subscription becomes active and paid, unlock users
        if (
            $subscription->status === 'active' && $subscription->payment_status === 'paid' &&
            $oldStatus !== 'active'
        ) {
            $this->unlockCompanyUsers($subscription->company_id);

            // Send activation notification
            $job = new \App\Jobs\CheckSubscriptionExpiryJob();
            $job->sendActivationNotification($subscription);
        }

        // Dispatch subscription expiry check job to process any changes
        CheckSubscriptionExpiryJob::dispatch();

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'Subscription updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Subscription $subscription)
    {
        // Only allow deletion of pending or cancelled subscriptions
        if (!in_array($subscription->status, ['pending', 'cancelled'])) {
            return redirect()->back()
                ->withErrors(['error' => 'Only pending or cancelled subscriptions can be deleted.']);
        }

        $subscription->delete();

        return redirect()->route('subscriptions.index')
            ->with('success', 'Subscription deleted successfully.');
    }

    /**
     * Mark subscription as paid
     */
    public function markAsPaid(Request $request, Subscription $subscription)
    {
        $request->validate([
            'payment_method' => 'required|string|max:255',
            'transaction_id' => 'nullable|string|max:255',
            'payment_notes' => 'nullable|string',
        ]);

        $subscription->markAsPaid(
            $request->payment_method,
            $request->transaction_id,
            $request->payment_notes
        );

        // Unlock users for this company
        $this->unlockCompanyUsers($subscription->company_id);

        // Send activation notification
        $job = new CheckSubscriptionExpiryJob();
        $job->sendActivationNotification($subscription);

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'Subscription marked as paid successfully. All users have been unlocked.');
    }

    /**
     * Cancel subscription
     */
    public function cancel(Subscription $subscription)
    {
        if ($subscription->status === 'cancelled') {
            return redirect()->back()
                ->withErrors(['error' => 'Subscription is already cancelled.']);
        }

        $subscription->update(['status' => 'cancelled']);

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'Subscription cancelled successfully.');
    }

    /**
     * Renew subscription
     */
    public function renew(Subscription $subscription)
    {
        if ($subscription->status !== 'expired') {
            return redirect()->back()
                ->withErrors(['error' => 'Only expired subscriptions can be renewed.']);
        }

        $subscription->renew();

        // Dispatch subscription expiry check job to process any changes
        CheckSubscriptionExpiryJob::dispatch();

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'Subscription renewed successfully. Please make payment to activate.');
    }

    /**
     * Extend subscription
     */
    public function extend(Request $request, Subscription $subscription)
    {
        $request->validate([
            'days' => 'required|integer|min:1|max:365',
        ]);

        // Store old status to check if we need to unlock users
        $oldStatus = $subscription->status;

        $subscription->extend($request->days);

        // Auto-update status based on new end date and payment status
        if ($subscription->payment_status === 'paid') {
            if (Carbon::parse($subscription->end_date)->isFuture()) {
                // Subscription is paid and not expired - make it active
                $subscription->update(['status' => 'active']);
            } else {
                // Subscription is paid but still expired - keep as expired
                $subscription->update(['status' => 'expired']);
            }
        }

        // If subscription becomes active and paid, unlock users
        if ($subscription->status === 'active' && $subscription->payment_status === 'paid' && $oldStatus !== 'active') {
            $this->unlockCompanyUsers($subscription->company_id);
        }

        // Dispatch subscription expiry check job to process any changes
        CheckSubscriptionExpiryJob::dispatch();

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', "Subscription extended by {$request->days} days successfully.");
    }

    /**
     * Get subscription dashboard data
     */
    public function dashboard()
    {
        $stats = [
            'total_subscriptions' => Subscription::count(),
            'active_subscriptions' => Subscription::active()->count(),
            'expiring_soon' => Subscription::expiringSoon()->count(),
            'expired' => Subscription::expired()->count(),
            'pending_payments' => Subscription::where('payment_status', 'pending')->count(),
        ];

        // Get recent subscriptions
        $recent_subscriptions = Subscription::with('company')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // Get expiring subscriptions
        $expiring_subscriptions = Subscription::with('company')
            ->expiringSoon()
            ->get();

        return view('subscriptions.dashboard', compact('stats', 'recent_subscriptions', 'expiring_subscriptions'));
    }

    /**
     * Unlock all users for a company (except super-admin)
     */
    private function unlockCompanyUsers(int $companyId): void
    {
        $users = \App\Models\User::where('company_id', $companyId)
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'super-admin');
            })
            ->get();

        foreach ($users as $user) {
            $user->update([
                'status' => 'active',
                'is_active' => 'yes',
            ]);
        }

        \Log::info("Unlocked {$users->count()} users for company ID: {$companyId} (super-admin users excluded)");
    }
}