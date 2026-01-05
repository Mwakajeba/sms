@extends('layouts.main')

@section('title', 'SMS Settings')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'SMS Settings', 'url' => '#', 'icon' => 'bx bx-message-dots']
        ]" />
        <h6 class="mb-0 text-uppercase">SMS SETTINGS</h6>
        <hr />

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        @can('manage subscription')
                        <h4 class="card-title mb-4">SMS Configuration</h4>

                        @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bx bx-check-circle me-2"></i>
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        @endif

                        @if(session('error'))
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bx bx-error-circle me-2"></i>
                            {{ session('error') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        @endif

                        @if(isset($errors) && $errors->any())
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bx bx-error-circle me-2"></i>
                            Please fix the following errors:
                            <ul class="mb-0 mt-2">
                                @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        @endif

                        <form action="{{ route('settings.sms.update') }}" method="POST">
                            @csrf
                            @method('PUT')

                            <div class="row">
                                <!-- SMS Provider URL -->
                                <div class="col-md-12 mb-3">
                                    <label for="sms_url" class="form-label">SMS API URL</label>
                                    <input type="url" class="form-control" id="sms_url" name="sms_url" 
                                        value="{{ old('sms_url', env('BEEM_SMS_URL', env('SMS_URL', 'https://apisms.beem.africa/v1/send'))) }}" 
                                        placeholder="https://apisms.beem.africa/v1/send" required>
                                    <small class="form-text text-muted">The API endpoint URL for sending SMS messages.</small>
                                </div>

                                <!-- Sender ID -->
                                <div class="col-md-6 mb-3">
                                    <label for="sms_senderid" class="form-label">Sender ID</label>
                                    <input type="text" class="form-control" id="sms_senderid" name="sms_senderid" 
                                        value="{{ old('sms_senderid', env('BEEM_SENDER_ID', env('SMS_SENDERID', ''))) }}" 
                                        placeholder="YourSenderID" required>
                                    <small class="form-text text-muted">The sender ID that will appear on SMS messages.</small>
                                </div>

                                <!-- API Key -->
                                <div class="col-md-6 mb-3">
                                    <label for="sms_key" class="form-label">API Key</label>
                                    <input type="text" class="form-control" id="sms_key" name="sms_key" 
                                        value="{{ old('sms_key', env('BEEM_API_KEY', env('SMS_KEY', ''))) }}" 
                                        placeholder="Your API Key" required>
                                    <small class="form-text text-muted">Your SMS provider API key.</small>
                                </div>

                                <!-- Secret Key / Token -->
                                <div class="col-md-6 mb-3">
                                    <label for="sms_token" class="form-label">Secret Key / Token</label>
                                    <input type="password" class="form-control" id="sms_token" name="sms_token" 
                                        value="{{ old('sms_token', env('BEEM_SECRET_KEY', env('SMS_TOKEN', ''))) }}" 
                                        placeholder="Your Secret Key" required>
                                    <small class="form-text text-muted">Your SMS provider secret key or token.</small>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="show_token" onchange="toggleTokenVisibility()">
                                        <label class="form-check-label" for="show_token">
                                            Show token
                                        </label>
                                    </div>
                                </div>

                                <!-- Test Phone Number (Optional) -->
                                <div class="col-md-6 mb-3">
                                    <label for="test_phone" class="form-label">Test Phone Number (Optional)</label>
                                    <input type="text" class="form-control" id="test_phone" name="test_phone" 
                                        value="{{ old('test_phone', '') }}" 
                                        placeholder="+255123456789">
                                    <small class="form-text text-muted">Optional: Enter a phone number to test SMS sending.</small>
                                </div>
                            </div>

                            <div class="alert alert-info mt-3" role="alert">
                                <i class="bx bx-info-circle me-2"></i>
                                <strong>Note:</strong> These settings will be saved to your <code>.env</code> file. 
                                Make sure to backup your <code>.env</code> file before making changes.
                            </div>

                            <div class="row mt-4">
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bx bx-save me-1"></i> Save SMS Settings
                                    </button>
                                    <button type="button" class="btn btn-info" id="testSmsBtn" onclick="testSms()">
                                        <i class="bx bx-message-check me-1"></i> Test SMS
                                    </button>
                                    <a href="{{ route('settings.index') }}" class="btn btn-secondary">
                                        <i class="bx bx-arrow-back me-1"></i> Back to Settings
                                    </a>
                                </div>
                            </div>
                        </form>
                        @else
                        <div class="alert alert-warning" role="alert">
                            <i class="bx bx-lock me-2"></i>
                            You don't have permission to manage SMS settings.
                        </div>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!--end page wrapper -->
<!--start overlay-->
<div class="overlay toggle-icon"></div>
<!--end overlay-->
<!--Start Back To Top Button--> <a href="javaScript:;" class="back-to-top"><i class='bx bxs-up-arrow-alt'></i></a>
<!--End Back To Top Button-->
<footer class="page-footer">
    <p class="mb-0">Copyright © {{ date('Y') }}. All right reserved. -- By SAFCO FINTECH</p>
</footer>

@endsection

@push('scripts')
<script>
    function toggleTokenVisibility() {
        const tokenInput = document.getElementById('sms_token');
        const showTokenCheckbox = document.getElementById('show_token');
        
        if (showTokenCheckbox.checked) {
            tokenInput.type = 'text';
        } else {
            tokenInput.type = 'password';
        }
    }

    function testSms() {
        const testPhone = document.getElementById('test_phone').value;
        const smsUrl = document.getElementById('sms_url').value;
        const smsSenderid = document.getElementById('sms_senderid').value;
        const smsKey = document.getElementById('sms_key').value;
        const smsToken = document.getElementById('sms_token').value;

        if (!testPhone) {
            alert('Please enter a test phone number first.');
            document.getElementById('test_phone').focus();
            return;
        }

        if (!smsUrl || !smsSenderid || !smsKey || !smsToken) {
            alert('Please fill in all SMS settings before testing.');
            return;
        }

        // Disable button and show loading
        const btn = document.getElementById('testSmsBtn');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i> Testing...';

        // Send test request with current form values
        fetch('{{ route("settings.sms.test") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                test_phone: testPhone,
                sms_url: smsUrl,
                sms_senderid: smsSenderid,
                sms_key: smsKey,
                sms_token: smsToken
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('✓ ' + data.message);
            } else {
                alert('✗ ' + (data.message || 'Test SMS failed'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('✗ An error occurred while testing SMS: ' + error.message);
        })
        .finally(() => {
            // Re-enable button
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    }
</script>
@endpush

