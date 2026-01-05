<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BulkWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;
    
    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = 5;

    protected $recipients;
    protected $message;
    protected $mediaPath;
    protected $mediaType;

    /**
     * Create a new job instance.
     */
    public function __construct($recipients, $message, $mediaPath = null, $mediaType = null)
    {
        $this->recipients = $recipients; // Array of ['phone' => '...', 'name' => '...']
        $this->message = $message;
        $this->mediaPath = $mediaPath;
        $this->mediaType = $mediaType;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $whatsappService = new WhatsAppService();
        $successCount = 0;
        $failedCount = 0;

        Log::info('=== Starting Bulk WhatsApp Message Job ===', [
            'total_recipients' => count($this->recipients),
            'has_message' => !empty($this->message),
            'has_media' => !empty($this->mediaPath),
            'media_type' => $this->mediaType,
            'job_id' => $this->job->getJobId() ?? 'N/A'
        ]);

        foreach ($this->recipients as $recipient) {
            try {
                $phone = preg_replace('/[^0-9+]/', '', $recipient['phone'] ?? $recipient['phone_number'] ?? '');

                if (empty($phone)) {
                    $failedCount++;
                    continue;
                }

                // Create WhatsApp message record
                Log::info('Creating WhatsApp message record', [
                    'phone' => $phone,
                    'recipient_name' => $recipient['name'] ?? 'Unknown'
                ]);
                
                $whatsappMessage = WhatsAppMessage::create([
                    'phone' => $phone,
                    'message' => $this->message,
                    'media_path' => $this->mediaPath,
                    'media_type' => $this->mediaType,
                    'status' => 'pending',
                ]);
                
                Log::info('WhatsApp message record created', [
                    'whatsapp_message_id' => $whatsappMessage->id,
                    'phone' => $phone
                ]);

                // Send message via WhatsApp API
                $apiResponse = $whatsappService->sendMessage(
                    $phone,
                    $this->message,
                    $this->mediaPath
                );

                // Log API response for debugging
                Log::info('WhatsApp Message API Response', [
                    'whatsapp_message_id' => $whatsappMessage->id,
                    'phone' => $phone,
                    'success' => $apiResponse['success'] ?? false,
                    'message_id' => $apiResponse['message_id'] ?? null,
                    'raw_response' => $apiResponse['raw_response'] ?? null,
                    'error' => $apiResponse['error'] ?? null
                ]);

                // Update message status based on API response
                if ($apiResponse['success']) {
                    $updateData = [
                        'provider_message_id' => $apiResponse['message_id'] ?? null,
                        'status' => 'sent',
                        'sent_at' => now(),
                    ];
                    
                    // Store raw API response if available
                    if (isset($apiResponse['raw_response'])) {
                        $updateData['api_response'] = $apiResponse['raw_response'];
                    }
                    
                    $whatsappMessage->update($updateData);
                    
                    Log::info('WhatsApp Message Status Updated to Sent', [
                        'whatsapp_message_id' => $whatsappMessage->id,
                        'phone' => $phone,
                        'provider_message_id' => $apiResponse['message_id']
                    ]);
                    
                    $successCount++;
                } else {
                    $updateData = [
                        'status' => 'failed',
                    ];
                    
                    // Store raw API response even for failed messages
                    if (isset($apiResponse['raw_response'])) {
                        $updateData['api_response'] = $apiResponse['raw_response'];
                    }
                    
                    $whatsappMessage->update($updateData);
                    
                    $failedCount++;
                    Log::warning('Failed to send WhatsApp message', [
                        'whatsapp_message_id' => $whatsappMessage->id,
                        'phone' => $phone,
                        'error' => $apiResponse['error'] ?? 'Unknown error',
                        'raw_response' => $apiResponse['raw_response'] ?? null
                    ]);
                }

                // Add small delay to avoid rate limiting
                usleep(500000); // 0.5 seconds delay between messages

            } catch (\Exception $e) {
                $failedCount++;
                Log::error('Bulk WhatsApp message error', [
                    'phone' => $recipient['phone'] ?? 'Unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('=== Bulk WhatsApp Message Job Completed ===', [
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'total' => count($this->recipients),
            'success_rate' => count($this->recipients) > 0 ? round(($successCount / count($this->recipients)) * 100, 2) . '%' : '0%'
        ]);
    }
}

