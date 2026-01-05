@extends('layouts.main')

@section('title', 'WhatsApp Report')

@section('content')
    <div class="page-wrapper">
        <div class="page-content">
            <x-breadcrumbs-with-icons :links="[
                ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
                ['label' => 'WhatsApp', 'url' => route('whatsapp.index'), 'icon' => 'bx bxl-whatsapp'],
                ['label' => 'Report', 'url' => '#', 'icon' => 'bx bx-file']
            ]" />
            <h6 class="mb-0 text-uppercase">WHATSAPP REPORT</h6>
            <hr />

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title mb-4">WhatsApp Messages Report</h4>

                            <!-- Filters -->
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <label for="date_from" class="form-label">Date From</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from">
                                </div>
                                <div class="col-md-3">
                                    <label for="date_to" class="form-label">Date To</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to">
                                </div>
                                <div class="col-md-3">
                                    <label for="status_filter" class="form-label">Status</label>
                                    <select class="form-select" id="status_filter" name="status">
                                        <option value="">All Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="sent">Sent</option>
                                        <option value="delivered">Delivered</option>
                                        <option value="read">Read</option>
                                        <option value="failed">Failed</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">&nbsp;</label>
                                    <div>
                                        <button type="button" class="btn btn-primary" id="filterBtn">
                                            <i class="bx bx-filter me-1"></i> Filter
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="resetBtn">
                                            <i class="bx bx-refresh me-1"></i> Reset
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- DataTable -->
                            <div class="table-responsive">
                                <table id="whatsappReportTable" class="table table-striped table-bordered" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th>Phone</th>
                                            <th>Message</th>
                                            <th>Media</th>
                                            <th>Status</th>
                                            <th>Sent At</th>
                                            <th>Delivered At</th>
                                            <th>Read At</th>
                                            <th>Created At</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        var table = $('#whatsappReportTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route("whatsapp.report.data") }}',
                data: function(d) {
                    d.date_from = $('#date_from').val();
                    d.date_to = $('#date_to').val();
                    d.status = $('#status_filter').val();
                }
            },
            columns: [
                { data: 'phone', name: 'phone' },
                { data: 'message_preview', name: 'message' },
                { data: 'media', name: 'media_type' },
                { data: 'status', name: 'status' },
                { data: 'sent_at', name: 'sent_at' },
                { data: 'delivered_at', name: 'delivered_at' },
                { data: 'read_at', name: 'read_at' },
                { data: 'created_at', name: 'created_at' }
            ],
            order: [[7, 'desc']], // Order by created_at desc
            pageLength: 25,
            language: {
                processing: '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>'
            }
        });

        // Filter button
        $('#filterBtn').on('click', function() {
            table.ajax.reload();
        });

        // Reset button
        $('#resetBtn').on('click', function() {
            $('#date_from').val('');
            $('#date_to').val('');
            $('#status_filter').val('');
            table.ajax.reload();
        });

        // Allow Enter key to trigger filter
        $('#date_from, #date_to, #status_filter').on('keypress', function(e) {
            if (e.which === 13) {
                table.ajax.reload();
            }
        });
    });
</script>
@endpush
