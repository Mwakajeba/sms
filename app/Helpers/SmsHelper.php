<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class SmsHelper
{
    /**
     * Get SMS configuration from environment variables
     * Reads from the settings configured in the SMS Settings page
     * Checks config() first (for temporary testing), then falls back to env()
     */
    protected static function getConfig()
    {
        // Read from config first (allows temporary override for testing), then fall back to env
        // These values are set via the SMS Settings form
        return [
            'senderid' => trim((string) (config('services.sms.senderid') ?: env('BEEM_SENDER_ID', env('SMS_SENDERID', '')))),
            'token' => trim((string) (config('services.sms.token') ?: env('BEEM_SECRET_KEY', env('SMS_TOKEN', '')))),
            'key' => trim((string) (config('services.sms.key') ?: env('BEEM_API_KEY', env('SMS_KEY', '')))),
            'url' => trim((string) (config('services.sms.url') ?: env('BEEM_SMS_URL', env('SMS_URL', 'https://apisms.beem.africa/v1/send')))),
        ];
    }

    /**
     * Check if SMS is properly configured
     */
    public static function isConfigured()
    {
        $config = self::getConfig();
        return !empty($config['senderid']) 
            && !empty($config['token']) 
            && !empty($config['key']) 
            && !empty($config['url']);
    }

    /**
     * Send SMS message
     * 
     * @param string $phone Phone number (will be cleaned)
     * @param string $message Message content
     * @return array|string Returns array with success status and response, or error string
     */
    public static function send($phone, $message)
    {
        try {
            // Get configuration from environment (set via SMS Settings)
            $config = self::getConfig();

            // Validate configuration
            if (!self::isConfigured()) {
                $error = 'SMS is not properly configured. Please configure SMS settings in Settings > SMS Setting.';
                Log::error('SMS Error: ' . $error);
                return [
                    'success' => false,
                    'error' => $error,
                    'message' => 'SMS configuration missing'
                ];
            }

            // Clean phone number - remove any non-numeric characters except +
            $phone = preg_replace('/[^0-9+]/', '', $phone);
            
            if (empty($phone)) {
                $error = 'Invalid phone number provided.';
                Log::error('SMS Error: ' . $error);
                return [
                    'success' => false,
                    'error' => $error,
                    'message' => 'Invalid phone number'
                ];
            }

            // Validate message
            if (empty(trim($message))) {
                $error = 'Message cannot be empty.';
                Log::error('SMS Error: ' . $error);
                return [
                    'success' => false,
                    'error' => $error,
                    'message' => 'Empty message'
                ];
            }

            // Prepare POST data
            $postData = [
                'source_addr' => $config['senderid'],
                'encoding' => 0,
                'schedule_time' => '',
                'message' => $message,
                'recipients' => [
                    [
                        'recipient_id' => '1',
                        'dest_addr' => $phone
                    ]
                ]
            ];

            // Initialize cURL
            $ch = curl_init($config['url']);
            if ($ch === false) {
                $error = 'Failed to initialize cURL.';
                Log::error('SMS Error: ' . $error);
                return [
                    'success' => false,
                    'error' => $error,
                    'message' => 'cURL initialization failed'
                ];
            }

            // Set cURL options
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization:Basic ' . base64_encode("{$config['key']}:{$config['token']}"),
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($postData)
            ]);

            // Execute request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);

            // Handle cURL errors
            if ($curlErrno !== 0) {
                $error = "cURL Error ({$curlErrno}): {$curlError}";
                Log::error('SMS Error: ' . $error, [
                    'phone' => $phone,
                    'url' => $config['url']
                ]);
                curl_close($ch);
                return [
                    'success' => false,
                    'error' => $error,
                    'message' => 'Network error occurred'
                ];
            }

            curl_close($ch);

            // Parse response
            $responseData = json_decode($response, true);
            
            // Check HTTP status code
            if ($httpCode >= 200 && $httpCode < 300) {
                Log::info('SMS sent successfully', [
                    'phone' => $phone,
                    'http_code' => $httpCode,
                    'response' => $responseData
                ]);
                
                return [
                    'success' => true,
                    'response' => $responseData ?: $response,
                    'http_code' => $httpCode,
                    'message' => 'SMS sent successfully'
                ];
            } else {
                $error = "SMS API returned error (HTTP {$httpCode}): " . ($responseData['message'] ?? $response);
                Log::error('SMS Error: ' . $error, [
                    'phone' => $phone,
                    'http_code' => $httpCode,
                    'response' => $response
                ]);
                
                return [
                    'success' => false,
                    'error' => $error,
                    'http_code' => $httpCode,
                    'response' => $responseData ?: $response,
                    'message' => 'SMS API error'
                ];
            }

        } catch (\Exception $e) {
            $error = 'Exception occurred while sending SMS: ' . $e->getMessage();
            Log::error('SMS Exception: ' . $error, [
                'phone' => $phone ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => $error,
                'message' => 'Unexpected error occurred'
            ];
        }
    }

    /**
     * Test SMS configuration by sending a test message
     * 
     * @param string $testPhone Phone number to send test message to
     * @return array Result with success status and message
     */
    public static function test($testPhone)
    {
        if (!self::isConfigured()) {
            return [
                'success' => false,
                'message' => 'SMS is not properly configured. Please configure all SMS settings.'
            ];
        }

        $testMessage = 'Test SMS from SmartFinance system. If you receive this, your SMS configuration is working correctly.';
        $result = self::send($testPhone, $testMessage);

        return $result;
    }
}
