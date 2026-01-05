@extends('layouts.main')

@section('title', 'Subscription Dashboard')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Subscriptions', 'url' => route('subscriptions.index'), 'icon' => 'bx bx-calendar-check'],
            ['label' => 'Dashboard', 'url' => '#', 'icon' => 'bx bx-bar-chart']
        ]" />

        <div class="row">
            <div class="col-12">
                <div class="card border-top border-0 border-4 border-primary">
                    <div class="card-body p-4">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="card-title d-flex align-items-center">
                                    <div><i class="bx bx-calendar-check me-1 font-22 text-primary"></i></div>
                                    <h5 class="mb-0 text-primary">Subscription Dashboard</h5>
                                </div>
                                <p class="mb-0 text-muted">Overview of all subscription statuses and expiring subscriptions</p>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="d-flex gap-2 justify-content-end">
                                    <a href="{{ route('subscriptions.create') }}" class="btn btn-sm btn-primary">
                                        <i class="bx bx-plus"></i> Create Subscription
                                    </a>
                                    <a href="{{ route('subscriptions.index') }}" class="btn btn-sm btn-secondary">
                                        <i class="bx bx-list-ul"></i> View All
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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

        <!-- Statistics Cards -->
        <div class="row row-cols-1 row-cols-lg-5 g-3 mb-4">
            <div class="col">
                <div class="card radius-10">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="mb-0 text-muted">Total Subscriptions</p>
                                <h4 class="font-weight-bold text-dark">{{ $stats['total_subscriptions'] }}</h4>
                            </div>
                            <div class="widgets-icons bg-gradient-cosmic text-white">
                                <i class='bx bx-calendar'></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col">
                <div class="card radius-10">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="mb-0 text-muted">Active</p>
                                <h4 class="font-weight-bold text-success">{{ $stats['active_subscriptions'] }}</h4>
                            </div>
                            <div class="widgets-icons bg-gradient-lush text-white">
                                <i class='bx bx-check-circle'></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col">
                <div class="card radius-10">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="mb-0 text-muted">Expiring Soon</p>
                                <h4 class="font-weight-bold text-warning">{{ $stats['expiring_soon'] }}</h4>
                            </div>
                            <div class="widgets-icons bg-gradient-orange text-white">
                                <i class='bx bx-time-five'></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col">
                <div class="card radius-10">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="mb-0 text-muted">Expired</p>
                                <h4 class="font-weight-bold text-danger">{{ $stats['expired'] }}</h4>
                            </div>
                            <div class="widgets-icons bg-gradient-bloody text-white">
                                <i class='bx bx-x-circle'></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col">
                <div class="card radius-10">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="mb-0 text-muted">Pending Payments</p>
                                <h4 class="font-weight-bold text-info">{{ $stats['pending_payments'] }}</h4>
                            </div>
                            <div class="widgets-icons bg-gradient-moonlit text-white">
                                <i class='bx bx-money'></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Expiring Subscriptions -->
            <div class="col-12 col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header bg-warning text-white">
                        <h5 class="mb-0">
                            <i class="bx bx-time-five me-2"></i>
                            Expiring Soon (Next 5 Days)
                        </h5>
                    </div>
                    <div class="card-body">
                        @if($expiring_subscriptions->count() > 0)
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Company</th>
                                            <th>Plan</th>
                                            <th>End Date</th>
                                            <th>Days Left</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($expiring_subscriptions as $subscription)
                                            <tr>
                                                <td>{{ $subscription->company->name ?? 'N/A' }}</td>
                                                <td>
                                                    <strong>{{ $subscription->plan_name }}</strong>
                                                    <br>
                                                    <small class="text-muted">{{ $subscription->billing_cycle }}</small>
                                                </td>
                                                <td>{{ $subscription->end_date->format('M d, Y') }}</td>
                                                <td>
                                                    @php
                                                        $timeRemaining = $subscription->getFormattedTimeRemaining();
                                                    @endphp
                                                    <span class="badge bg-{{ $timeRemaining['status'] === 'expired' ? 'danger' : ($timeRemaining['status'] === 'warning' ? 'warning' : ($timeRemaining['status'] === 'danger' ? 'danger' : 'success')) }}">
                                                        {{ $timeRemaining['formatted'] }}
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="{{ route('subscriptions.show', $subscription) }}" class="btn btn-sm btn-primary">
                                                        <i class="bx bx-show"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="text-center py-4">
                                <i class="bx bx-check-circle text-success" style="font-size: 3rem;"></i>
                                <p class="text-muted mt-2">No subscriptions expiring soon</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Recent Subscriptions -->
            <div class="col-12 col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="bx bx-list-ul me-2"></i>
                            Recent Subscriptions
                        </h5>
                    </div>
                    <div class="card-body">
                        @if($recent_subscriptions->count() > 0)
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Company</th>
                                            <th>Plan</th>
                                            <th>Status</th>
                                            <th>Payment</th>
                                            <th>Created</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($recent_subscriptions as $subscription)
                                            <tr>
                                                <td>{{ $subscription->company->name ?? 'N/A' }}</td>
                                                <td>
                                                    <strong>{{ $subscription->plan_name }}</strong>
                                                    <br>
                                                    <small class="text-muted">{{ $subscription->billing_cycle }}</small>
                                                </td>
                                                <td>
                                                    <span class="badge {{ $subscription->getStatusBadgeClass() }}">
                                                        {{ ucfirst($subscription->status) }}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge {{ $subscription->getPaymentStatusBadgeClass() }}">
                                                        {{ ucfirst($subscription->payment_status) }}
                                                    </span>
                                                </td>
                                                <td>{{ $subscription->created_at->format('M d, Y') }}</td>
                                                <td>
                                                    <a href="{{ route('subscriptions.show', $subscription) }}" class="btn btn-sm btn-primary">
                                                        <i class="bx bx-show"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="text-center py-4">
                                <i class="bx bx-info-circle text-info" style="font-size: 3rem;"></i>
                                <p class="text-muted mt-2">No subscriptions found</p>
                                <a href="{{ route('subscriptions.create') }}" class="btn btn-primary mt-2">
                                    <i class="bx bx-plus"></i> Create First Subscription
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

