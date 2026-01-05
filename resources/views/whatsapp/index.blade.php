@extends('layouts.main')

@section('title', 'WhatsApp')

@section('content')
    <div class="page-wrapper">
        <div class="page-content">
            <x-breadcrumbs-with-icons :links="[
                ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
                ['label' => 'WhatsApp', 'url' => '#', 'icon' => 'bx bxl-whatsapp']
            ]" />
            <h6 class="mb-0 text-uppercase">WHATSAPP</h6>
            <hr />

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title mb-4">WhatsApp Menu</h4>

                            @if(session('success'))
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="bx bx-check-circle me-2"></i>
                                    {{ session('success') }}
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            @endif

                            <div class="row">
                                <!-- Phone Book Card -->
                                <div class="col-md-6 col-lg-4 mb-4">
                                    <div class="card border-primary">
                                        <div class="card-body text-center">
                                            <div class="mb-3">
                                                <i class="bx bx-book fs-1 text-primary"></i>
                                            </div>
                                            <h5 class="card-title">Phone Book</h5>
                                            <p class="card-text">Manage and view your contacts phone book.</p>
                                            <a href="{{ route('whatsapp.phone-book') }}" class="btn btn-primary">
                                                <i class="bx bx-book me-1"></i> Open Phone Book
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <!-- Send Message Card -->
                                <div class="col-md-6 col-lg-4 mb-4">
                                    <div class="card border-success">
                                        <div class="card-body text-center">
                                            <div class="mb-3">
                                                <i class="bx bx-message fs-1 text-success"></i>
                                            </div>
                                            <h5 class="card-title">Send Message</h5>
                                            <p class="card-text">Send WhatsApp messages to your contacts.</p>
                                            <a href="{{ route('whatsapp.send-message') }}" class="btn btn-success">
                                                <i class="bx bx-message me-1"></i> Send Message
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <!-- Report Card -->
                                <div class="col-md-6 col-lg-4 mb-4">
                                    <div class="card border-info">
                                        <div class="card-body text-center">
                                            <div class="mb-3">
                                                <i class="bx bx-file fs-1 text-info"></i>
                                            </div>
                                            <h5 class="card-title">Report</h5>
                                            <p class="card-text">View WhatsApp usage and activity reports.</p>
                                            <a href="{{ route('whatsapp.report') }}" class="btn btn-info">
                                                <i class="bx bx-file me-1"></i> View Report
                                            </a>
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
@endsection

