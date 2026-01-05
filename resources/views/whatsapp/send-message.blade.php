@extends('layouts.main')

@section('title', 'WhatsApp Send Message')

@section('content')
    <div class="page-wrapper">
        <div class="page-content">
            <x-breadcrumbs-with-icons :links="[
                ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
                ['label' => 'WhatsApp', 'url' => route('whatsapp.index'), 'icon' => 'bx bxl-whatsapp'],
                ['label' => 'Send Message', 'url' => '#', 'icon' => 'bx bx-message']
            ]" />
            <h6 class="mb-0 text-uppercase">WHATSAPP SEND MESSAGE</h6>
            <hr />

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title mb-4">Send WhatsApp Message to All Contacts</h4>

                            <!-- Info Alert -->
                            <div class="alert alert-info mb-4">
                                <i class="bx bx-info-circle me-2"></i>
                                <strong>Note:</strong> This message will be sent to all <strong>{{ $phoneBookCount }}</strong> contact(s) in your phone book. Messages will be processed in the background.
                            </div>

                            <form id="sendMessageForm" method="POST" action="{{ route('whatsapp.send-message.store') }}" enctype="multipart/form-data" onsubmit="return false;">
                                @csrf
                                
                                <!-- Message Text -->
                                <div class="mb-3">
                                    <label for="message" class="form-label">Message Text</label>
                                    <textarea class="form-control" id="message" name="message" rows="5" 
                                              placeholder="Enter your message here..."></textarea>
                                    <small class="text-muted">Leave empty if sending media only</small>
                                    <div class="mt-1">
                                        <small class="text-muted">
                                            Character count: <span id="charCount">0</span>/5000
                                        </small>
                                    </div>
                                </div>

                                <!-- Media Upload -->
                                <div class="mb-3">
                                    <label for="media" class="form-label">Media File (Optional)</label>
                                    <input type="file" class="form-control" id="media" name="media" 
                                           accept="image/*,video/*,audio/*,.pdf,.doc,.docx">
                                    <small class="text-muted">
                                        Supported formats: Images (JPG, PNG, GIF), Videos (MP4, AVI, MOV), 
                                        Audio (MP3, WAV), Documents (PDF, DOC, DOCX). Max size: 25MB
                                    </small>
                                    <div id="mediaPreview" class="mt-2"></div>
                                </div>

                                <!-- Submit Button -->
                                <div class="d-flex justify-content-between align-items-center">
                                    <button type="button" class="btn btn-secondary" id="clearFormBtn">
                                        <i class="bx bx-refresh me-1"></i> Clear
                                    </button>
                                    <button type="button" class="btn btn-success" id="sendBtn">
                                        <i class="bx bx-send me-1"></i> Send to All Contacts
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
<style>
    #mediaPreview img, #mediaPreview video {
        max-width: 100%;
        max-height: 300px;
        border-radius: 8px;
        margin-top: 10px;
    }
    .media-preview-item {
        position: relative;
        display: inline-block;
        margin-right: 10px;
        margin-top: 10px;
    }
    .media-preview-item .remove-media {
        position: absolute;
        top: -5px;
        right: -5px;
        background: #dc3545;
        color: white;
        border: none;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }
</style>
@endpush

