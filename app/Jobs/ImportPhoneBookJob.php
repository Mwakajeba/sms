<?php

namespace App\Jobs;

use App\Models\PhoneBook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportPhoneBookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $csvData;
    protected $userId;

    /**
     * Create a new job instance.
     */
    public function __construct($csvData, $userId)
    {
        $this->csvData = $csvData;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting phone book import job', [
            'total_rows' => count($this->csvData),
            'user_id' => $this->userId
        ]);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($this->csvData as $index => $row) {
            try {
                // Skip header row if present
                if ($index === 0 && (strtolower($row[0]) === 'name' || strtolower($row[0]) === 'phone_number' || strtolower($row[0]) === 'phone number')) {
                    continue;
                }

                // Validate row has at least 2 columns
                if (count($row) < 2) {
                    $skipped++;
                    continue;
                }

                $name = trim($row[0] ?? '');
                $phoneNumber = trim($row[1] ?? '');

                // Skip empty rows
                if (empty($name) || empty($phoneNumber)) {
                    $skipped++;
                    continue;
                }

                // Clean phone number (remove any non-numeric characters except +)
                $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

                // Check if phone number already exists
                $exists = PhoneBook::where('phone_number', $phoneNumber)->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }

                // Create phone book entry
                PhoneBook::create([
                    'name' => $name,
                    'phone_number' => $phoneNumber,
                    'user_id' => $this->userId,
                ]);

                $imported++;
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $index + 1,
                    'error' => $e->getMessage()
                ];
                Log::error('Failed to import phone book row', [
                    'row' => $index + 1,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('Phone book import completed', [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => count($errors)
        ]);
    }
}
