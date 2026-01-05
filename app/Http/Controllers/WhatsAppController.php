<?php

namespace App\Http\Controllers;

use App\Models\PhoneBook;
use App\Models\WhatsAppMessage;
use App\Jobs\ImportPhoneBookJob;
use App\Jobs\BulkWhatsAppMessageJob;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Vinkla\Hashids\Facades\Hashids;
use Yajra\DataTables\Facades\DataTables;

class WhatsAppController extends Controller
{
    /**
     * Display the WhatsApp menu index page with cards
     */
    public function index()
    {
        return view('whatsapp.index');
    }

    /**
     * Display the Phone Book page
     */
    public function phoneBook()
    {
        $phoneBookCount = PhoneBook::count();
        return view('whatsapp.phone-book', compact('phoneBookCount'));
    }

    /**
     * Get phone books data for DataTables
     */
    public function getPhoneBooksData(Request $request)
    {
        if ($request->ajax()) {
            $phoneBooks = PhoneBook::query();

            return DataTables::eloquent($phoneBooks)
                ->addColumn('name', function ($phoneBook) {
                    return '<div class="d-flex align-items-center">
                                <div class="avatar-sm bg-light-primary text-primary rounded-circle d-flex align-items-center justify-content-center me-3">
                                    <i class="bx bx-user font-size-18"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">' . e($phoneBook->name) . '</h6>
                                </div>
                            </div>';
                })
                ->addColumn('phone_number', function ($phoneBook) {
                    return '<div><i class="bx bx-phone me-1"></i>' . e($phoneBook->phone_number) . '</div>';
                })
                ->addColumn('actions', function ($phoneBook) {
                    $actions = '';
                    $encodedId = Hashids::encode($phoneBook->id);
                    
                    $actions .= '<button type="button"
                                    class="btn btn-sm btn-outline-warning me-1 edit-phone-book-btn"
                                    data-bs-toggle="tooltip" 
                                    data-bs-placement="top" 
                                    title="Edit phone book"
                                    data-id="' . $encodedId . '"
                                    data-name="' . e($phoneBook->name) . '"
                                    data-phone="' . e($phoneBook->phone_number) . '">
                                    <i class="bx bx-edit"></i>
                                </button>';
                    
                    $actions .= '<button type="button"
                                    class="btn btn-sm btn-outline-danger delete-phone-book-btn"
                                    data-bs-toggle="tooltip" 
                                    data-bs-placement="top" 
                                    title="Delete phone book"
                                    data-id="' . $encodedId . '"
                                    data-name="' . e($phoneBook->name) . '">
                                    <i class="bx bx-trash"></i>
                                </button>';
                    
                    return '<div class="text-center">' . $actions . '</div>';
                })
                ->rawColumns(['name', 'phone_number', 'actions'])
                ->make(true);
        }
        
        return response()->json(['error' => 'Invalid request'], 400);
    }

