@extends('layouts.main')

@section('title', 'Edit Subscription')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Subscriptions', 'url' => route('subscriptions.index'), 'icon' => 'bx bx-calendar-check'],
            ['label' => 'Edit Subscription', 'url' => '#', 'icon' => 'bx bx-edit']
        ]" />

        <!-- Header Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-top border-0 border-4 border-warning shadow-sm">
                    <div class="card-body p-4">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="d-flex align-items-center">
                                    <div class="widgets-icons bg-gradient-orange text-white rounded-circle p-3 me-3">
                                        <i class="bx bx-edit fs-4"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-1 text-warning fw-bold">Edit Subscription</h4>
                                        <p class="mb-0 text-muted">Update subscription details and expiration date</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <a href="{{ route('subscriptions.show', $subscription) }}" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Details
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bx bx-error-circle fs-4 me-2"></i>
                    <div class="flex-grow-1">
                        <strong>Please fix the following errors:</strong>
                        <ul class="mb-0 mt-2">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <form action="{{ route('subscriptions.update', $subscription) }}" method="POST" id="subscriptionForm">
            @csrf
            @method('PUT')

            <div class="row">
                <!-- Main Form Section -->
                <div class="col-lg-8">
                    <div class="card shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="bx bx-info-circle text-primary me-2"></i>
                                Subscription Details
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- Company Selection -->
                            <div class="mb-4">
                                <label for="company_id" class="form-label fw-bold">
                                    <i class="bx bx-building me-1 text-primary"></i>
                                    Company <span class="text-danger">*</span>
                                </label>
                                <select class="form-select form-select-lg select2-single" id="company_id" name="company_id" required>
                                    <option value="">Select a company...</option>
                                    @foreach($companies as $company)
                                        <option value="{{ $company->id }}" {{ old('company_id', $subscription->company_id) == $company->id ? 'selected' : '' }}>
                                            {{ $company->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">
                                    <i class="bx bx-info-circle me-1"></i>
                                    Select the company for this subscription
                                </div>
                                @error('company_id')
                                    <div class="text-danger small mt-1">
                                        <i class="bx bx-error-circle me-1"></i>{{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <!-- End Date -->
                            <div class="mb-4">
                                <label for="end_date" class="form-label fw-bold">
                                    <i class="bx bx-calendar me-1 text-primary"></i>
                                    Subscription End Date <span class="text-danger">*</span>
                                </label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text bg-light">
                                        <i class="bx bx-calendar"></i>
                                    </span>
                                    <input type="date" class="form-control" id="end_date" name="end_date" 
                                           value="{{ old('end_date', $subscription->end_date->format('Y-m-d')) }}" required>
                                </div>
                                <div class="form-text">
                                    <i class="bx bx-info-circle me-1"></i>
                                    The date when this subscription will expire
                                </div>
                                @error('end_date')
                                    <div class="text-danger small mt-1">
                                        <i class="bx bx-error-circle me-1"></i>{{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <!-- Notification Days -->
                            <div class="mb-4">
                                <label for="notification_days" class="form-label fw-bold">
                                    <i class="bx bx-bell me-1 text-primary"></i>
                                    Notification Days <span class="text-danger">*</span>
                                </label>
                                <div class="row g-2">
                                    <div class="col-md-5">
                                        @php
                                            $currentNotificationDays = $subscription->features['notification_days'] ?? \App\Services\SystemSettingService::get('subscription_notification_days_30', 30);
                                        @endphp
                                        <select class="form-select" id="notification_days_select" onchange="updateNotificationDays()">
                                            <option value="">Quick Select</option>
                                            <option value="{{ \App\Services\SystemSettingService::get('subscription_notification_days_30', 30) }}" {{ $currentNotificationDays == \App\Services\SystemSettingService::get('subscription_notification_days_30', 30) ? 'selected' : '' }}>
                                                {{ \App\Services\SystemSettingService::get('subscription_notification_days_30', 30) }} days (First Notification)
                                            </option>
                                            <option value="{{ \App\Services\SystemSettingService::get('subscription_notification_days_20', 20) }}" {{ $currentNotificationDays == \App\Services\SystemSettingService::get('subscription_notification_days_20', 20) ? 'selected' : '' }}>
                                                {{ \App\Services\SystemSettingService::get('subscription_notification_days_20', 20) }} days (Second Notification)
                                            </option>
                                            <option value="custom">Custom Value</option>
                                        </select>
                                    </div>
                                    <div class="col-md-7">
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">
                                                <i class="bx bx-time"></i>
                                            </span>
                                            <input type="number" class="form-control" id="notification_days" name="notification_days" 
                                                   value="{{ old('notification_days', $currentNotificationDays) }}" 
                                                   min="1" max="365" required placeholder="Enter number of days">
                                            <span class="input-group-text bg-light">days</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-text mt-2">
                                    <i class="bx bx-info-circle me-1"></i>
                                    Number of days before expiry to show notification to users (1-365 days)
                                </div>
                                @error('notification_days')
                                    <div class="text-danger small mt-1">
                                        <i class="bx bx-error-circle me-1"></i>{{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar Information -->
                <div class="col-lg-4">
                    <!-- Current Status Card -->
                    <div class="card shadow-sm border-primary mb-4">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0">
                                <i class="bx bx-info-circle me-2"></i>
                                Current Status
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="text-muted small">Status</label>
                                <div>
                                    @php
                                        $statusColors = [
                                            'active' => 'success',
                                            'expired' => 'danger',
                                            'pending' => 'info',
                                            'cancelled' => 'warning',
                                            'inactive' => 'secondary'
                                        ];
                                        $statusColor = $statusColors[$subscription->status] ?? 'secondary';
                                    @endphp
                                    <span class="badge bg-{{ $statusColor }} fs-6">
                                        {{ ucfirst($subscription->status) }}
                                    </span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small">End Date</label>
                                <div>
                                    <strong>{{ $subscription->end_date->format('M d, Y') }}</strong>
                                </div>
                            </div>
                            @php
                                $timeRemaining = $subscription->getFormattedTimeRemaining();
                            @endphp
                            <div>
                                <label class="text-muted small">Time Remaining</label>
                                <div>
                                    <span class="badge bg-{{ $timeRemaining['status'] === 'expired' ? 'danger' : ($timeRemaining['status'] === 'warning' ? 'warning' : ($timeRemaining['status'] === 'danger' ? 'danger' : 'success')) }}">
                                        {{ $timeRemaining['formatted'] }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">
                                <i class="bx bx-cog me-2"></i>
                                Quick Actions
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-warning btn-lg">
                                    <i class="bx bx-save me-2"></i>
                                    Update Subscription
                                </button>
                                <a href="{{ route('subscriptions.show', $subscription) }}" class="btn btn-outline-secondary">
                                    <i class="bx bx-x me-2"></i>
                                    Cancel
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
    .avatar-sm {
        width: 40px;
        height: 40px;
    }
    .form-select-lg {
        padding: 0.75rem 1rem;
        font-size: 1rem;
    }
    .card {
        border: none;
        border-radius: 10px;
    }
    .card-header {
        border-radius: 10px 10px 0 0 !important;
        border-bottom: 1px solid #e9ecef;
    }
    .input-group-lg .form-control,
    .input-group-lg .input-group-text {
        padding: 0.75rem 1rem;
    }
</style>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Select2 for company dropdown
        if (typeof $ !== 'undefined' && $.fn.select2) {
            $('#company_id').select2({
                theme: 'bootstrap-5',
                placeholder: 'Select a company...',
                allowClear: true,
                width: '100%'
            });
        }
    });

    function updateNotificationDays() {
        const select = document.getElementById('notification_days_select');
        const input = document.getElementById('notification_days');
        
        if (select.value === 'custom') {
            input.value = '';
            input.focus();
            input.readOnly = false;
            input.classList.add('border-warning');
        } else if (select.value) {
            input.value = select.value;
            input.readOnly = true;
            input.classList.remove('border-warning');
            input.classList.add('border-success');
        } else {
            input.readOnly = false;
            input.classList.remove('border-warning', 'border-success');
        }
    }

    // Form validation
    document.getElementById('subscriptionForm').addEventListener('submit', function(e) {
        const companyId = document.getElementById('company_id').value;
        const endDate = document.getElementById('end_date').value;
        const notificationDays = document.getElementById('notification_days').value;

        if (!companyId || !endDate || !notificationDays) {
            e.preventDefault();
            alert('Please fill in all required fields');
            return false;
        }
    });
</script>
@endpush