@push('scripts')
<script>
    $(document).ready(function() {
        console.log('WhatsApp send message page loaded');

        // Character count for message
        function updateCharCount() {
            var length = $('#message').val().length;
            $('#charCount').text(length);
            if (length > 5000) {
                $('#charCount').parent().addClass('text-danger');
            } else {
                $('#charCount').parent().removeClass('text-danger');
            }
        }
        
        // Update on input
        $('#message').on('input keyup paste', function() {
            updateCharCount();
        });
        
        // Update on page load if there's existing text
        updateCharCount();

        // Helper function for media preview
        function previewMediaFile(input, previewSelector) {
            var file = input.files[0];
            var preview = $(previewSelector);
            preview.empty();

            if (file) {
                var reader = new FileReader();
                var fileType = file.type.split('/')[0];

                reader.onload = function(e) {
                    var html = '<div class="media-preview-item">';
                    
                    if (fileType === 'image') {
                        html += '<img src="' + e.target.result + '" alt="Preview" class="img-thumbnail">';
                    } else if (fileType === 'video') {
                        html += '<video src="' + e.target.result + '" controls class="img-thumbnail" style="max-width: 300px;"></video>';
                    } else {
                        html += '<div class="card">';
                        html += '<div class="card-body text-center">';
                        html += '<i class="bx bx-file font-size-48 text-primary"></i>';
                        html += '<p class="mb-0 mt-2"><strong>' + file.name + '</strong></p>';
                        html += '<small class="text-muted">' + (file.size / 1024 / 1024).toFixed(2) + ' MB</small>';
                        html += '</div></div>';
                    }
                    
                    html += '<button type="button" class="remove-media" onclick="removeMedia()">×</button>';
                    html += '</div>';
                    preview.html(html);
                };

                if (fileType === 'image' || fileType === 'video') {
                    reader.readAsDataURL(file);
                } else {
                    preview.html('<div class="card"><div class="card-body text-center"><i class="bx bx-file font-size-48 text-primary"></i><p class="mb-0 mt-2"><strong>' + file.name + '</strong></p><small class="text-muted">' + (file.size / 1024 / 1024).toFixed(2) + ' MB</small></div></div><button type="button" class="btn btn-sm btn-danger mt-2" onclick="removeMedia()">Remove</button>');
                }
            }
        }

        // Media preview
        $('#media').on('change', function() {
            previewMediaFile(this, '#mediaPreview');
        });

        // Remove media function
        window.removeMedia = function() {
            $('#media').val('');
            $('#mediaPreview').empty();
        };

        // Clear form
        $('#clearFormBtn').on('click', function() {
            $('#sendMessageForm')[0].reset();
            $('#mediaPreview').empty();
            $('#charCount').text('0');
        });

        // Handle button click - submit form via AJAX
        $('#sendBtn').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('Send button clicked');
            
            // Get form values
            var message = $('#message').val() ? $('#message').val().trim() : '';
            var media = $('#media')[0] ? $('#media')[0].files[0] : null;

            console.log('Form validation:', {
                hasMessage: !!message,
                hasMedia: !!media
            });

            // Validate message or media
            if (!message && !media) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Validation Error!',
                        text: 'Please enter a message or select a media file.',
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                } else {
                    alert('Please enter a message or select a media file.');
                }
                return false;
            }

            // Show loading state
            var sendBtn = $('#sendBtn');
            if (!sendBtn.length) {
                console.error('Send button not found!');
                alert('Send button not found. Please refresh the page.');
                return false;
            }
            
            var originalText = sendBtn.html();
            sendBtn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-1"></i> Queuing Messages...');
            
            console.log('Sending WhatsApp message to all contacts:', {
                hasMessage: !!message,
                hasMedia: !!media
            });

            // Prepare form data
            var formData = new FormData();
            
            // Get CSRF token from meta tag
            var csrfToken = $('meta[name="csrf-token"]').attr('content');
            if (csrfToken) {
                formData.append('_token', csrfToken);
            } else {
                console.error('CSRF token not found!');
                sendBtn.prop('disabled', false).html(originalText);
                alert('CSRF token not found. Please refresh the page.');
                return false;
            }
            
            // Add form fields
            if (message) {
                formData.append('message', message);
            }
            if (media) {
                formData.append('media', media);
            }

            // Send AJAX request
            var ajaxUrl = '{{ route("whatsapp.send-message.store") }}';
            console.log('Starting AJAX request to:', ajaxUrl);
            
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 60000,
                beforeSend: function() {
                    console.log('AJAX beforeSend called');
                },
                success: function(response) {
                    console.log('WhatsApp send success:', response);
                    sendBtn.prop('disabled', false).html(originalText);
                    
                    if (response && response.success) {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                title: 'Success!',
                                text: response.message || 'Messages queued successfully!',
                                icon: 'success',
                                confirmButtonText: 'OK'
                            }).then(() => {
                                $('#sendMessageForm')[0].reset();
                                $('#mediaPreview').empty();
                                $('#charCount').text('0');
                            });
                        } else {
                            alert('Messages queued successfully!');
                            $('#sendMessageForm')[0].reset();
                            $('#mediaPreview').empty();
                            $('#charCount').text('0');
                        }
                    } else {
                        var errorMsg = (response && response.message) ? response.message : 'Failed to queue messages.';
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                title: 'Error!',
                                text: errorMsg,
                                icon: 'error',
                                confirmButtonText: 'OK'
                            });
                        } else {
                            alert('Error: ' + errorMsg);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('WhatsApp send error:', {
                        xhr: xhr,
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        statusCode: xhr.status
                    });
                    
                    sendBtn.prop('disabled', false).html(originalText);
                    
                    var errorMessage = 'Failed to queue messages.';
                    
                    if (xhr.responseJSON) {
                        errorMessage = xhr.responseJSON.message || xhr.responseJSON.error || errorMessage;
                        if (xhr.responseJSON.errors) {
                            var errors = Object.values(xhr.responseJSON.errors).flat();
                            errorMessage = errors.join(', ');
                        }
                    } else if (xhr.status === 419) {
                        errorMessage = 'CSRF token mismatch. Please refresh the page and try again.';
                    } else if (xhr.status === 0) {
                        errorMessage = 'Network error. Please check your connection and try again.';
                    } else if (xhr.status === 500) {
                        errorMessage = 'Server error. Please try again later.';
                    } else if (status === 'timeout') {
                        errorMessage = 'Request timeout. Please try again.';
                    } else if (xhr.statusText) {
                        errorMessage = xhr.statusText;
                    }
                    
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            title: 'Error!',
                            text: errorMessage,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    } else {
                        alert('Error: ' + errorMessage);
                    }
                },
                complete: function() {
                    // Already handled in success/error
                }
            });
            
            return false;
        });
        
        // Prevent form submission (just in case)
        $('#sendMessageForm').on('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        });
    });
</script>
@endpush
