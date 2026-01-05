<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsAppService
{
    protected $apiUrl;
    protected $secretKey;

    public function __construct()
    {
        $this->apiUrl = config('services.whatsapp.api_url', 'https://live.nialike.com/api/V1/wa-message/send');
        $this->secretKey = config('services.whatsapp.secret_key', env('WHATSAPP_SECRET_KEY', 'C2mdyNqqZiOYUxA2'));
    }

    /**
     * Send WhatsApp message
     *
     * @param string $phoneNumber
     * @param string|null $message
     * @param string|null $mediaPath
     * @return array
     */
    public function sendMessage($phoneNumber, $message = null, $mediaPath = null)
    {
        try {
            // Clean phone number - remove + and keep only digits
            $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
            
            $data = [
                "secret_key" => $this->secretKey,
                "phone_number" => $phoneNumber,
            ];

            // Add media URL if provided
            if (!empty($mediaPath)) {
                // If media_path is a local file, we need to get the full public URL
                if (Storage::disk('public')->exists($mediaPath)) {
                    // Get the full public URL for the media file
                    // Use Storage::url() which handles the URL generation properly
                    $mediaUrl = Storage::disk('public')->url($mediaPath);
                    
                    // If Storage::url() returns a relative path, make it absolute
                    if (!filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
                        $baseUrl = rtrim(config('app.url'), '/');
                        $mediaUrl = $baseUrl . '/' . ltrim($mediaUrl, '/');
                    }
                    
                    $data["media_url"] = $mediaUrl;
                } else {
                    // If it's already a URL, use it directly
                    $data["media_url"] = $mediaPath;
                }
                
                // When sending media, send ONLY media (no text message)
                // This matches the user's requirement: "i want just a media not that plain text"
            } else {
                // Only add message if no media is provided
                if (!empty($message)) {
                    $data["message"] = $message;
                }
            }
            
            // Log what we're sending
            Log::info('WhatsApp API Request Data', [
                'phone' => $phoneNumber,
                'has_message' => isset($data['message']),
                'has_media_url' => isset($data['media_url']),
                'media_url' => $data['media_url'] ?? null,
                'message_preview' => isset($data['message']) ? substr($data['message'], 0, 50) . '...' : null,
                'full_data' => array_merge($data, ['secret_key' => '***HIDDEN***']) // Hide secret key in logs
            ]);

            // Make API request
            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json"
                ],
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            
            if ($curlErrno) {
                curl_close($ch);
                
                $errorMessages = [
                    7 => 'Could not connect to WhatsApp API server',
                    28 => 'Connection to WhatsApp API timed out',
                    35 => 'SSL connection error',
                    6 => 'Could not resolve WhatsApp API host',
                ];
                
                $errorMessage = !empty($curlError) ? $curlError : ($errorMessages[$curlErrno] ?? "cURL Error #{$curlErrno}");
                
                Log::error('WhatsApp API cURL Error', [
                    'error' => $errorMessage,
                    'errno' => $curlErrno,
                    'phone' => $phoneNumber,
                    'url' => $this->apiUrl
                ]);
                
                return [
                    'success' => false,
                    'error' => 'cURL Error: ' . $errorMessage . ($curlErrno ? " (Error #{$curlErrno})" : '')
                ];
            }
            
            curl_close($ch);

            // Parse response
            $responseData = json_decode($response, true);
            
            // Log full response for debugging
            Log::info('WhatsApp API Response', [
                'http_code' => $httpCode,
                'raw_response' => $response,
                'parsed_response' => $responseData,
                'phone' => $phoneNumber,
                'url' => $this->apiUrl
            ]);

            // Check if request was successful
            if ($httpCode >= 200 && $httpCode < 300) {
                // Extract message ID from different possible response structures
                $messageId = null;
                
                // Handle nested response structure (response is a JSON string)
                if (isset($responseData['response']) && is_string($responseData['response'])) {
                    $nestedResponse = json_decode($responseData['response'], true);
                    if (isset($nestedResponse['messages'][0]['id'])) {
                        $messageId = $nestedResponse['messages'][0]['id'];
                    }
                }
                
                // Handle direct response structure
                if (!$messageId) {
                    if (isset($responseData['messages'][0]['id'])) {
                        $messageId = $responseData['messages'][0]['id'];
                    } elseif (isset($responseData['message_id'])) {
                        $messageId = $responseData['message_id'];
                    } elseif (isset($responseData['id'])) {
                        $messageId = $responseData['id'];
                    }
                }
                
                Log::info('WhatsApp Message Sent Successfully', [
                    'phone' => $phoneNumber,
                    'message_id' => $messageId,
                    'http_code' => $httpCode,
                    'full_response' => $response
                ]);
                
                return [
                    'success' => true,
                    'data' => $responseData,
                    'raw_response' => $response,
                    'message_id' => $messageId,
                ];
            } else {
                Log::warning('WhatsApp API Error Response', [
                    'http_code' => $httpCode,
                    'response' => $responseData,
                    'raw_response' => $response,
                    'phone' => $phoneNumber
                ]);
                
                return [
                    'success' => false,
                    'error' => $responseData['message'] ?? $responseData['error'] ?? 'Unknown error',
                    'data' => $responseData,
                    'raw_response' => $response
                ];
            }
        } catch (\Exception $e) {
            Log::error('WhatsApp API Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'phone' => $phoneNumber
            ]);
            
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }
}

