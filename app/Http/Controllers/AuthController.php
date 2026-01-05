<?php

namespace App\Http\Controllers;

use App\Models\OtpCode;
use App\Models\LoginAttempt;
use App\Rules\PasswordValidation;
use App\Services\SystemSettingService;
use Illuminate\Support\Carbon;
use App\Helpers\SmsHelper;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use Jenssegers\Agent\Facades\Agent;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'phone' => 'required',
            'password' => 'required',
        ]);

        $agent = new Agent();

        $deviceInfo = 'Unknown';
        if ($agent::isDesktop()) {
            $deviceInfo = 'Desktop';
        } elseif ($agent::isPhone()) {
            if ($agent::is('iPhone')) {
                $deviceInfo = 'iPhone';
            } elseif ($agent::is('AndroidOS')) {
                $deviceInfo = 'Android Phone';
            } else {
                $deviceInfo = 'Phone';
            }
        } elseif ($agent::isTablet()) {
            if ($agent::is('iPad')) {
                $deviceInfo = 'iPad';
            } else {
                $deviceInfo = 'Tablet';
            }
        }

        $deviceString = $deviceInfo . ' - ' . $agent::browser();

        if (LoginAttempt::isLockedOut($request->ip())) {
            $remainingTime = LoginAttempt::getRemainingLockoutTime($request->ip());

            ActivityLog::create([
                'user_id' => null,
                'model' => 'Auth',
                'action' => 'login_failed',
                'description' => "Login blocked - too many attempts for {$request->phone}",
                'ip_address' => $request->ip(),
                'device' => $deviceString,
                'activity_time' => now(),
            ]);

            return back()->withErrors([
                'phone' => "Account is temporarily locked. Please try again in {$remainingTime} minutes.",
            ])->withInput();
        }

        $user = find_user_by_phone($request->phone);

        if (!$user) {
            LoginAttempt::record($request->phone, $request->ip(), $request->userAgent(), false);

            ActivityLog::create([
                'user_id' => null,
                'model' => 'Auth',
                'action' => 'login_failed',
                'description' => "Login failed - phone not found ({$request->phone})",
                'ip_address' => $request->ip(),
                'device' => $deviceString,
                'activity_time' => now(),
            ]);

            return back()->withErrors([
                'phone' => 'Phone number not found.',
            ])->withInput();
        }

        // Check subscription status FIRST before checking user status
        // This allows us to reactivate users if their subscription is valid
        $subscriptionValid = false;
        if ($user->company_id) {
            $subscription = \App\Models\Subscription::where('company_id', $user->company_id)
                ->where('payment_status', 'paid')
                ->orderBy('end_date', 'desc')
                ->first();

            if ($subscription) {
                // Parse end_date to Carbon for proper comparison
                $endDate = \Carbon\Carbon::parse($subscription->end_date);
                $now = \Carbon\Carbon::now();
                
                // A subscription is expired if:
                // 1. The end_date has passed (end_date < now), OR
                // 2. The status is explicitly set to 'expired'
                $isExpired = $endDate->lt($now) || $subscription->status === 'expired';
                
                \Log::info("Login subscription check", [
                    'user_id' => $user->id,
                    'company_id' => $user->company_id,
                    'subscription_id' => $subscription->id,
                    'end_date' => $subscription->end_date,
                    'end_date_parsed' => $endDate->toDateTimeString(),
                    'now' => $now->toDateTimeString(),
                    'is_expired' => $isExpired,
                    'status' => $subscription->status,
                    'payment_status' => $subscription->payment_status,
                    'user_status' => $user->status,
                    'user_is_active' => $user->is_active
                ]);
                
                // Check if subscription is expired (by date or status)
                if ($isExpired) {
                    // Suspend user if not already suspended
                    if ($user->status !== 'suspended') {
                        $user->update([
                            'status' => 'suspended', // Use 'suspended' (valid enum value)
                            'is_active' => 'no',
                        ]);
                    }

                    LoginAttempt::record($request->phone, $request->ip(), $request->userAgent(), false);

                    ActivityLog::create([
                        'user_id' => $user->id,
                        'model' => 'Auth',
                        'action' => 'login_failed',
                        'description' => "Login blocked - subscription expired (end_date: {$subscription->end_date})",
                        'ip_address' => $request->ip(),
                        'device' => $deviceString,
                        'activity_time' => now(),
                    ]);

                    return redirect()->route('subscription.expired')->with('error', 'Your subscription has expired. Please contact your administrator to renew.');
                }
                
                // Check payment status - must be 'paid'
                if ($subscription->payment_status !== 'paid') {
                    \Log::warning("Login blocked - subscription not paid", [
                        'user_id' => $user->id,
                        'company_id' => $user->company_id,
                        'subscription_id' => $subscription->id,
                        'payment_status' => $subscription->payment_status,
                    ]);
                    
                    LoginAttempt::record($request->phone, $request->ip(), $request->userAgent(), false);
                    
                    ActivityLog::create([
                        'user_id' => $user->id,
                        'model' => 'Auth',
                        'action' => 'login_failed',
                        'description' => "Login blocked - subscription payment not completed (payment_status: {$subscription->payment_status})",
                        'ip_address' => $request->ip(),
                        'device' => $deviceString,
                        'activity_time' => now(),
                    ]);
                    
                    return redirect()->route('subscription.expired')->with('error', 'Your subscription payment is pending. Please contact your administrator.');
                }
                
                // Subscription is valid (end_date in future and payment_status is 'paid')
                $subscriptionValid = true;
                
                // Auto-correct subscription status to 'active' if it's not already
                if ($subscription->status !== 'active' && $endDate->gte($now)) {
                    \Log::info("Auto-correcting subscription status to 'active' during login", [
                        'subscription_id' => $subscription->id,
                        'old_status' => $subscription->status,
                        'end_date' => $subscription->end_date,
                    ]);
                    $subscription->update(['status' => 'active']);
                }
                
                // Reactivate user if they were suspended but subscription is now valid
                if (in_array($user->status, ['suspended', 'inactive']) && $subscriptionValid) {
                    \Log::info("Reactivating user - subscription is valid", [
                        'user_id' => $user->id,
                        'old_status' => $user->status,
                        'old_is_active' => $user->is_active,
                        'subscription_id' => $subscription->id,
                    ]);
                    $user->update([
                        'status' => 'active',
                        'is_active' => 'yes',
                    ]);
                    // Refresh user model to get updated values
                    $user->refresh();
                }
            } else {
                // No subscription found - check if user is suspended
                if (in_array($user->status, ['suspended', 'inactive'])) {
                    LoginAttempt::record($request->phone, $request->ip(), $request->userAgent(), false);

                    ActivityLog::create([
                        'user_id' => $user->id,
                        'model' => 'Auth',
                        'action' => 'login_failed',
                        'description' => "Login blocked - no subscription found and user account is {$user->status}",
                        'ip_address' => $request->ip(),
                        'device' => $deviceString,
                        'activity_time' => now(),
                    ]);

                    return redirect()->route('subscription.expired')->with('error', 'No active subscription found for your company. Please contact your administrator.');
                }
            }
        } else {
            // No company_id - check if user is suspended
            if (in_array($user->status, ['suspended', 'inactive'])) {
                LoginAttempt::record($request->phone, $request->ip(), $request->userAgent(), false);

                ActivityLog::create([
                    'user_id' => $user->id,
                    'model' => 'Auth',
                    'action' => 'login_failed',
                    'description' => "Login blocked - user account is {$user->status} and has no company",
                    'ip_address' => $request->ip(),
                    'device' => $deviceString,
                    'activity_time' => now(),
                ]);

                return redirect()->route('subscription.expired')->with('error', 'Your account has been suspended. Please contact your administrator.');
            }
        }

        // Check if user is active
        if ($user->is_active !== 'yes' || $user->status === 'inactive' || $user->status === 'suspended') {
            LoginAttempt::record($request->phone, $request->ip(), $request->userAgent(), false);

            ActivityLog::create([
                'user_id' => $user->id,
                'model' => 'Auth',
                'action' => 'login_failed',
                'description' => "Login failed - user account is {$user->status} (is_active: {$user->is_active})",
                'ip_address' => $request->ip(),
                'device' => $deviceString,
                'activity_time' => now(),
            ]);

            $errorMessage = 'Your account is currently inactive. Please contact your administrator.';
            if ($user->status === 'suspended') {
                $errorMessage = 'Your account has been suspended due to expired subscription. Please contact your administrator.';
            }

            return back()->withErrors([
                'phone' => $errorMessage,
            ])->withInput();
        }

        $credentials = [
            'phone' => $user->phone,
            'password' => $request->password
        ];

        if (Auth::attempt($credentials)) {
            // Re-check subscription status after successful login
            if ($user->company_id) {
                $subscription = \App\Models\Subscription::where('company_id', $user->company_id)
                    ->where('payment_status', 'paid')
                    ->orderBy('end_date', 'desc')
                    ->first();

                if ($subscription) {
                    // Parse end_date to Carbon for proper comparison
                    $endDate = \Carbon\Carbon::parse($subscription->end_date);
                    $now = \Carbon\Carbon::now();
                    
                    // A subscription is expired if:
                    // 1. The end_date has passed (end_date < now), OR
                    // 2. The status is explicitly set to 'expired'
                    $isExpired = $endDate->lt($now) || $subscription->status === 'expired';
                    
                    \Log::info("Post-login subscription check", [
                        'user_id' => $user->id,
                        'company_id' => $user->company_id,
                        'subscription_id' => $subscription->id,
                        'end_date' => $subscription->end_date,
                        'end_date_parsed' => $endDate->toDateTimeString(),
                        'now' => $now->toDateTimeString(),
                        'is_expired' => $isExpired,
                        'status' => $subscription->status,
                        'payment_status' => $subscription->payment_status
                    ]);
                    
                    // Check if subscription is expired (by date or status)
                    if ($isExpired) {
                        // Suspend user and logout immediately
                        $user->update([
                            'status' => 'suspended', // Use 'suspended' (valid enum value)
                            'is_active' => 'no',
                        ]);
                        
                        Auth::logout();
                        
                        LoginAttempt::record($user->phone, $request->ip(), $request->userAgent(), false);

                        ActivityLog::create([
                            'user_id' => $user->id,
                            'model' => 'Auth',
                            'action' => 'login_blocked',
                            'description' => "Login blocked after authentication - subscription expired (end_date: {$subscription->end_date})",
                            'ip_address' => $request->ip(),
                            'device' => $deviceString,
                            'activity_time' => now(),
                        ]);

                        return redirect()->route('subscription.expired')->with('error', 'Your subscription has expired. Please contact your administrator to renew.');
                    }
                    
                    // Check payment status - must be 'paid'
                    if ($subscription->payment_status !== 'paid') {
                        \Log::warning("Post-login check - subscription not paid", [
                            'user_id' => $user->id,
                            'company_id' => $user->company_id,
                            'subscription_id' => $subscription->id,
                            'payment_status' => $subscription->payment_status,
                        ]);
                        
                        $user->update([
                            'status' => 'suspended',
                            'is_active' => 'no',
                        ]);
                        
                        Auth::logout();
                        
                        LoginAttempt::record($user->phone, $request->ip(), $request->userAgent(), false);
                        
                        ActivityLog::create([
                            'user_id' => $user->id,
                            'model' => 'Auth',
                            'action' => 'login_blocked',
                            'description' => "Login blocked after authentication - subscription payment not completed (payment_status: {$subscription->payment_status})",
                            'ip_address' => $request->ip(),
                            'device' => $deviceString,
                            'activity_time' => now(),
                        ]);
                        
                        return redirect()->route('subscription.expired')->with('error', 'Your subscription payment is pending. Please contact your administrator.');
                    }
                    
                    // If end_date is in the future and payment_status is 'paid', allow login
                    // Auto-correct status to 'active' if it's not already
                    if ($subscription->status !== 'active' && $endDate->gte($now)) {
                        \Log::info("Auto-correcting subscription status to 'active' after login", [
                            'subscription_id' => $subscription->id,
                            'old_status' => $subscription->status,
                            'end_date' => $subscription->end_date,
                        ]);
                        $subscription->update(['status' => 'active']);
                    }
                }
            }

            LoginAttempt::record($user->phone, $request->ip(), $request->userAgent(), true);
            LoginAttempt::clearOldAttempts();

            ActivityLog::create([
                'user_id' => $user->id,
                'model' => 'Auth',
                'action' => 'login_success',
                'description' => 'User logged in successfully',
                'ip_address' => $request->ip(),
                'device' => $deviceString,
                'activity_time' => now(),
            ]);

            return redirect()->intended('/change-branch');
        }

        LoginAttempt::record($request->phone, $request->ip(), $request->userAgent(), false);

        ActivityLog::create([
            'user_id' => $user->id,
            'model' => 'Auth',
            'action' => 'login_failed',
            'description' => 'Login failed - wrong password',
            'ip_address' => $request->ip(),
            'device' => $deviceString,
            'activity_time' => now(),
        ]);

        if (LoginAttempt::isLockedOut($request->ip())) {
            $securityConfig = SystemSettingService::getSecurityConfig();
            $duration = $securityConfig['lockout_duration'] ?? 15;

            return back()->withErrors([
                'phone' => "Too many failed attempts. Account is locked for {$duration} minutes.",
            ])->withInput();
        }

        return back()->withErrors([
            'password' => 'Invalid password.',
        ])->withInput();
    }


    public function showForgotPasswordForm()
    {
        return view('auth.forgotPassword');
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'phone' => 'required',
        ]);

        // Find user by phone with flexible matching
        $user = find_user_by_phone($request->phone);

        if (!$user) {
            return back()->withErrors([
                'phone' => 'Phone number not found.',
            ])->withInput();
        }

        $verification_code = rand(100000, 999999);

        OtpCode::create([
            'phone' => $user->phone, // Use the normalized phone number
            'code' => $verification_code,
            'expires_at' => Carbon::now()->addMinutes(5)
        ]);

        // Send SMS
        $this->sendSmsVerification($user->phone, $verification_code);

        // Redirect to verification page
        session(['phone' => $user->phone]);
        return redirect()->route('verify-otp-password');
    }

    public function resendOtp($phone)
    {
        // Find user by phone with flexible matching
        $user = find_user_by_phone($phone);

        if (!$user) {
            return back()->withErrors([
                'phone' => 'Phone number not found.',
            ]);
        }

        // Optional: invalidate previous OTPs
        OtpCode::where('phone', $user->phone)->update(['is_used' => 1]);

        // Generate new OTP
        $otpCode = rand(100000, 999999);

        // Save OTP
        OtpCode::create([
            'phone' => $user->phone, // Use the normalized phone number
            'code' => $otpCode,
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $this->sendSmsVerification($user->phone, $otpCode);

        // Redirect to verification page
        session(['phone' => $user->phone]);
        return redirect()->route('verify-otp-password');
    }

    protected function sendSmsVerification($phone, $code)
    {
        $message = 'OTP Code is ' . $code;
        SmsHelper::send($phone, $message);
    }

    public function showVerificationForm(Request $request)
    {
        // Get phone number from session
        $phone = session('phone');

        // If phone not in session, redirect or show error
        if (!$phone) {
            return redirect()->route('forgotPassword')->with('error', 'Session expired. Please try again.');
        }

        // Pass to view
        return view('auth.verify-otp-password', compact('phone'));
    }

    public function verifyPasswordCode(Request $request)
    {
        $request->validate([
            'phone' => 'required',
            'code' => 'required',
        ]);

        // Find user by phone with flexible matching
        $user = find_user_by_phone($request->phone);

        if (!$user) {
            return back()->withErrors(['phone' => 'Phone number not found.']);
        }

        $otp = OtpCode::where('phone', $user->phone)
            ->where('code', $request->code)
            ->where('expires_at', '>', Carbon::now())
            ->where('is_used', 0)
            ->latest()
            ->first();

        if (!$otp) {
            return back()->withErrors(['code' => 'Invalid verification code.']);
        }

        $otp->update(['is_used' => 1]);

        session(['verified_phone' => $user->phone]);

        return redirect()->route('new-password-form')->with('success', 'Phone verified successfully!');
    }

    public function showNewPasswordForm()
    {
        $phone = session('verified_phone');

        if (!$phone) {
            return redirect()->route('forgot-password')->with('error', 'Session expired.');
        }

        return view('auth.reset-password', compact('phone'));
    }

    public function storeNewPassword(Request $request)
    {
        $request->validate([
            'phone' => 'required',
            'password' => ['required', 'confirmed', new PasswordValidation],
        ]);

        // Find user by phone with flexible matching
        $user = find_user_by_phone($request->phone);

        if (!$user) {
            return back()->withErrors(['phone' => 'User not found.']);
        }

        $user->password = bcrypt($request->password);
        $user->save();

        session()->forget('verified_phone');

        return redirect()->route('login')->with('success', 'Password reset successfully. You can now login.');
    }
}
