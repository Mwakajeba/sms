<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Subscription;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DefaultSubscriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * This seeder creates a default one-year subscription for all companies
     * that don't already have an active subscription.
     */
    public function run(): void
    {
        $this->command->info('Starting Default Subscription Seeder...');

        // Get all companies
        $companies = Company::all();

        if ($companies->isEmpty()) {
            $this->command->warn('No companies found. Please run CompanySeeder first.');
            return;
        }

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($companies as $company) {
            // Check if company already has an active subscription
            $activeSubscription = Subscription::where('company_id', $company->id)
                ->where('status', 'active')
                ->first();

            if ($activeSubscription) {
                $this->command->info("Company '{$company->name}' already has an active subscription. Skipping...");
                $skippedCount++;
                continue;
            }

            // Create one-year subscription starting from today
            $startDate = Carbon::now();
            $endDate = Carbon::now()->addYear(); // One year from today

            try {
                Subscription::create([
                    'company_id' => $company->id,
                    'plan_name' => 'Default Annual Subscription - ' . $company->name,
                    'plan_description' => 'Default one-year subscription created by seeder',
                    'amount' => 0,
                    'currency' => 'TZS',
                    'billing_cycle' => 'yearly',
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => 'active',
                    'payment_status' => 'paid',
                    'features' => [
                        'notification_days' => 30 // Default notification days
                    ],
                ]);

                $this->command->info("✓ Created one-year subscription for company: {$company->name} (Expires: {$endDate->format('Y-m-d')})");
                $createdCount++;

            } catch (\Exception $e) {
                $this->command->error("✗ Failed to create subscription for company '{$company->name}': " . $e->getMessage());
            }
        }

        $this->command->info("\n=== Seeder Summary ===");
        $this->command->info("Total companies processed: " . $companies->count());
        $this->command->info("Subscriptions created: {$createdCount}");
        $this->command->info("Subscriptions skipped: {$skippedCount}");
        $this->command->info('Default Subscription Seeder completed!');
    }
}

