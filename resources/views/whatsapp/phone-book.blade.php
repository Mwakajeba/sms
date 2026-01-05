@extends('layouts.main')

@section('title', 'WhatsApp Phone Book')

@section('content')
    <div class="page-wrapper">
        <div class="page-content">
            <x-breadcrumbs-with-icons :links="[
                ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
                ['label' => 'WhatsApp', 'url' => route('whatsapp.index'), 'icon' => 'bx bxl-whatsapp'],
                ['label' => 'Phone Book', 'url' => '#', 'icon' => 'bx bx-book']
            ]" />
            <h6 class="mb-0 text-uppercase">WHATSAPP PHONE BOOK</h6>
            <hr />

            <!-- Dashboard Stats -->
            <div class="row row-cols-1 row-cols-lg-4">
                <div class="col mb-4">
                    <div class="card radius-10">
                        <div class="card-body d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="text-muted mb-1">Total Contacts</p>
                                <h4 class="mb-0">{{ $phoneBookCount ?? 0 }}</h4>
                            </div>
                            <div class="ms-3">
                                <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center">
                                    <i class="bx bx-book font-size-24"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Phone Books Table -->
            <div class="row">
                <div class="col-12">
                    <div class="card radius-10">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h4 class="card-title mb-0">Phone Book List</h4>
                                <div>
                                    <a href="{{ route('whatsapp.phone-book.template') }}" class="btn btn-info me-2">
                                        <i class="bx bx-download"></i> Download Template
                                    </a>
                                    <button type="button" class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#importPhoneBookModal">
                                        <i class="bx bx-upload"></i> Import
                                    </button>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPhoneBookModal">
                                        <i class="bx bx-plus"></i> Add Contact
                                    </button>
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
                                    <i class="bx bx-error-circle me-2"></i>
                                    {{ session('error') }}
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            @endif

                            <div class="table-responsive">
                                <table class="table table-hover dt-responsive nowrap" id="phoneBooksTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Name</th>
                                            <th>Phone Number</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Data will be loaded via Ajax -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Phone Book Modal -->
    <div class="modal fade" id="addPhoneBookModal" tabindex="-1" aria-labelledby="addPhoneBookModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addPhoneBookModalLabel">
                        <i class="bx bx-plus me-2"></i> Add New Contact
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addPhoneBookForm">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required placeholder="Enter contact name">
                        </div>
                        <div class="mb-3">
                            <label for="phone_number" class="form-label">Phone Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="phone_number" name="phone_number" required placeholder="Enter phone number">
                            <small class="text-muted">Enter phone number with country code (e.g., +255123456789)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bx bx-save me-1"></i> Save Contact
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Phone Book Modal -->
    <div class="modal fade" id="editPhoneBookModal" tabindex="-1" aria-labelledby="editPhoneBookModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editPhoneBookModalLabel">
                        <i class="bx bx-edit me-2"></i> Edit Contact
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editPhoneBookForm">
                    @csrf
                    @method('PUT')
                    <input type="hidden" id="edit_id" name="id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_name" name="name" required placeholder="Enter contact name">
                        </div>
                        <div class="mb-3">
                            <label for="edit_phone_number" class="form-label">Phone Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_phone_number" name="phone_number" required placeholder="Enter phone number">
                            <small class="text-muted">Enter phone number with country code (e.g., +255123456789)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bx bx-save me-1"></i> Update Contact
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Import Phone Book Modal -->
    <div class="modal fade" id="importPhoneBookModal" tabindex="-1" aria-labelledby="importPhoneBookModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="importPhoneBookModalLabel">
                        <i class="bx bx-upload me-2"></i> Import Phone Book
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="importPhoneBookForm" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="csv_file" class="form-label">CSV File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv,.txt" required>
                            <small class="text-muted">Upload a CSV file with columns: Name, Phone Number</small>
                        </div>
                        <div class="alert alert-info">
                            <i class="bx bx-info-circle me-2"></i>
                            <strong>CSV Format:</strong> The CSV file should have two columns:
                            <ul class="mb-0 mt-2">
                                <li>Column 1: Name</li>
                                <li>Column 2: Phone Number</li>
                            </ul>
                            <strong>Note:</strong> The first row can be a header row (Name, Phone Number) or data. Duplicate phone numbers will be skipped.
                            <div class="mt-2">
                                <a href="{{ route('whatsapp.phone-book.template') }}" class="btn btn-sm btn-outline-info">
                                    <i class="bx bx-download me-1"></i> Download Sample CSV Template
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bx bx-upload me-1"></i> Import
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        // Initialize DataTable with Ajax
        var table = $('#phoneBooksTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route("whatsapp.phone-book.data") }}',
                type: 'GET',
                error: function(xhr, error, code) {
                    console.error('DataTables Ajax Error:', error, code);
                    Swal.fire({
                        title: 'Error!',
                        text: 'Failed to load phone book data. Please refresh the page.',
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                }
            },
            columns: [
                { data: 'name', name: 'name', title: 'Name', orderable: true, searchable: true },
                { data: 'phone_number', name: 'phone_number', title: 'Phone Number', orderable: true, searchable: true },
                { data: 'actions', name: 'actions', title: 'Actions', orderable: false, searchable: false }
            ],
            responsive: true,
            order: [[0, 'asc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            language: {
                search: "",
                searchPlaceholder: "Search contacts...",
                processing: '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>'
            }
        });

        // Handle Add Phone Book Form
        $('#addPhoneBookForm').on('submit', function(e) {
            e.preventDefault();
            
            var formData = $(this).serialize();
            
            $.ajax({
                url: '{{ route("whatsapp.phone-book.store") }}',
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        $('#addPhoneBookModal').modal('hide');
                        $('#addPhoneBookForm')[0].reset();
                        table.ajax.reload();
                        Swal.fire({
                            title: 'Success!',
                            text: response.message,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    var errors = xhr.responseJSON?.errors || {};
                    var errorMessage = xhr.responseJSON?.message || 'Failed to create contact.';
                    
                    if (Object.keys(errors).length > 0) {
                        var errorList = '<ul class="mb-0">';
                        $.each(errors, function(key, value) {
                            errorList += '<li>' + value[0] + '</li>';
                        });
                        errorList += '</ul>';
                        Swal.fire({
                            title: 'Validation Error!',
                            html: errorList,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    } else {
                        Swal.fire({
                            title: 'Error!',
                            text: errorMessage,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    }
                }
            });
        });

        // Handle Edit Button Click
        $(document).on('click', '.edit-phone-book-btn', function() {
            var id = $(this).data('id');
            var name = $(this).data('name');
            var phone = $(this).data('phone');
            
            $('#edit_id').val(id);
            $('#edit_name').val(name);
            $('#edit_phone_number').val(phone);
            
            $('#editPhoneBookForm').attr('action', '{{ url("whatsapp/phone-book") }}/' + id);
            $('#editPhoneBookModal').modal('show');
        });

        // Handle Edit Phone Book Form
        $('#editPhoneBookForm').on('submit', function(e) {
            e.preventDefault();
            
            var id = $('#edit_id').val();
            var formData = $(this).serialize();
            formData += '&_method=PUT';
            
            $.ajax({
                url: '{{ url("whatsapp/phone-book") }}/' + id,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        $('#editPhoneBookModal').modal('hide');
                        $('#editPhoneBookForm')[0].reset();
                        table.ajax.reload();
                        Swal.fire({
                            title: 'Success!',
                            text: response.message,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    var errors = xhr.responseJSON?.errors || {};
                    var errorMessage = xhr.responseJSON?.message || 'Failed to update contact.';
                    
                    if (Object.keys(errors).length > 0) {
                        var errorList = '<ul class="mb-0">';
                        $.each(errors, function(key, value) {
                            errorList += '<li>' + value[0] + '</li>';
                        });
                        errorList += '</ul>';
                        Swal.fire({
                            title: 'Validation Error!',
                            html: errorList,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    } else {
                        Swal.fire({
                            title: 'Error!',
                            text: errorMessage,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    }
                }
            });
        });

        // Handle Delete Button Click
        $(document).on('click', '.delete-phone-book-btn', function() {
            var id = $(this).data('id');
            var name = $(this).data('name');
            
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '{{ url("whatsapp/phone-book") }}/' + id,
                        type: 'DELETE',
                        data: {
                            _token: '{{ csrf_token() }}'
                        },
                        success: function(response) {
                            if (response.success) {
                                table.ajax.reload();
                                Swal.fire({
                                    title: 'Deleted!',
                                    text: response.message,
                                    icon: 'success',
                                    confirmButtonText: 'OK'
                                });
                            }
                        },
                        error: function(xhr) {
                            var errorMessage = xhr.responseJSON?.message || 'Failed to delete contact.';
                            Swal.fire({
                                title: 'Error!',
                                text: errorMessage,
                                icon: 'error',
                                confirmButtonText: 'OK'
                            });
                        }
                    });
                }
            });
        });

        // Handle Import Form
        $('#importPhoneBookForm').on('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            
            $.ajax({
                url: '{{ route("whatsapp.phone-book.import") }}',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        $('#importPhoneBookModal').modal('hide');
                        $('#importPhoneBookForm')[0].reset();
                        table.ajax.reload();
                        Swal.fire({
                            title: 'Success!',
                            text: response.message,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    var errorMessage = xhr.responseJSON?.message || 'Failed to import phone book.';
                    Swal.fire({
                        title: 'Error!',
                        text: errorMessage,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });

        // Reset modal forms when closed
        $('#addPhoneBookModal').on('hidden.bs.modal', function() {
            $('#addPhoneBookForm')[0].reset();
        });
        
        $('#editPhoneBookModal').on('hidden.bs.modal', function() {
            $('#editPhoneBookForm')[0].reset();
        });
        
        $('#importPhoneBookModal').on('hidden.bs.modal', function() {
            $('#importPhoneBookForm')[0].reset();
        });
    });
</script>
@endpush
