@extends('layouts.main')

@section('title', 'Subscription Details')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Subscriptions', 'url' => route('subscriptions.index'), 'icon' => 'bx bx-calendar-check'],
            ['label' => 'Subscription Details', 'url' => '#', 'icon' => 'bx bx-show']
        ]" />

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bx bx-check-circle me-2"></i>
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bx bx-x-circle me-2"></i>
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <!-- Header Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-top border-0 border-4 border-primary shadow-sm">
                    <div class="card-body p-4">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="d-flex align-items-center">
                                    <div class="widgets-icons bg-gradient-cosmic text-white rounded-circle p-3 me-3">
                                        <i class="bx bx-calendar-check fs-4"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-1 text-primary fw-bold">Subscription Details</h4>
                                        <p class="mb-0 text-muted">{{ $subscription->plan_name }}</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="d-flex gap-2 justify-content-end">
                                    <a href="{{ route('subscriptions.edit', $subscription) }}" class="btn btn-primary">
                                        <i class="bx bx-edit me-1"></i> Edit
                                    </a>
                                    <a href="{{ route('subscriptions.index') }}" class="btn btn-outline-secondary">
                                        <i class="bx bx-arrow-back me-1"></i> Back
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Main Details -->
            <div class="col-lg-8">
                <!-- Subscription Status Card -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm border-0">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            @php
                                                $statusConfig = [
                                                    'active' => ['icon' => 'bx-check-circle', 'color' => 'success', 'bg' => 'bg-success bg-opacity-10'],
                                                    'expired' => ['icon' => 'bx-x-circle', 'color' => 'danger', 'bg' => 'bg-danger bg-opacity-10'],
                                                    'pending' => ['icon' => 'bx-time-five', 'color' => 'info', 'bg' => 'bg-info bg-opacity-10'],
                                                    'cancelled' => ['icon' => 'bx-x', 'color' => 'warning', 'bg' => 'bg-warning bg-opacity-10'],
                                                    'inactive' => ['icon' => 'bx-pause-circle', 'color' => 'secondary', 'bg' => 'bg-secondary bg-opacity-10'],
                                                ];
                                                $currentStatus = $statusConfig[$subscription->status] ?? $statusConfig['pending'];
                                            @endphp
                                            <div class="avatar-lg {{ $currentStatus['bg'] }} rounded-circle d-flex align-items-center justify-content-center">
                                                <i class="bx {{ $currentStatus['icon'] }} text-{{ $currentStatus['color'] }} fs-3"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h6 class="text-muted mb-1">Subscription Status</h6>
                                            <h4 class="mb-0 text-{{ $currentStatus['color'] }}">
                                                {{ ucfirst($subscription->status) }}
                                            </h4>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-{{ $currentStatus['color'] }} fs-6 px-4 py-2">
                                            <i class="bx {{ $currentStatus['icon'] }} me-1"></i>
                                            {{ strtoupper($subscription->status) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Subscription Information -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="bx bx-info-circle text-primary me-2"></i>
                            Subscription Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="text-muted small">Company</label>
                                <h6 class="mb-0">
                                    <i class="bx bx-building me-1 text-primary"></i>
                                    {{ $subscription->company->name ?? 'N/A' }}
                                </h6>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small">Plan Name</label>
                                <h6 class="mb-0">
                                    <i class="bx bx-package me-1 text-primary"></i>
                                    {{ $subscription->plan_name }}
                                </h6>
                            </div>
                        </div>

                        @if($subscription->plan_description)
                        <div class="row mb-3">
                            <div class="col-12">
                                <label class="text-muted small">Description</label>
                                <p class="mb-0">{{ $subscription->plan_description }}</p>
                            </div>
                        </div>
                        @endif

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="text-muted small">Start Date</label>
                                <h6 class="mb-0">
                                    <i class="bx bx-calendar me-1 text-primary"></i>
                                    {{ $subscription->start_date->format('M d, Y') }}
                                </h6>
                            </div>
                            <div class="col-md-4">
                                <label class="text-muted small">End Date</label>
                                <h6 class="mb-0">
                                    <i class="bx bx-calendar-check me-1 text-primary"></i>
                                    {{ $subscription->end_date->format('M d, Y') }}
                                </h6>
                            </div>
                            <div class="col-md-4">
                                <label class="text-muted small">Time Until Expiry</label>
                                @php
                                    $timeRemaining = $subscription->getFormattedTimeRemaining();
                                    $daysUntilExpiry = $subscription->daysUntilExpiry();
                                @endphp
                                <h6 class="mb-0">
                                    <i class="bx bx-time me-1 text-primary"></i>
                                    @if($timeRemaining['status'] === 'expired')
                                        <span class="text-danger fw-bold">{{ $timeRemaining['formatted'] }}</span>
                                    @elseif($timeRemaining['status'] === 'danger')
                                        <span class="text-danger fw-bold">{{ $timeRemaining['formatted'] }}</span>
                                    @elseif($timeRemaining['status'] === 'warning')
                                        <span class="text-warning fw-bold">{{ $timeRemaining['formatted'] }}</span>
                                    @else
                                        <span class="text-success fw-bold">{{ $timeRemaining['formatted'] }}</span>
                                    @endif
                                </h6>
                            </div>
                        </div>

                        @if($subscription->features && isset($subscription->features['notification_days']))
                        <div class="row">
                            <div class="col-md-6">
                                <label class="text-muted small">Notification Days</label>
                                <h6 class="mb-0">
                                    <i class="bx bx-bell me-1 text-primary"></i>
                                    {{ $subscription->features['notification_days'] }} days before expiry
                                </h6>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>

            </div>

            <!-- Sidebar Actions -->
            <div class="col-lg-4">
                <!-- Quick Actions -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0">
                            <i class="bx bx-cog me-2"></i>
                            Quick Actions
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="{{ route('subscriptions.edit', $subscription) }}" class="btn btn-warning">
                                <i class="bx bx-edit me-2"></i> Edit Subscription
                            </a>

                            @if($subscription->status !== 'cancelled')
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                                <i class="bx bx-x-circle me-2"></i> Cancel Subscription
                            </button>
                            @endif

                            @if($subscription->status === 'expired')
                            <form action="{{ route('subscriptions.renew', $subscription) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bx bx-refresh me-2"></i> Renew Subscription
                                </button>
                            </form>
                            @endif

                            @if($subscription->status === 'active')
                            <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#extendModal">
                                <i class="bx bx-calendar-plus me-2"></i> Extend Subscription
                            </button>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Subscription Timeline -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">
                            <i class="bx bx-time me-2"></i>
                            Timeline
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="timeline">
                            <div class="timeline-item mb-3">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <div class="avatar-sm bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center">
                                            <i class="bx bx-calendar text-primary"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h6 class="mb-1">Created</h6>
                                        <p class="text-muted small mb-0">{{ $subscription->created_at->format('M d, Y \a\t h:i A') }}</p>
                                    </div>
                                </div>
                            </div>
                            <div class="timeline-item mb-3">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <div class="avatar-sm bg-success bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center">
                                            <i class="bx bx-check-circle text-success"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h6 class="mb-1">Start Date</h6>
                                        <p class="text-muted small mb-0">{{ $subscription->start_date->format('M d, Y') }}</p>
                                    </div>
                                </div>
                            </div>
                            <div class="timeline-item">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        @php
                                            $timelineColor = $daysUntilExpiry < 0 ? 'danger' : ($daysUntilExpiry <= 7 ? 'warning' : 'success');
                                        @endphp
                                        <div class="avatar-sm bg-{{ $timelineColor }} bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center">
                                            <i class="bx bx-calendar-check text-{{ $timelineColor }}"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h6 class="mb-1">End Date</h6>
                                        <p class="text-muted small mb-0">{{ $subscription->end_date->format('M d, Y') }}</p>
                                        @php
                                            $timeRemaining = $subscription->getFormattedTimeRemaining();
                                        @endphp
                                        <span class="badge bg-{{ $timeRemaining['status'] === 'expired' ? 'danger' : ($timeRemaining['status'] === 'warning' ? 'warning' : ($timeRemaining['status'] === 'danger' ? 'danger' : 'success')) }} mt-1">
                                            {{ $timeRemaining['formatted'] }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cancelModalLabel">Cancel Subscription</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('subscriptions.cancel', $subscription) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <p>Are you sure you want to cancel this subscription?</p>
                    <p class="text-muted small">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No, Keep It</button>
                    <button type="submit" class="btn btn-danger">Yes, Cancel Subscription</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Extend Modal -->
<div class="modal fade" id="extendModal" tabindex="-1" aria-labelledby="extendModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="extendModalLabel">Extend Subscription</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('subscriptions.extend', $subscription) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="extend_days" class="form-label">Number of Days to Extend</label>
                        <input type="number" class="form-control" id="extend_days" name="days" 
                               min="1" max="365" value="30" required>
                        <div class="form-text">Enter the number of days to add to the current end date (1-365 days)</div>
                    </div>
                    <div class="alert alert-info">
                        <i class="bx bx-info-circle me-2"></i>
                        Current end date: <strong>{{ $subscription->end_date->format('M d, Y') }}</strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Extend Subscription</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .avatar-sm {
        width: 40px;
        height: 40px;
    }
    .timeline-item {
        position: relative;
    }
    .timeline-item:not(:last-child)::after {
        content: '';
        position: absolute;
        left: 19px;
        top: 50px;
        width: 2px;
        height: calc(100% - 20px);
        background: #e9ecef;
    }
</style>
@endsection

