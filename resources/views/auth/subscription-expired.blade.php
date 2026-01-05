@extends('layouts.auth')

@section('title', \App\Services\SystemSettingService::get('app_name', 'SmartAccounting') . ' – Subscription Expired')

@section('content')
    <div class="authentication-header"></div>
    <div class="section-authentication-signin d-flex align-items-center justify-content-center my-5 my-lg-0">
        <div class="container">
            <div class="row row-cols-1 row-cols-lg-2 row-cols-xl-3">
                <div class="col mx-auto">
                    <div class="mb-4 text-center">
                        <img src="{{ asset('assets/images/logo1.png') }}" width="180" alt="" />
                    </div>
                    
                    <!-- Language Switcher for Auth Pages -->
                    <div class="text-center mb-3">
                        @include('incs.languageSwitcher')
                    </div>
                    <div class="card rounded-4">
                        <div class="card-body">
                            <div class="p-4 rounded">
                                <div class="text-center mb-4">
                                    <img src="{{ asset('assets/images/icons/lock.png') }}" width="150" alt="Subscription Expired" />
                                </div>
                                
                                <div class="text-center mb-4">
                                    <h3 class="text-danger fw-bold">{{ __('Subscription Expired') }}</h3>
                                    <p class="text-muted mb-0">{{ __('Your subscription has expired. Please contact your administrator to renew your subscription.') }}</p>
                                </div>

                                <div class="alert alert-danger" role="alert">
                                    <div class="d-flex align-items-center">
                                        <i class="bx bx-error-circle fs-4 me-2"></i>
                                        <div>
                                            <strong>{{ __('Access Restricted') }}</strong>
                                            <p class="mb-0 mt-1">{{ __('You cannot access the system until your subscription is renewed.') }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="card border-primary border-2 mb-3 shadow-sm">
                                    <div class="card-header bg-primary text-white text-center py-3">
                                        <h6 class="mb-0 fw-bold">
                                            <i class="bx bx-credit-card me-2"></i>
                                            Payment Information
                                        </h6>
                                    </div>
                                    <div class="card-body p-4">
                                        <div class="text-center mb-3">
                                            <div class="mb-3">
                                                <label class="text-muted small d-block mb-1">Bank Name</label>
                                                <h5 class="mb-0 fw-bold text-primary">
                                                    <i class="bx bx-building me-2"></i>
                                                    CRDB Bank
                                                </h5>
                                            </div>
                                            <hr class="my-3">
                                            <div class="mb-3">
                                                <label class="text-muted small d-block mb-1">Account Name</label>
                                                <h6 class="mb-0 fw-bold">
                                                    <i class="bx bx-user me-2 text-primary"></i>
                                                    STEP AHEAD FINANCIAL CONS
                                                </h6>
                                            </div>
                                            <hr class="my-3">
                                            <div>
                                                <label class="text-muted small d-block mb-1">Account Number</label>
                                                <h4 class="mb-0 fw-bold text-success">
                                                    <i class="bx bx-hash me-2"></i>
                                                    015C448187900
                                                </h4>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card bg-primary text-white border-0 mb-3">
                                    <div class="card-body text-center">
                                        <h6 class="card-title mb-2">
                                            <i class="bx bx-phone me-2"></i>
                                            {{ __('Contact Support') }}
                                        </h6>
                                        <p class="mb-0 fs-5">
                                            <a href="tel:+255747762244" class="text-white text-decoration-none fw-bold">
                                                <i class="bx bx-phone-call me-2"></i>
                                                +255 747 762 244
                                            </a>
                                        </p>
                                        <small class="opacity-75">Call us for immediate assistance</small>
                                    </div>
                                </div>

                                <div class="text-center">
                                    <a href="{{ route('login') }}" class="btn btn-primary">
                                        <i class="bx bx-arrow-back me-1"></i>
                                        {{ __('Back to Login') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