    /**
     * Store a new phone book entry
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:20',
        ]);

        // Clean phone number
        $validated['phone_number'] = preg_replace('/[^0-9+]/', '', $validated['phone_number']);

        // Check if phone number already exists
        $exists = PhoneBook::where('phone_number', $validated['phone_number'])->exists();
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Phone number already exists in the phone book.'
            ], 422);
        }

        $phoneBook = PhoneBook::create([
            'name' => $validated['name'],
            'phone_number' => $validated['phone_number'],
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Phone book entry created successfully!'
        ]);
    }

    /**
     * Update a phone book entry
     */
    public function update(Request $request, $encodedId)
    {
        $id = Hashids::decode($encodedId)[0] ?? null;
        
        if (!$id) {
            return response()->json([
                'success' => false,
                'message' => 'Phone book entry not found.'
            ], 404);
        }

        $phoneBook = PhoneBook::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:20',
        ]);

        // Clean phone number
        $validated['phone_number'] = preg_replace('/[^0-9+]/', '', $validated['phone_number']);

        // Check if phone number already exists (excluding current entry)
        $exists = PhoneBook::where('phone_number', $validated['phone_number'])
            ->where('id', '!=', $id)
            ->exists();
        
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Phone number already exists in the phone book.'
            ], 422);
        }

        $phoneBook->update([
            'name' => $validated['name'],
            'phone_number' => $validated['phone_number'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Phone book entry updated successfully!'
        ]);
    }

    /**
     * Delete a phone book entry
     */
    public function destroy($encodedId)
    {
        $id = Hashids::decode($encodedId)[0] ?? null;
        
        if (!$id) {
            return response()->json([
                'success' => false,
                'message' => 'Phone book entry not found.'
            ], 404);
        }

        $phoneBook = PhoneBook::findOrFail($id);
        $phoneBook->delete();

        return response()->json([
            'success' => true,
            'message' => 'Phone book entry deleted successfully!'
        ]);
    }

    /**
     * Import phone books from CSV
     */
    public function import(Request $request)
    {
        $validated = $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:10240'
        ]);

        try {
            $file = $request->file('csv_file');
            $csvData = array_map('str_getcsv', file($file->getPathname()));

            // Dispatch job for bulk import
            ImportPhoneBookJob::dispatch($csvData, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Phone book import started. The import will be processed in the background.'
            ]);
        } catch (\Exception $e) {
            Log::error('Phone book import failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to process import: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download sample CSV template
     */
    public function downloadTemplate()
    {
        $filename = 'phone_book_sample.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () {
            $file = fopen('php://output', 'w');

            // Add headers
            fputcsv($file, ['Name', 'Phone Number']);

            // Add sample data
            fputcsv($file, ['John Doe', '+255712345678']);
            fputcsv($file, ['Jane Smith', '+255723456789']);
            fputcsv($file, ['Michael Johnson', '+255734567890']);
            fputcsv($file, ['Sarah Williams', '+255745678901']);
            fputcsv($file, ['David Brown', '+255756789012']);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Display the Send Message page
     */
    public function sendMessage()
    {
        $phoneBookCount = PhoneBook::count();
        return view('whatsapp.send-message', compact('phoneBookCount'));
    }

    /**
     * Send WhatsApp message to all phone book contacts
     */
    public function sendWhatsAppMessage(Request $request)
    {
        $validated = $request->validate([
            'message' => 'nullable|string|max:5000',
            'media' => 'nullable|file|mimes:jpg,jpeg,png,gif,pdf,doc,docx,mp4,avi,mov,mp3,wav|max:25600', // 25MB max
        ], [
            'media.max' => 'Media file size must not exceed 25MB.',
        ]);

        try {
            $mediaPath = null;
            $mediaType = null;

            // Handle media upload if present
            if ($request->hasFile('media')) {
                $file = $request->file('media');
                $mimeType = $file->getMimeType();
                
                // Determine media type
                if (str_starts_with($mimeType, 'image/')) {
                    $mediaType = 'image';
                    $mediaPath = $file->store('whatsapp/images', 'public');
                } elseif (str_starts_with($mimeType, 'video/')) {
                    $mediaType = 'video';
                    $mediaPath = $file->store('whatsapp/videos', 'public');
                } elseif (str_starts_with($mimeType, 'audio/')) {
                    $mediaType = 'audio';
                    $mediaPath = $file->store('whatsapp/audio', 'public');
                } else {
                    $mediaType = 'document';
                    $mediaPath = $file->store('whatsapp/documents', 'public');
                }
            }

            // Validate that either message or media is provided
            if (empty($validated['message']) && empty($mediaPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Either message text or media file is required.'
                ], 422);
            }

            // Get ALL phone book contacts
            $contacts = PhoneBook::orderBy('name')->get();
            
            if ($contacts->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No contacts found in phone book. Please add contacts first.'
                ], 422);
            }

            // Prepare recipients array
            $recipients = $contacts->map(function ($contact) {
                return [
                    'phone' => $contact->phone_number,
                    'name' => $contact->name,
                ];
            })->toArray();

            // Log before dispatching
            Log::info('=== Dispatching Bulk WhatsApp Message Job ===', [
                'recipient_count' => count($recipients),
                'has_message' => !empty($validated['message']),
                'has_media' => !empty($mediaPath),
                'media_type' => $mediaType,
                'queue_connection' => config('queue.default')
            ]);

            // Dispatch bulk message job
            BulkWhatsAppMessageJob::dispatch(
                $recipients,
                $validated['message'] ?? null,
                $mediaPath,
                $mediaType
            );
            
            Log::info('Bulk WhatsApp Message Job Dispatched', [
                'recipient_count' => count($recipients),
                'queue_connection' => config('queue.default')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Messages queued successfully! ' . count($recipients) . ' message(s) will be sent to all phone book contacts in the background.',
                'count' => count($recipients)
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp bulk message send failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to queue messages: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Send bulk WhatsApp messages from phone book
     */
    public function sendBulkFromPhoneBook(Request $request)
    {
        $validated = $request->validate([
            'phone_ids' => 'required|array|min:1',
            'phone_ids.*' => 'required|integer|exists:phone_books,id',
            'message' => 'nullable|string|max:5000',
            'media' => 'nullable|file|mimes:jpg,jpeg,png,gif,pdf,doc,docx,mp4,avi,mov,mp3,wav|max:25600',
        ], [
            'phone_ids.required' => 'Please select at least one contact.',
            'phone_ids.min' => 'Please select at least one contact.',
        ]);

        try {
            $mediaPath = null;
            $mediaType = null;

            // Handle media upload if present
            if ($request->hasFile('media')) {
                $file = $request->file('media');
                $mimeType = $file->getMimeType();
                
                if (str_starts_with($mimeType, 'image/')) {
                    $mediaType = 'image';
                    $mediaPath = $file->store('whatsapp/images', 'public');
                } elseif (str_starts_with($mimeType, 'video/')) {
                    $mediaType = 'video';
                    $mediaPath = $file->store('whatsapp/videos', 'public');
                } elseif (str_starts_with($mimeType, 'audio/')) {
                    $mediaType = 'audio';
                    $mediaPath = $file->store('whatsapp/audio', 'public');
                } else {
                    $mediaType = 'document';
                    $mediaPath = $file->store('whatsapp/documents', 'public');
                }
            }

            // Validate that either message or media is provided
            if (empty($validated['message']) && empty($mediaPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Either message text or media file is required.'
                ], 422);
            }

            // Get selected phone book contacts
            $contacts = PhoneBook::whereIn('id', $validated['phone_ids'])->get();
            
            if ($contacts->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid contacts found.'
                ], 422);
            }

            // Prepare recipients array
            $recipients = $contacts->map(function ($contact) {
                return [
                    'phone' => $contact->phone_number,
                    'name' => $contact->name,
                ];
            })->toArray();

            // Dispatch bulk message job
            BulkWhatsAppMessageJob::dispatch(
                $recipients,
                $validated['message'] ?? null,
                $mediaPath,
                $mediaType
            );

            return response()->json([
                'success' => true,
                'message' => 'Bulk messages queued successfully! ' . count($recipients) . ' message(s) will be sent in the background.',
                'count' => count($recipients)
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk WhatsApp message send failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to queue bulk messages: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send bulk WhatsApp messages from Excel import
     */
    public function sendBulkFromExcel(Request $request)
    {
        $validated = $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'message' => 'nullable|string|max:5000',
            'media' => 'nullable|file|mimes:jpg,jpeg,png,gif,pdf,doc,docx,mp4,avi,mov,mp3,wav|max:25600',
        ]);

        try {
            $mediaPath = null;
            $mediaType = null;

            // Handle media upload if present
            if ($request->hasFile('media')) {
                $file = $request->file('media');
                $mimeType = $file->getMimeType();
                
                if (str_starts_with($mimeType, 'image/')) {
                    $mediaType = 'image';
                    $mediaPath = $file->store('whatsapp/images', 'public');
                } elseif (str_starts_with($mimeType, 'video/')) {
                    $mediaType = 'video';
                    $mediaPath = $file->store('whatsapp/videos', 'public');
                } elseif (str_starts_with($mimeType, 'audio/')) {
                    $mediaType = 'audio';
                    $mediaPath = $file->store('whatsapp/audio', 'public');
                } else {
                    $mediaType = 'document';
                    $mediaPath = $file->store('whatsapp/documents', 'public');
                }
            }

            // Validate that either message or media is provided
            if (empty($validated['message']) && empty($mediaPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Either message text or media file is required.'
                ], 422);
            }

            // Read Excel/CSV file
            $file = $request->file('excel_file');
            $extension = $file->getClientOriginalExtension();
            
            $recipients = [];
            
            if (in_array($extension, ['xlsx', 'xls'])) {
                // Handle Excel files using PhpSpreadsheet
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file->getPathname());
                $spreadsheet = $reader->load($file->getPathname());
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                
                // Skip header row
                array_shift($rows);
                
                foreach ($rows as $row) {
                    if (count($row) >= 2 && !empty($row[0]) && !empty($row[1])) {
                        $recipients[] = [
                            'phone' => preg_replace('/[^0-9+]/', '', $row[1]),
                            'name' => trim($row[0]),
                        ];
                    }
                }
            } else {
                // Handle CSV files
                $csvData = array_map('str_getcsv', file($file->getPathname()));
                
                // Skip header row
                array_shift($csvData);
                
                foreach ($csvData as $row) {
                    if (count($row) >= 2 && !empty($row[0]) && !empty($row[1])) {
                        $recipients[] = [
                            'phone' => preg_replace('/[^0-9+]/', '', $row[1]),
                            'name' => trim($row[0]),
                        ];
                    }
                }
            }

            if (empty($recipients)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid recipients found in the file. Please check the file format.'
                ], 422);
            }

            // Dispatch bulk message job
            BulkWhatsAppMessageJob::dispatch(
                $recipients,
                $validated['message'] ?? null,
                $mediaPath,
                $mediaType
            );

            return response()->json([
                'success' => true,
                'message' => 'Bulk messages queued successfully! ' . count($recipients) . ' message(s) will be sent in the background.',
                'count' => count($recipients)
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk WhatsApp message from Excel failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to process Excel file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the Report page
     */
    public function report()
    {
        return view('whatsapp.report');
    }

    /**
     * Get WhatsApp messages data for DataTables
     */
    public function getReportData(Request $request)
    {
        if ($request->ajax()) {
            $query = WhatsAppMessage::query();

            // Date range filter
            if ($request->has('date_from') && !empty($request->date_from)) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->has('date_to') && !empty($request->date_to)) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            // Status filter
            if ($request->has('status') && !empty($request->status)) {
                $query->where('status', $request->status);
            }

            return DataTables::eloquent($query)
                ->addColumn('phone', function ($message) {
                    return '<span class="fw-bold">' . e($message->phone) . '</span>';
                })
                ->addColumn('message_preview', function ($message) {
                    if ($message->message) {
                        $preview = Str::limit($message->message, 50);
                        return '<span title="' . e($message->message) . '">' . e($preview) . '</span>';
                    }
                    return '<span class="text-muted">-</span>';
                })
                ->addColumn('media', function ($message) {
                    if ($message->hasMedia()) {
                        $icon = '';
                        switch ($message->media_type) {
                            case 'image':
                                $icon = '<i class="bx bx-image text-primary"></i>';
                                break;
                            case 'video':
                                $icon = '<i class="bx bx-video text-danger"></i>';
                                break;
                            case 'audio':
                                $icon = '<i class="bx bx-music text-success"></i>';
                                break;
                            default:
                                $icon = '<i class="bx bx-file text-secondary"></i>';
                        }
                        return $icon . ' <small class="text-muted">' . e($message->media_type) . '</small>';
                    }
                    return '<span class="text-muted">-</span>';
                })
                ->addColumn('status', function ($message) {
                    return $message->status_badge;
                })
                ->addColumn('sent_at', function ($message) {
                    return $message->sent_at ? $message->sent_at->format('Y-m-d H:i:s') : '<span class="text-muted">-</span>';
                })
                ->addColumn('delivered_at', function ($message) {
                    return $message->delivered_at ? $message->delivered_at->format('Y-m-d H:i:s') : '<span class="text-muted">-</span>';
                })
                ->addColumn('read_at', function ($message) {
                    return $message->read_at ? $message->read_at->format('Y-m-d H:i:s') : '<span class="text-muted">-</span>';
                })
                ->addColumn('created_at', function ($message) {
                    return $message->created_at->format('Y-m-d H:i:s');
                })
                ->rawColumns(['phone', 'message_preview', 'media', 'status', 'sent_at', 'delivered_at', 'read_at'])
                ->make(true);
        }
    }

    /**
     * Webhook endpoint to receive status updates from WhatsApp API
     */
    public function webhook(Request $request)
    {
        try {
            // Log incoming webhook request for debugging
            Log::info('WhatsApp webhook received', [
                'headers' => $request->headers->all(),
                'body' => $request->all(),
                'raw_body' => $request->getContent(),
                'ip' => $request->ip(),
                'method' => $request->method()
            ]);

            // Try to get message_id and status from different possible locations
            // WhatsApp API might send data in different formats
            $messageId = $request->input('message_id') 
                ?? $request->input('id') 
                ?? $request->input('provider_message_id')
                ?? $request->input('entry.0.changes.0.value.messages.0.id') // WhatsApp Business API format
                ?? null;
                
            $status = $request->input('status')
                ?? $request->input('entry.0.changes.0.value.statuses.0.status') // WhatsApp Business API format
                ?? null;

            // If not found in request, try JSON body
            if (!$messageId || !$status) {
                $jsonBody = json_decode($request->getContent(), true);
                if ($jsonBody) {
                    $messageId = $messageId ?? $jsonBody['message_id'] ?? $jsonBody['id'] ?? $jsonBody['entry'][0]['changes'][0]['value']['messages'][0]['id'] ?? null;
                    $status = $status ?? $jsonBody['status'] ?? $jsonBody['entry'][0]['changes'][0]['value']['statuses'][0]['status'] ?? null;
                }
            }

            // Validate required fields
            if (!$messageId) {
                Log::warning('WhatsApp webhook: message_id missing', [
                    'request_data' => $request->all(),
                    'raw_body' => $request->getContent()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Message ID is required'
                ], 400);
            }

            if (!$status) {
                Log::warning('WhatsApp webhook: status missing', [
                    'message_id' => $messageId,
                    'request_data' => $request->all()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Status is required'
                ], 400);
            }

            // Normalize status
            $status = strtolower($status);
            $validStatuses = ['pending', 'sent', 'delivered', 'read', 'failed', 'accepted'];
            
            if (!in_array($status, $validStatuses)) {
                // Map WhatsApp API statuses to our statuses
                $statusMap = [
                    'accepted' => 'sent',
                    'received' => 'delivered',
                    'viewed' => 'read',
                ];
                
                if (isset($statusMap[$status])) {
                    $status = $statusMap[$status];
                } else {
                    Log::warning('WhatsApp webhook: invalid status', [
                        'message_id' => $messageId,
                        'status' => $status
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid status: ' . $status
                    ], 400);
                }
            }

            // Optional: Validate webhook secret if BOTH are provided
            // Only validate if the webhook sends a secret AND we have one configured
            $webhookSecret = $request->input('secret_key') ?? $request->header('X-Webhook-Secret') ?? $request->header('Authorization');
            $expectedSecret = config('services.whatsapp.secret_key');
            
            // Only validate secret if webhook provides one AND we have one configured
            // If webhook doesn't send secret, we'll accept it (for APIs that don't send secrets)
            if ($webhookSecret && $expectedSecret && $webhookSecret !== $expectedSecret) {
                Log::warning('WhatsApp webhook unauthorized - secret mismatch', [
                    'ip' => $request->ip(),
                    'provided_secret' => substr($webhookSecret, 0, 5) . '...',
                    'expected_secret' => substr($expectedSecret, 0, 5) . '...'
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }
            
            // Log if secret validation was skipped
            if (!$webhookSecret && $expectedSecret) {
                Log::info('WhatsApp webhook accepted without secret validation', [
                    'ip' => $request->ip(),
                    'note' => 'Webhook did not provide secret key, but we have one configured'
                ]);
            }

            // Find message by provider_message_id
            $whatsappMessage = WhatsAppMessage::where('provider_message_id', $messageId)->first();

            if (!$whatsappMessage) {
                Log::warning('WhatsApp webhook: Message not found', [
                    'message_id' => $messageId,
                    'status' => $status
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Message not found'
                ], 404);
            }

            // Update message status and timestamps
            $updateData = ['status' => $status];

            switch ($status) {
                case 'sent':
                    if (!$whatsappMessage->sent_at) {
                        $updateData['sent_at'] = now();
                    }
                    break;
                
                case 'delivered':
                    if (!$whatsappMessage->delivered_at) {
                        $updateData['delivered_at'] = now();
                    }
                    // Also set sent_at if not already set
                    if (!$whatsappMessage->sent_at) {
                        $updateData['sent_at'] = now();
                    }
                    break;
                
                case 'read':
                    if (!$whatsappMessage->read_at) {
                        $updateData['read_at'] = now();
                    }
                    // Also set delivered_at and sent_at if not already set
                    if (!$whatsappMessage->delivered_at) {
                        $updateData['delivered_at'] = now();
                    }
                    if (!$whatsappMessage->sent_at) {
                        $updateData['sent_at'] = now();
                    }
                    break;
                
                case 'failed':
                    // Don't update timestamps for failed messages
                    break;
            }

            $whatsappMessage->update($updateData);

            Log::info('WhatsApp webhook: Message status updated', [
                'message_id' => $messageId,
                'old_status' => $whatsappMessage->getOriginal('status'),
                'new_status' => $status,
                'whatsapp_message_id' => $whatsappMessage->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status updated successfully',
                'data' => [
                    'id' => $whatsappMessage->id,
                    'message_id' => $messageId,
                    'status' => $status,
                    'updated_at' => $whatsappMessage->updated_at
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('WhatsApp webhook validation error', [
                'errors' => $e->errors(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('WhatsApp webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error: ' . $e->getMessage()
            ], 500);
        }
    }
}

