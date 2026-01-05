<?php

namespace App\Services;

use App\Models\Journal;
use App\Models\JournalItem;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Repayment;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\GlTransaction;
use App\Models\ChartAccount;
use App\Models\BankAccount;
use App\Helpers\SmsHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class LoanRepaymentService
{
    /**
     * Process loan repayment with different calculation methods
     */
    public function processRepayment($loanId, $amount, $paymentData, $calculationMethod = 'flat_rate')
    {
        DB::beginTransaction();
        $loan = Loan::with(['product', 'customer', 'schedule'])->findOrFail($loanId);
        $remainingAmount = $amount;
        $processedRepayments = [];
        $totalPaidAmount = 0;

        // Get unpaid schedules ordered by due date
        $unpaidSchedules = $this->getUnpaidSchedules($loan);
        Log::info('Unpaid schedules loaded', ['count' => $unpaidSchedules->count()]);

        if ($unpaidSchedules->count() === 0) {
            throw new \Exception('No unpaid schedules found for this loan.');
        }

        foreach ($unpaidSchedules as $schedule) {
            if ($remainingAmount <= 0) {
                Log::info('No remaining amount, breaking loop', ['loanId' => $loanId]);
                break;
            }

            $schedulePayment = $this->processSchedulePayment($loan, $schedule, $remainingAmount, $paymentData);

            if (empty($schedulePayment) || !isset($schedulePayment['amount']) || $schedulePayment['amount'] <= 0) {
                Log::warning('No payment allocated for schedule, breaking loop', ['schedule_id' => $schedule->id]);
                break;
            }

            $remainingAmount -= $schedulePayment['amount'];
            $totalPaidAmount += $schedulePayment['amount'];

            // Create repayment record
            $repayment = $this->createRepaymentRecord($loan, $schedule, $schedulePayment, $paymentData);
            if (!$repayment) {
                Log::error('Failed to create repayment', ['loanId' => $loanId, 'schedule_id' => $schedule->id]);
                throw new \Exception('Repayment not saved');
            }

            // Bank/cash or cash deposit logic
            if (isset($paymentData['bank_account_id']) && $paymentData['bank_account_id']) {
                Log::info('Processing bank/cash repayment', ['bank_account_id' => $paymentData['bank_account_id']]);
                $this->createReceiptAndGL($loan, $repayment, $schedulePayment, $paymentData);
            } elseif (isset($paymentData['cash_deposit_id']) && $paymentData['cash_deposit_id']) {
                Log::info('Processing cash deposit repayment', ['cash_deposit_id' => $paymentData['cash_deposit_id']]);
                $this->createJournalEntry($loan, $repayment, $schedulePayment, $paymentData);
            } else {
                Log::warning('No payment method provided', ['loanId' => $loanId]);
            }

            $processedRepayments[] = $schedulePayment;
        }

        // Check if loan is fully paid and close it automatically
        if ($this->isLoanFullyPaid($loan)) {
            $closed = $loan->closeLoan();
            if ($closed) {
                Log::info('Loan automatically closed after complete repayment', [
                    'loanId' => $loanId,
                    'loanNo' => $loan->loanNo
                ]);
            } else {
                Log::warning('Failed to close loan despite being fully paid', [
                    'loanId' => $loanId,
                    'loanNo' => $loan->loanNo
                ]);
            }
        }

        Log::info('Repayment transaction committed', ['loanId' => $loanId]);
        DB::commit();

        // Refresh loan to get updated outstanding balance
        $loan->refresh();
        $loan->load(['schedule', 'customer', 'company', 'branch.company']);

        // Send SMS notification to customer after successful repayment
        $this->sendRepaymentSms($loan, $totalPaidAmount);

        return [
            'success' => true,
            'paid_amount' => $totalPaidAmount,
            'balance' => $remainingAmount,
            'processed_repayments' => $processedRepayments,
            'loan_status' => $loan->status
        ];
    }

    /**
     * Send SMS notification to customer after repayment
     */
    private function sendRepaymentSms($loan, $amount)
    {
        try {
            // Ensure customer relationship is loaded
            if (!$loan->relationLoaded('customer')) {
                $loan->load('customer');
            }
            
            // Get customer and company information
            $customer = $loan->customer;
            
            Log::info('Attempting to send repayment SMS', [
                'loan_id' => $loan->id,
                'loan_no' => $loan->loanNo ?? null,
                'customer_id' => $customer->id ?? null,
                'customer_name' => $customer->name ?? null,
                'phone1' => $customer->phone1 ?? null,
                'phone1_empty' => empty($customer->phone1 ?? null),
                'amount' => $amount
            ]);
            
            if (!$customer || empty($customer->phone1)) {
                Log::warning('Skipping SMS - customer phone not available', [
                    'loan_id' => $loan->id,
                    'loan_no' => $loan->loanNo ?? null,
                    'customer_id' => $customer->id ?? null,
                    'customer_exists' => $customer ? 'yes' : 'no',
                    'phone1' => $customer->phone1 ?? 'not set',
                    'phone1_empty' => empty($customer->phone1 ?? null)
                ]);
                return;
            }

            // Get company name - try multiple sources for reliability
            $company = null;
            $source = 'none';
            
            // First try: Get company from loan's company relationship
            if ($loan->relationLoaded('company') && $loan->company) {
                $company = $loan->company;
                $source = 'loan_relationship';
            } elseif (isset($loan->company_id) && $loan->company_id) {
                $company = \App\Models\Company::find($loan->company_id);
                $source = 'loan_id';
            }
            
            // Second try: Get company from customer
            if (!$company && $customer) {
                if ($customer->relationLoaded('company') && $customer->company) {
                    $company = $customer->company;
                    $source = 'customer_relationship';
                } elseif (isset($customer->company_id) && $customer->company_id) {
                    $company = \App\Models\Company::find($customer->company_id);
                    $source = 'customer_id';
                }
            }
            
            // Third try: Get company from branch
            if (!$company && $loan->branch_id) {
                if ($loan->relationLoaded('branch') && $loan->branch) {
                    $branch = $loan->branch;
                    if ($branch->relationLoaded('company') && $branch->company) {
                        $company = $branch->company;
                        $source = 'branch_relationship';
                    } elseif (isset($branch->company_id) && $branch->company_id) {
                        $company = \App\Models\Company::find($branch->company_id);
                        $source = 'branch_id';
                    }
                } else {
                    $branch = \App\Models\Branch::find($loan->branch_id);
                    if ($branch && isset($branch->company_id) && $branch->company_id) {
                        $company = \App\Models\Company::find($branch->company_id);
                        $source = 'branch_lookup';
                    }
                }
            }
            
            // Fourth try: Use current_company() as fallback
            if (!$company) {
                $company = current_company();
                $source = 'current_company';
            }
            
            $companyName = $company ? $company->name : 'SMARTFINANCE';
            
            Log::info('Company name resolved for SMS', [
                'loan_id' => $loan->id,
                'company_id' => $company->id ?? null,
                'company_name' => $companyName,
                'source' => $source,
                'loan_company_id' => $loan->company_id ?? null,
                'customer_company_id' => $customer->company_id ?? null,
                'branch_company_id' => ($loan->branch && isset($loan->branch->company_id)) ? $loan->branch->company_id : null
            ]);

            // Get customer name
            $customerName = $customer->name ?? 'Mteja';

            // Format phone number (remove any non-numeric characters except +)
            $phone = preg_replace('/[^0-9+]/', '', $customer->phone1);

            // Calculate remaining/outstanding amount
            $remainingAmount = $loan->getTotalOutstandingAmount();

            // Format message as specified - include remaining amount
            $message = 'Habari! ' . $customerName . ', umelipa rejesho kiasi cha Tsh ' . number_format($amount, 0) . '. Salio: Tsh ' . number_format($remainingAmount, 0) . '. ' . $companyName;

            // Send SMS
            $smsResult = SmsHelper::send($phone, $message);

            if (is_array($smsResult) && ($smsResult['success'] ?? false)) {
                Log::info('Repayment SMS sent successfully', [
                    'loan_id' => $loan->id,
                    'customer_id' => $customer->id,
                    'phone' => $phone,
                    'amount' => $amount
                ]);
            } else {
                Log::warning('Repayment SMS failed', [
                    'loan_id' => $loan->id,
                    'customer_id' => $customer->id,
                    'phone' => $phone,
                    'error' => is_array($smsResult) ? ($smsResult['error'] ?? 'Unknown error') : $smsResult
                ]);
            }
        } catch (\Exception $e) {
            // Log error but don't throw - SMS failure shouldn't break repayment process
            Log::error('Failed to send repayment SMS', [
                'loan_id' => $loan->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    private function getUnpaidSchedules($loan)
    {
        return $loan->schedule()
            ->whereRaw('(
                SELECT COALESCE(SUM(principal), 0) + COALESCE(SUM(interest), 0) + COALESCE(SUM(fee_amount), 0) + COALESCE(SUM(penalt_amount), 0)
                FROM repayments
                WHERE repayments.loan_schedule_id = loan_schedules.id
            ) < (loan_schedules.principal + loan_schedules.interest + loan_schedules.fee_amount + loan_schedules.penalty_amount)')
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Process payment for a single schedule
     */
    private function processSchedulePayment($loan, $schedule, $remainingAmount, $paymentData)
    {
        // Check if payment is made before or on due date and remove penalties if applicable
        $this->checkAndRemovePenaltyForOnTimePayment($schedule, $paymentData);

        // Get already paid amounts for this schedule
        $paidAmounts = $this->getPaidAmountsForSchedule($schedule);

        // Calculate remaining amounts
        $remainingAmounts = [
            'principal' => $schedule->principal - $paidAmounts['principal'],
            'interest' => $schedule->interest - $paidAmounts['interest'],
            'fee_amount' => $schedule->fee_amount - $paidAmounts['fee_amount'],
            'penalty_amount' => $schedule->penalty_amount - $paidAmounts['penalty_amount']
        ];

        // Get repayment order from loan product
        $repaymentOrder = $this->getRepaymentOrder($loan);

        $allocatedAmounts = [
            'principal' => 0,
            'interest' => 0,
            'fee_amount' => 0,
            'penalty_amount' => 0
        ];

        $currentAmount = $remainingAmount;

        // Allocate payment according to repayment order
        foreach ($repaymentOrder as $component) {
            if ($currentAmount <= 0)
                break;

            if (isset($remainingAmounts[$component]) && $remainingAmounts[$component] > 0) {
                $amountToPay = min($currentAmount, $remainingAmounts[$component]);
                $allocatedAmounts[$component] = $amountToPay;
                $currentAmount -= $amountToPay;
            }
        }
        return [
            'schedule_id' => $schedule->id,
            'amount' => $remainingAmount - $currentAmount,
            'principal' => $allocatedAmounts['principal'],
            'interest' => $allocatedAmounts['interest'],
            'fee_amount' => $allocatedAmounts['fee_amount'],
            'penalty_amount' => $allocatedAmounts['penalty_amount']
        ];
    }

    /**
     * Get repayment order from loan product
     */
    private function getRepaymentOrder($loan)
    {
        // Default order if not configured
        $defaultOrder = ['penalty_amount', 'fee_amount', 'interest', 'principal'];

        if ($loan->product && $loan->product->repayment_order) {
            $rawOrder = $loan->product->repayment_order;

            // Normalize to array: accept array, JSON string, or comma-separated string
            if (is_array($rawOrder)) {
                $repaymentComponents = $rawOrder;
            } else if (is_string($rawOrder)) {
                $trimmed = trim($rawOrder);
                if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
                    $decoded = json_decode($trimmed, true);
                    $repaymentComponents = is_array($decoded) ? $decoded : explode(',', $rawOrder);
                } else {
                    $repaymentComponents = explode(',', $rawOrder);
                }
            } else {
                $repaymentComponents = [];
            }

            $validComponents = [];

            // Map the components to the correct field names
            foreach ($repaymentComponents as $component) {
                $component = is_string($component) ? trim($component) : $component;
                switch ($component) {
                    case 'penalties':
                    case 'penalty':
                    case 'penalty_amount':
                        $validComponents[] = 'penalty_amount';
                        break;
                    case 'fees':
                    case 'fee':
                    case 'fee_amount':
                        $validComponents[] = 'fee_amount';
                        break;
                    case 'interest':
                        $validComponents[] = 'interest';
                        break;
                    case 'principal':
                        $validComponents[] = 'principal';
                        break;
                }
            }

            return !empty($validComponents) ? $validComponents : $defaultOrder;
        }

        return $defaultOrder;
    }

    /**
     * Get paid amounts for a schedule
     */
    private function getPaidAmountsForSchedule($schedule)
    {
        $repayments = $schedule->repayments;

        return [
            'principal' => $repayments->sum('principal'),
            'interest' => $repayments->sum('interest'),
            'fee_amount' => $repayments->sum('fee_amount'),
            'penalty_amount' => $repayments->sum('penalt_amount')
        ];
    }

    /**
     * Create repayment record
     */
    private function createRepaymentRecord($loan, $schedule, $schedulePayment, $paymentData)
    {
        $repaymentData = [
            'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id,
            'loan_schedule_id' => $schedule->id,
            'bank_account_id' => $paymentData['bank_chart_account_id'],
            'payment_date' => $paymentData['payment_date'] ?? now(),
            'due_date' => $schedule->due_date,
            'principal' => $schedulePayment['principal'],
            'interest' => $schedulePayment['interest'],
            'fee_amount' => $schedulePayment['fee_amount'],
            'penalt_amount' => $schedulePayment['penalty_amount'],
            'cash_deposit' => $schedulePayment['amount'],
        ];

        Log::info('Creating repayment record', $repaymentData);

        try {
            $repayment = Repayment::create($repaymentData);
            Log::info('Repayment created successfully', ['id' => $repayment->id]);
            return $repayment;
        } catch (\Exception $e) {
            Log::error('Failed to create repayment record: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create receipt and GL transactions
     */
    private function createReceiptAndGL($loan, $repayment, $schedulePayment, $paymentData)
    {

        // check if the interest  receivable has been posted first, if not, do not create the interest receivable
        // credit interest income and debit interest receivable,
        // Log after receipt is created
        // Log::info('Starting createReceiptAndGL', [
        //     'loan_id' => $loan->id,
        //     'repayment_id' => $repayment->id,
        //     'schedulePayment' => $schedulePayment,
        //     'bank_account_id' => $receipt->bank_account_id,
        //     'receipt_id' => $receipt->id
        // ]);
        // Only create receipt if payment source is not cash deposit
        if (isset($paymentData['payment_source']) && $paymentData['payment_source'] === 'cash_deposit') {
            $this->createJournalEntry($loan, $repayment, $schedulePayment, $paymentData);
            return;
        }

        // Create receipt for bank payment
        $receipt = Receipt::create([
            'reference' => $repayment->id,
            'reference_type' => 'loan_repayment',
            'reference_number' => null,
            'amount' => $schedulePayment['amount'],
            'date' => $paymentData['payment_date'] ?? now(),
            'description' => "Loan repayment for {$loan->customer->name} - Loan #{$loan->id}",
            'user_id' => auth()->id(),
            'bank_account_id' => $paymentData['bank_account_id'] ?? $loan->bank_account_id,
            'payee_type' => 'customer',
            'payee_id' => $loan->customer_id,
            'payee_name' => $loan->customer->name,
            'branch_id' => auth()->user()->branch_id ?? 1,
            'approved' => true,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
        // Logging is only allowed inside function blocks after variable definitions
        Log::info('Starting createReceiptAndGL', [
            'loan_id' => $loan->id,
            'repayment_id' => $repayment->id,
            'schedulePayment' => $schedulePayment,
            'bank_account_id' => $receipt->bank_account_id,
            'receipt_id' => $receipt->id
        ]);

        // Get chart accounts for components and log them

        // Handle fee chart account by exploding fee_ids and fetching from fees table
        $feeAccountId = null;
        Log::info('fees_ids existence check', [
            'isset' => isset($loan->product->fees_ids),
            'value' => $loan->product->fees_ids ?? null
        ]);
        if (isset($loan->product->fees_ids)) {
            $feeIds = is_array($loan->product->fees_ids) ? $loan->product->fees_ids : json_decode($loan->product->fees_ids, true);
            Log::info('Processing fees_ids', ['fees_ids' => $feeIds]);
            if (is_array($feeIds)) {
                foreach ($feeIds as $feeId) {
                    // Fetch fee from DB
                    $fee = \DB::table('fees')->where('id', $feeId)->first();
                    Log::info('Fetched fee for fee_id', ['fee_id' => $feeId, 'fee' => $fee]);
                    if ($fee && $fee->include_in_schedule == 1 && $fee->chart_account_id) {
                        $feeAccountId = $fee->chart_account_id;
                        break;
                    }
                }
            }
        }
        // Fallback to fee_income_account_id if no valid fee found
        if (!$feeAccountId) {
            $feeAccountId = null;
        }
        // Handle penalty chart account by exploding penalty_ids and fetching from penalties table
        $penaltyAccountId = null;
        if (isset($loan->product->penalty_ids)) {
            $penaltyIds = is_array($loan->product->penalty_ids) ? $loan->product->penalty_ids : json_decode($loan->product->penalty_ids, true);
            Log::info('Processing penalty_ids', ['penalty_ids' => $penaltyIds]);
            if (is_array($penaltyIds)) {
                foreach ($penaltyIds as $penaltyId) {
                    // Fetch penalty from DB
                    $penalty = \DB::table('penalties')->where('id', $penaltyId)->first();
                    Log::info('Fetched penalty for penalty_id', ['penalty_id' => $penaltyId, 'penalty' => $penalty]);
                    if ($penalty && $penalty->penalty_receivables_account_id) {
                        $penaltyAccountId = $penalty->penalty_receivables_account_id;
                        break;
                    }
                }
            }
        }

        $chartAccounts = [
            'principal' => $loan->product->principal_receivable_account_id ?? null,
            'interest' => $loan->product->interest_revenue_account_id ?? null,
            'fee_amount' => $feeAccountId,
            'penalty_amount' => $penaltyAccountId ?? null
        ];
        Log::info('GL Chart Accounts for Receipt', $chartAccounts);

        $components = [
            'principal' => $schedulePayment['principal'],
            'interest' => $schedulePayment['interest'],
            'fee_amount' => $schedulePayment['fee_amount'],
            'penalty_amount' => $schedulePayment['penalty_amount']
        ];
        $bankAccount = BankAccount::find($receipt->bank_account_id);
        $bankAccountChartAccount = $bankAccount->chart_account_id;

        info("bank account chart account", ['bank_account_id' => $bankAccount->id, 'chart_account_id' => $bankAccountChartAccount]);
        Log::info('GL Component Amounts for Receipt', $components);

        // Debit: Bank/cash account (total amount)
        Log::info('GL Debit Posting', [
            'chart_account_id' => $receipt->bank_account_id,
            'amount' => $schedulePayment['amount'],
            'customer_id' => $loan->customer_id,
            'receipt_id' => $receipt->id
        ]);
        GlTransaction::create([
            'chart_account_id' => $bankAccountChartAccount,
            'customer_id' => $loan->customer_id,
            'amount' => $schedulePayment['amount'],
            'nature' => 'debit',
            'transaction_id' => $receipt->id,
            'transaction_type' => 'receipt',
            'date' => $receipt->date,
            'description' => "Loan repayment received - {$loan->customer->name}",
            'branch_id' => $receipt->branch_id,
            'user_id' => auth()->id(),
        ]);

        // check if the interest receivable has been posted first, if not, do not create the interest receivable by debiting  and credit interest income
        $receivableId = $loan->product->interest_receivable_account_id;
        $incomeId = $loan->product->interest_revenue_account_id;

        if (!$receivableId) {
            Log::warning("Missing interest accounts for product {$loan->product->id}");
            return 0;
        }

        $exists = GlTransaction::where('chart_account_id', $receivableId)
            ->where('customer_id', $loan->customer_id)
            ->where('date', $repayment->due_date)
            ->where('amount', $schedulePayment['interest'])
            ->where('transaction_type', 'Mature Interest')
            ->exists();
        if (!$incomeId) {
            Log::warning("Missing interest income account for product {$loan->product->id}");
            return 0;
        }

        $incomeExists = GlTransaction::where('chart_account_id', $incomeId)
            ->where('customer_id', $loan->customer_id)
            ->where('date', $repayment->due_date)
            ->where('amount', $schedulePayment['interest'])
            ->where('transaction_type', 'Mature Interest')
            ->exists();

        if ($exists && $incomeExists) {
            Log::info('Interest receivable and interest income have been posted ovewtite the array chartAccont interest to be receivable instead of icome');
            $chartAccounts['interest'] = $receivableId;
        }

        // Credit: Each component to its respective account
        foreach ($components as $component => $amount) {
            $accountId = $chartAccounts[$component] ?? null;
            if ($amount > 0 && $accountId) {
                Log::info('GL Credit Posting', [
                    'component' => $component,
                    'chart_account_id' => $accountId,
                    'amount' => $amount,
                    'customer_id' => $loan->customer_id,
                    'receipt_id' => $receipt->id
                ]);
                ReceiptItem::create([
                    'receipt_id' => $receipt->id,
                    'chart_account_id' => $accountId,
                    'amount' => $amount,
                    'description' => ucfirst($component) . " payment for loan #{$loan->id}"
                ]);
                GlTransaction::create([
                    'chart_account_id' => $accountId,
                    'customer_id' => $loan->customer_id,
                    'amount' => $amount,
                    'nature' => 'credit',
                    'transaction_id' => $receipt->id,
                    'transaction_type' => 'receipt',
                    'date' => $receipt->date,
                    'description' => ucfirst($component) . " payment for loan #{$loan->id}",
                    'branch_id' => $receipt->branch_id,
                    'user_id' => auth()->id(),
                ]);
            } else if ($amount > 0 && !$accountId) {
                Log::error('Missing chart account for GL component', [
                    'component' => $component,
                    'amount' => $amount,
                    'loan_id' => $loan->id,
                    'receipt_id' => $receipt->id
                ]);
            }
        }
    }

    /**
     * Create journal entry for cash deposit payments
     */
    private function createJournalEntry($loan, $repayment, $schedulePayment, $paymentData)
    {
        // Log::info('createJournalEntry called', [
        //     'loan_id' => $loan->id,
        //     'repayment_id' => $repayment->id ?? null,
        //     'schedulePayment' => $schedulePayment,
        //     'cash_deposit_id' => $paymentData['cash_deposit_id'] ?? null,
        //     'cash_deposit_before' => $cashDeposit->amount,
        // ]);
        // Get cash deposit account
        $cashDeposit = \App\Models\CashCollateral::findOrFail($paymentData['cash_deposit_id']);
        // Reduce cash deposit balance
        $cashDeposit->decrement('amount', $schedulePayment['amount']);
        Log::info('Cash collateral decremented', [
            'cash_deposit_id' => $cashDeposit->id,
            'cash_deposit_after' => $cashDeposit->amount,
        ]);

        // Create journal record for withdrawal from cash deposit
        $journal = Journal::create([
            'reference' => $repayment->id,
            'reference_type' => 'Withdrawal',
            'customer_id' => $loan->customer_id,
            'description' => "Loan repayment from cash deposit for {$loan->customer->name} - Loan #{$loan->id}",
            'branch_id' => auth()->user()->branch_id ?? 1,
            'user_id' => auth()->id(),
            'date' => $paymentData['payment_date'] ?? now(),
        ]);
        Log::info('Journal created', ['journal_id' => $journal->id]);

        // Debit: Cash collateral account (total amount)
        JournalItem::create([
            'journal_id' => $journal->id,
            'chart_account_id' => $cashDeposit->type->chart_account_id ?? 1,
            'amount' => $schedulePayment['amount'],
            'description' => "Loan repayment from cash deposit",
            'nature' => 'debit',
        ]);
        Log::info('JournalItem debit created', ['journal_id' => $journal->id, 'amount' => $schedulePayment['amount']]);

        // Always credit all components, not only principal
        $chartAccounts = [
            'principal' => $loan->product->principal_receivable_account_id ?? null,
            'interest' => $loan->product->interest_revenue_account_id ?? null,
            'fee_amount' => $loan->product->fee_income_account_id ?? null,
            'penalty_amount' => $loan->product->penalty_receivables_account_id ?? null
        ];

        Log::info('chart accounts', $chartAccounts);

        $components = [
            'principal' => $schedulePayment['principal'],
            'interest' => $schedulePayment['interest'],
            'fee_amount' => $schedulePayment['fee_amount'],
            'penalty_amount' => $schedulePayment['penalty_amount']
        ];
        info("components amounts", $components);

        // check if the interest receivable has been posted first, if not, do not create the interest receivable by debiting  and credit interest income
        $receivableId = $loan->product->interest_receivable_account_id;
        $incomeId = $loan->product->interest_revenue_account_id;

        Log::info("Interest accounts for product {$loan->product->id}", [
            'receivable_id' => $receivableId,
            'income_id' => $incomeId,
        ]);

        if (!$receivableId) {
            Log::warning("Missing interest accounts for product {$loan->product->id}");
            return 0;
        }

        $exists = GlTransaction::where('chart_account_id', $receivableId)
            ->where('customer_id', $loan->customer_id)
            ->where('date', $repayment->due_date)
            ->where('amount', $schedulePayment['interest'])
            ->where('transaction_type', 'Mature Interest')
            ->exists();

        Log::info("Interest accounts for product {$loan->product->id}", [
            'exists' => $exists,
        ]);

        if (!$incomeId) {
            Log::warning("Missing interest income account for product {$loan->product->id}");
            return 0;
        }
        Log::info('income account', [$incomeId]);

        $incomeExists = GlTransaction::where('chart_account_id', $incomeId)
            ->where('customer_id', $loan->customer_id)
            ->where('date', $repayment->due_date)
            ->where('amount', $schedulePayment['interest'])
            ->where('transaction_type', 'Mature Interest')
            ->exists();

        Log::info("Interest accounts for product {$loan->product->id}", [
            'exists' => $incomeExists,
        ]);


        if ($exists && $incomeExists) {
            Log::info('Interest receivable and interest income have been posted ovewtite the array chartAccont interest to be receivable instead of icome');
            $chartAccounts['interest'] = $receivableId;
        }

        foreach ($components as $component => $amount) {
            if ($amount > 0 && !empty($chartAccounts[$component])) {
                JournalItem::create([
                    'journal_id' => $journal->id,
                    'chart_account_id' => $chartAccounts[$component],
                    'amount' => $amount,
                    'description' => ucfirst($component) . " repayment for loan #{$loan->id}",
                    'nature' => 'credit',
                ]);
                GlTransaction::create([
                    'chart_account_id' => $chartAccounts[$component],
                    'customer_id' => $loan->customer_id,
                    'amount' => $amount,
                    'nature' => 'credit',
                    'transaction_id' => $journal->id,
                    'transaction_type' => 'journal repayment',
                    'date' => $journal->date,
                    'description' => ucfirst($component) . " repayment from cash deposit - Loan #{$loan->id}",
                    'branch_id' => $journal->branch_id,
                    'user_id' => $journal->user_id,
                ]);
            }
        }

        // Debit: Cash collateral account (total amount)
        JournalItem::create([
            'journal_id' => $journal->id,
            'chart_account_id' => $cashDeposit->type->chart_account_id ?? 1,
            'amount' => $schedulePayment['amount'],
            'description' => "Loan repayment from cash deposit",
            'nature' => 'debit',
        ]);
        GlTransaction::create([
            'chart_account_id' => $cashDeposit->type->chart_account_id ?? 1,
            'customer_id' => $loan->customer_id,
            'amount' => $schedulePayment['amount'],
            'nature' => 'debit',
            'transaction_id' => $journal->id,
            'transaction_type' => 'journal repayment',
            'date' => $journal->date,
            'description' => "Loan repayment from cash deposit - Loan #{$loan->id}",
            'branch_id' => $journal->branch_id,
            'user_id' => $journal->user_id,
        ]);
    }

    /**
     * Get chart accounts for loan components
     */
    private function getChartAccounts($loan)
    {
        // Use chart accounts from loan product
        $chartAccounts = [];

        if ($loan->product) {
            $chartAccounts = [
                'principal' => $loan->product->principal_receivable_account_id,
                'interest' => $loan->product->interest_receivable_account_id,
                'fee_amount' => $loan->product->fee ? $loan->product->fee->chart_account_id : null, // Use interest account for fees
                'penalty_amount' => $loan->product->penalty ? $loan->product->penalty->penalty_receivables_account_id : null // Use interest account for penalties
            ];
        }

        return $chartAccounts;
    }

    /**
     * Check if loan is fully paid using the same logic as closeLoan method
     */
    private function isLoanFullyPaid($loan)
    {
        // Use the same logic as the Loan model's isEligibleForClosing method
        return $loan->isEligibleForClosing();
    }

    /**
     * Remove penalty from schedule (for pardon functionality)
     */
    public function removePenalty($scheduleId, $reason = null, $amount = null, $loanId = null)
    {
        DB::beginTransaction();

        try {
            $schedule = LoanSchedule::findOrFail($scheduleId);

            // Get the current penalty amount before removing it
            $currentPenaltyAmount = $schedule->penalty_amount;

            // Get the total penalty paid amount
            $penaltyPaidAmount = $schedule->repayments ? $schedule->repayments->sum('penalt_amount') : 0;

            // Check if penalty removal is allowed (only if paid amount is less than penalty amount)
            if ($penaltyPaidAmount >= $currentPenaltyAmount) {
                throw new \Exception("Penalty removal not allowed. Penalty amount ({$currentPenaltyAmount}) has been fully or partially paid ({$penaltyPaidAmount}).");
            }

            Log::info("Reducing penalty for schedule ID: {$scheduleId}, current penalty amount: {$currentPenaltyAmount}, penalty paid: {$penaltyPaidAmount}", [
                'schedule_id' => $scheduleId,
                'customer_id' => $schedule->customer_id,
                'penalty_amount' => $currentPenaltyAmount,
                'penalty_paid' => $penaltyPaidAmount,
                'reason' => $reason,
                'remove_amount' => $amount,
                'loan_id' => $loanId
            ]);

            // Subtract the penalty amount from GL transactions for this loan
            $updatedCount = GlTransaction::where('transaction_id', $loanId)
                ->whereIn('transaction_type', ['Penalty', 'penalty', 'Loan Penalty'])
                ->where('amount', '>', 0)
                ->update([
                    'amount' => DB::raw('amount - ' . floatval($amount))
                ]);

            Log::info("Subtracted penalty amount ({$amount}) from {$updatedCount} GL transactions for loan ID: {$loanId}");

            // Reduce the schedule penalty by the entered amount (not below zero)
            $newPenaltyAmount = max($currentPenaltyAmount - floatval($amount), 0);
            $schedule->update([
                'penalty_amount' => $newPenaltyAmount,
            ]);

            DB::commit();

            return [
                'success' => true,
                'message' => "Penalty removed successfully from schedule and subtracted amount from {$updatedCount} GL transactions"
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to remove penalty for schedule ID: {$scheduleId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Check if payment is made before or on due date and remove penalties if applicable
     */
    private function checkAndRemovePenaltyForOnTimePayment($schedule, $paymentData)
    {
        try {
            // Get the payment date (use provided date or current date)
            $paymentDate = isset($paymentData['payment_date'])
                ? Carbon::parse($paymentData['payment_date'])
                : Carbon::today();

            // Get the schedule due date
            $dueDate = Carbon::parse($schedule->due_date);

            // Check if payment is made before or on the due date
            if ($paymentDate->lte($dueDate) && $schedule->penalty_amount > 0) {
                Log::info("Payment made on/before due date. Removing penalty for schedule {$schedule->id}", [
                    'schedule_id' => $schedule->id,
                    'payment_date' => $paymentDate->format('Y-m-d'),
                    'due_date' => $dueDate->format('Y-m-d'),
                    'penalty_amount' => $schedule->penalty_amount,
                    'customer_id' => $schedule->customer_id
                ]);


                // Remove penalty from schedule and GL transactions with all required parameters
                $this->removePenalty(
                    $schedule->id,
                    'Paid earlier or on due date',
                    $schedule->penalty_amount,
                    $schedule->loan_id
                );

                // Refresh the schedule model to get updated penalty_amount
                $schedule->refresh();

                Log::info("Penalty successfully removed for on-time payment on schedule {$schedule->id}");
            }
        } catch (\Exception $e) {
            // Log the error but don't stop the payment process
            Log::error("Failed to check/remove penalty for on-time payment on schedule {$schedule->id}", [
                'error' => $e->getMessage(),
                'schedule_id' => $schedule->id
            ]);
        }
    }

    /**
     * Calculate loan schedule using different methods
     */
    public function calculateSchedule($loan, $method = 'flat_rate')
    {
        switch ($method) {
            case 'flat_rate':
                return $this->calculateFlatRateSchedule($loan);
            case 'reducing_equal_installment':
                return $this->calculateReducingEqualInstallmentSchedule($loan);
            case 'reducing_equal_principal':
                return $this->calculateReducingEqualPrincipalSchedule($loan);
            default:
                throw new \Exception('Invalid calculation method');
        }
    }

    /**
     * Calculate flat rate schedule
     */
    private function calculateFlatRateSchedule($loan)
    {
        $principal = $loan->amount;
        $interestRate = $loan->interest / 100;
        $period = $loan->period;

        // Flat rate calculation
        $totalInterest = $principal * $interestRate * $period;
        $totalAmount = $principal + $totalInterest;
        $monthlyInstallment = $totalAmount / $period;
        $monthlyInterest = $totalInterest / $period;
        $monthlyPrincipal = $principal / $period;

        $schedules = [];
        $currentDate = Carbon::parse($loan->disbursed_on)->addMonth();

        for ($i = 1; $i <= $period; $i++) {
            $schedules[] = [
                'installment_no' => $i,
                'due_date' => $currentDate->format('Y-m-d'),
                'principal' => $monthlyPrincipal,
                'interest' => $monthlyInterest,
                'fee_amount' => 0,
                'penalty_amount' => 0,
                'total_installment' => $monthlyInstallment
            ];

            $currentDate->addMonth();
        }

        return $schedules;
    }

    /**
     * Calculate reducing balance with equal installments
     */
    private function calculateReducingEqualInstallmentSchedule($loan)
    {
        $principal = $loan->amount;
        $interestRate = $loan->interest / 100 / 12; // Monthly rate
        $period = $loan->period;

        // Calculate equal monthly installment
        $monthlyInstallment = $principal * ($interestRate * pow(1 + $interestRate, $period)) / (pow(1 + $interestRate, $period) - 1);

        $schedules = [];
        $currentDate = Carbon::parse($loan->disbursed_on)->addMonth();
        $remainingPrincipal = $principal;

        for ($i = 1; $i <= $period; $i++) {
            $monthlyInterest = $remainingPrincipal * $interestRate;
            $monthlyPrincipal = $monthlyInstallment - $monthlyInterest;

            // Adjust for last payment
            if ($i == $period) {
                $monthlyPrincipal = $remainingPrincipal;
                $monthlyInstallment = $monthlyPrincipal + $monthlyInterest;
            }

            $schedules[] = [
                'installment_no' => $i,
                'due_date' => $currentDate->format('Y-m-d'),
                'principal' => $monthlyPrincipal,
                'interest' => $monthlyInterest,
                'fee_amount' => 0,
                'penalty_amount' => 0,
                'total_installment' => $monthlyInstallment
            ];

            $remainingPrincipal -= $monthlyPrincipal;
            $currentDate->addMonth();
        }

        return $schedules;
    }

    /**
     * Calculate reducing balance with equal principal
     */
    private function calculateReducingEqualPrincipalSchedule($loan)
    {
        $principal = $loan->amount;
        $interestRate = $loan->interest / 100 / 12; // Monthly rate
        $period = $loan->period;

        $monthlyPrincipal = $principal / $period;

        $schedules = [];
        $currentDate = Carbon::parse($loan->disbursed_on)->addMonth();
        $remainingPrincipal = $principal;

        for ($i = 1; $i <= $period; $i++) {
            $monthlyInterest = $remainingPrincipal * $interestRate;
            $monthlyInstallment = $monthlyPrincipal + $monthlyInterest;

            $schedules[] = [
                'installment_no' => $i,
                'due_date' => $currentDate->format('Y-m-d'),
                'principal' => $monthlyPrincipal,
                'interest' => $monthlyInterest,
                'fee_amount' => 0,
                'penalty_amount' => 0,
                'total_installment' => $monthlyInstallment
            ];

            $remainingPrincipal -= $monthlyPrincipal;
            $currentDate->addMonth();
        }

        return $schedules;
    }

    /**
     * Create journal entries for cash deposit payments
     * DR: Cash Deposit Account (reducing balance)
     * CR: Principal/Interest/Penalty/Fee Accounts
     */
    private function createCashDepositJournalEntries($payment, $loan, $schedulePayment, $repayment, $cashDeposit)
    {
        // Get chart accounts from loan product or use defaults
        $principalAccount = ChartAccount::find($loan->product->principal_gl_account_id ?? 1);
        $interestAccount = ChartAccount::find($loan->product->interest_gl_account_id ?? 2);
        $penaltyAccount = ChartAccount::find($loan->product->penalty_gl_account_id ?? 3);
        $feeAccount = ChartAccount::find($loan->product->fee_gl_account_id ?? 4);
        $cashDepositAccount = ChartAccount::find($cashDeposit->type->chart_account_id ?? 5);

        $journalRef = 'LOAN-REPAY-CD-' . $loan->id . '-' . time();

        // Create payment items for tracking
        if ($schedulePayment['principal'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $principalAccount->id,
                'description' => 'Principal payment from cash deposit',
                'amount' => $schedulePayment['principal'],
            ]);

            // DR: Cash Deposit Account (reducing the deposit)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => $schedulePayment['principal'],
                'credit' => 0,
                'description' => "Cash deposit withdrawal for principal payment - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            // CR: Principal Account (loan repayment)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $principalAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['principal'],
                'description' => "Principal payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }

        if ($schedulePayment['interest'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $interestAccount->id,
                'description' => 'Interest payment from cash deposit',
                'amount' => $schedulePayment['interest'],
            ]);

            // DR: Cash Deposit Account (reducing the deposit)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => $schedulePayment['interest'],
                'credit' => 0,
                'description' => "Cash deposit withdrawal for interest payment - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            // CR: Interest Account (interest income)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $interestAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['interest'],
                'description' => "Interest payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }

        if ($schedulePayment['penalty'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $penaltyAccount->id,
                'description' => 'Penalty payment from cash deposit',
                'amount' => $schedulePayment['penalty'],
            ]);

            // DR: Cash Deposit Account (reducing the deposit)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => $schedulePayment['penalty'],
                'credit' => 0,
                'description' => "Cash deposit withdrawal for penalty payment - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            // CR: Penalty Account (penalty income)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $penaltyAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['penalty'],
                'description' => "Penalty payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }

        if ($schedulePayment['fee'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $feeAccount->id,
                'description' => 'Fee payment from cash deposit',
                'amount' => $schedulePayment['fee'],
            ]);

            // DR: Cash Deposit Account (reducing the deposit)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => $schedulePayment['fee'],
                'credit' => 0,
                'description' => "Cash deposit withdrawal for fee payment - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            // CR: Fee Account (fee income)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $feeAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['fee'],
                'description' => "Fee payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }

        if ($schedulePayment['interest'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $interestAccount->id,
                'description' => 'Interest payment from cash deposit',
                'amount' => $schedulePayment['interest'],
            ]);

            // DR: Interest Account, CR: Cash Deposit Account
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $interestAccount->id,
                'debit' => $schedulePayment['interest'],
                'credit' => 0,
                'description' => "Interest payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['interest'],
                'description' => "Interest payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }

        if ($schedulePayment['penalty'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $penaltyAccount->id,
                'description' => 'Penalty payment from cash deposit',
                'amount' => $schedulePayment['penalty'],
            ]);

            // DR: Penalty Account, CR: Cash Deposit Account
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $penaltyAccount->id,
                'debit' => $schedulePayment['penalty'],
                'credit' => 0,
                'description' => "Penalty payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['penalty'],
                'description' => "Penalty payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }

        if ($schedulePayment['fee'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $feeAccount->id,
                'description' => 'Fee payment from cash deposit',
                'amount' => $schedulePayment['fee'],
            ]);

            // DR: Fee Account, CR: Cash Deposit Account
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $feeAccount->id,
                'debit' => $schedulePayment['fee'],
                'credit' => 0,
                'description' => "Fee payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['fee'],
                'description' => "Fee payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }
    }

    /**
     * Process settle repayment - pays current interest and all remaining principal
     *
     * @param int $loanId The loan ID
     * @param float $amount The settle amount to be paid
     * @param array $paymentData Payment data including bank account, payment date, etc.
     * @return array Result of the settlement
     */
    public function processSettleRepayment($loanId, float $amount, array $paymentData): array
    {
        DB::beginTransaction();

        try {
            $loan = Loan::with(['product', 'customer', 'schedule.repayments'])->findOrFail($loanId);

            Log::info('Processing settle repayment', [
                'loan_id' => $loanId,
                'loan_status' => $loan->status,
                'schedule_count' => $loan->schedule ? $loan->schedule->count() : 0,
                'amount' => $amount
            ]);

            // Handle cash deposit balance reduction if using cash deposit
            if (isset($paymentData['payment_source']) && $paymentData['payment_source'] === 'cash_deposit') {
                $cashDeposit = \App\Models\CashCollateral::findOrFail($paymentData['cash_deposit_id']);
                $cashDeposit->decrement('amount', $amount);
                Log::info('Cash collateral decremented for settle repayment', [
                    'cash_deposit_id' => $cashDeposit->id,
                    'amount_decremented' => $amount,
                    'remaining_balance' => $cashDeposit->amount,
                ]);
            }

            // Get current unpaid/partially paid schedule
            // Ensure schedule is loaded as a collection
            $schedules = $loan->schedule;
            // If schedule relationship returns null or is not a collection, try to load it
            if (!$schedules) {
                $schedules = $loan->schedule()->get();
            }
            // Ensure it's a collection and not empty
            if (!$schedules || !($schedules instanceof \Illuminate\Database\Eloquent\Collection) || $schedules->isEmpty()) {
                throw new \Exception('No loan schedules found for settlement');
            }

            // Use filter() instead of where() for accessor-based filtering
            $currentSchedule = $schedules->filter(function ($schedule) {
                return !$schedule->is_fully_paid;
            })->first();

            if (!$currentSchedule) {
                throw new \Exception('No unpaid schedule found for settlement');
            }

            // Calculate current interest (remaining interest from current schedule)
            // Ensure repayments relationship is loaded
            $repayments = $currentSchedule->repayments ?? collect();
            $interestPaid = $repayments->sum('interest');
            $currentInterest = max(0, $currentSchedule->interest - $interestPaid);

            // Calculate total outstanding principal from all schedules
            $totalPrincipal = $schedules->sum('principal');
            $totalPaidPrincipal = $schedules->sum(function ($schedule) {
                $scheduleRepayments = $schedule->repayments ?? collect();
                return $scheduleRepayments->sum('principal');
            });
            $outstandingPrincipal = $totalPrincipal - $totalPaidPrincipal;

            // Validate settle amount
            $expectedSettleAmount = $currentInterest + $outstandingPrincipal;
            if (abs($amount - $expectedSettleAmount) > 0.01) {
                throw new \Exception("Settle amount mismatch. Expected: {$expectedSettleAmount}, Provided: {$amount}");
            }

            // Create repayment record for current schedule (interest only)
            if ($currentInterest > 0) {
                $currentRepayment = Repayment::create([
                    'customer_id' => $loan->customer_id,
                    'loan_id' => $loan->id,
                    'loan_schedule_id' => $currentSchedule->id,
                    'bank_account_id' => $paymentData['bank_chart_account_id'] ?? null,
                    'payment_date' => $paymentData['payment_date'] ?? now(),
                    'due_date' => $currentSchedule->due_date,
                    'principal' => 0,
                    'interest' => $currentInterest,
                    'fee_amount' => 0,
                    'penalt_amount' => 0,
                    'cash_deposit' => $currentInterest,
                ]);

                // Create GL transactions for current interest based on payment source
                if (isset($paymentData['payment_source']) && $paymentData['payment_source'] === 'cash_deposit') {
                    $this->createSettleInterestGLFromCashDeposit($loan, $currentRepayment, $currentInterest, $paymentData);
                } else {
                    $this->createSettleInterestGL($loan, $currentRepayment, $currentInterest, $paymentData);
                }
            }

            // Create repayment records for all remaining principal across all schedules
            $remainingAmount = $amount - $currentInterest;
            $processedSchedules = [];

            // Ensure we're iterating over a valid collection
            if (!$schedules || !($schedules instanceof \Illuminate\Database\Eloquent\Collection)) {
                throw new \Exception('Invalid schedule collection for settlement');
            }

            foreach ($schedules as $schedule) {
                if ($remainingAmount <= 0)
                    break;

                $scheduleRepayments = $schedule->repayments ?? collect();
                $principalPaid = $scheduleRepayments->sum('principal');
                $remainingPrincipal = $schedule->principal - $principalPaid;

                if ($remainingPrincipal > 0) {
                    $principalToPay = min($remainingAmount, $remainingPrincipal);

                    $principalRepayment = Repayment::create([
                        'customer_id' => $loan->customer_id,
                        'loan_id' => $loan->id,
                        'loan_schedule_id' => $schedule->id,
                        'bank_account_id' => $paymentData['bank_chart_account_id'] ?? null,
                        'payment_date' => $paymentData['payment_date'] ?? now(),
                        'due_date' => $schedule->due_date,
                        'principal' => $principalToPay,
                        'interest' => 0,
                        'fee_amount' => 0,
                        'penalt_amount' => 0,
                        'cash_deposit' => $principalToPay,
                    ]);

                    // Create GL transactions for principal based on payment source
                    if (isset($paymentData['payment_source']) && $paymentData['payment_source'] === 'cash_deposit') {
                        $this->createSettlePrincipalGLFromCashDeposit($loan, $principalRepayment, $principalToPay, $paymentData);
                    } else {
                        $this->createSettlePrincipalGL($loan, $principalRepayment, $principalToPay, $paymentData);
                    }

                    $remainingAmount -= $principalToPay;
                    $processedSchedules[] = [
                        'schedule_id' => $schedule->id,
                        'principal_paid' => $principalToPay
                    ];
                }
            }

            // Check if loan should be closed
            $shouldClose = $loan->isLoanFullyPaidForSettlement();
            if ($shouldClose) {
                $loan->status = Loan::STATUS_COMPLETE;
                $loan->save();
            }

            DB::commit();

            // Refresh loan to get updated outstanding balance
            $loan->refresh();
            $loan->load(['schedule', 'customer', 'company', 'branch.company']);

            // Send SMS notification to customer after successful settlement
            $this->sendRepaymentSms($loan, $amount);

            return [
                'success' => true,
                'message' => 'Loan settled successfully',
                'current_interest_paid' => $currentInterest,
                'total_principal_paid' => $amount - $currentInterest,
                'processed_schedules' => $processedSchedules,
                'loan_closed' => $shouldClose
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Settle repayment failed', [
                'loan_id' => $loanId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create GL transactions for settle interest payment
     */
    private function createSettleInterestGL(Loan $loan, Repayment $repayment, float $interestAmount, array $paymentData)
    {
        // Debit: Bank/Cash account
        GlTransaction::create([
            'chart_account_id' => $paymentData['bank_chart_account_id'],
            'customer_id' => $loan->customer_id,
            'amount' => $interestAmount,
            'nature' => 'debit',
            'transaction_id' => $repayment->id,
            'transaction_type' => 'Settle Interest',
            'date' => $repayment->payment_date,
            'description' => "Settle interest payment for loan {$loan->loanNo}",
            'branch_id' => $loan->branch_id,
            'user_id' => auth()->id(),
        ]);

        // Credit: Interest receivable or revenue account
        $interestAccountId = $loan->product->interest_receivable_account_id ?? $loan->product->interest_revenue_account_id;
        if ($interestAccountId) {
            GlTransaction::create([
                'chart_account_id' => $interestAccountId,
                'customer_id' => $loan->customer_id,
                'amount' => $interestAmount,
                'nature' => 'credit',
                'transaction_id' => $repayment->id,
                'transaction_type' => 'Settle Interest',
                'date' => $repayment->payment_date,
                'description' => "Settle interest payment for loan {$loan->loanNo}",
                'branch_id' => $loan->branch_id,
                'user_id' => auth()->id(),
            ]);
        }
    }

    /**
     * Create GL transactions for settle principal payment
     */
    private function createSettlePrincipalGL(Loan $loan, Repayment $repayment, float $principalAmount, array $paymentData)
    {
        // Debit: Bank/Cash account
        GlTransaction::create([
            'chart_account_id' => $paymentData['bank_chart_account_id'],
            'customer_id' => $loan->customer_id,
            'amount' => $principalAmount,
            'nature' => 'debit',
            'transaction_id' => $repayment->id,
            'transaction_type' => 'Settle Principal',
            'date' => $repayment->payment_date,
            'description' => "Settle principal payment for loan {$loan->loanNo}",
            'branch_id' => $loan->branch_id,
            'user_id' => auth()->id(),
        ]);

        // Credit: Principal receivable account
        $principalAccountId = $loan->product->principal_receivable_account_id;
        if ($principalAccountId) {
            GlTransaction::create([
                'chart_account_id' => $principalAccountId,
                'customer_id' => $loan->customer_id,
                'amount' => $principalAmount,
                'nature' => 'credit',
                'transaction_id' => $repayment->id,
                'transaction_type' => 'Settle Principal',
                'date' => $repayment->payment_date,
                'description' => "Settle principal payment for loan {$loan->loanNo}",
                'branch_id' => $loan->branch_id,
                'user_id' => auth()->id(),
            ]);
        }
    }

    /**
     * Create GL transactions for settle interest payment from cash deposit
     */
    private function createSettleInterestGLFromCashDeposit(Loan $loan, Repayment $repayment, float $interestAmount, array $paymentData)
    {
        // Get cash deposit account
        $cashDeposit = \App\Models\CashCollateral::findOrFail($paymentData['cash_deposit_id']);

        // Debit: Cash collateral account (reducing the deposit)
        GlTransaction::create([
            'chart_account_id' => $cashDeposit->type->chart_account_id ?? 1,
            'customer_id' => $loan->customer_id,
            'amount' => $interestAmount,
            'nature' => 'debit',
            'transaction_id' => $repayment->id,
            'transaction_type' => 'Settle Interest',
            'date' => $repayment->payment_date,
            'description' => "Settle interest payment from cash deposit for loan {$loan->loanNo}",
            'branch_id' => $loan->branch_id,
            'user_id' => auth()->id(),
        ]);

        // Credit: Interest receivable or revenue account
        $interestAccountId = $loan->product->interest_receivable_account_id ?? $loan->product->interest_revenue_account_id;
        if ($interestAccountId) {
            GlTransaction::create([
                'chart_account_id' => $interestAccountId,
                'customer_id' => $loan->customer_id,
                'amount' => $interestAmount,
                'nature' => 'credit',
                'transaction_id' => $repayment->id,
                'transaction_type' => 'Settle Interest',
                'date' => $repayment->payment_date,
                'description' => "Settle interest payment from cash deposit for loan {$loan->loanNo}",
                'branch_id' => $loan->branch_id,
                'user_id' => auth()->id(),
            ]);
        }
    }

    /**
     * Create GL transactions for settle principal payment from cash deposit
     */
    private function createSettlePrincipalGLFromCashDeposit(Loan $loan, Repayment $repayment, float $principalAmount, array $paymentData)
    {
        // Get cash deposit account
        $cashDeposit = \App\Models\CashCollateral::findOrFail($paymentData['cash_deposit_id']);

        // Debit: Cash collateral account (reducing the deposit)
        GlTransaction::create([
            'chart_account_id' => $cashDeposit->type->chart_account_id ?? 1,
            'customer_id' => $loan->customer_id,
            'amount' => $principalAmount,
            'nature' => 'debit',
            'transaction_id' => $repayment->id,
            'transaction_type' => 'Settle Principal',
            'date' => $repayment->payment_date,
            'description' => "Settle principal payment from cash deposit for loan {$loan->loanNo}",
            'branch_id' => $loan->branch_id,
            'user_id' => auth()->id(),
        ]);

        // Credit: Principal receivable account
        $principalAccountId = $loan->product->principal_receivable_account_id;
        if ($principalAccountId) {
            GlTransaction::create([
                'chart_account_id' => $principalAccountId,
                'customer_id' => $loan->customer_id,
                'amount' => $principalAmount,
                'nature' => 'credit',
                'transaction_id' => $repayment->id,
                'transaction_type' => 'Settle Principal',
                'date' => $repayment->payment_date,
                'description' => "Settle principal payment from cash deposit for loan {$loan->loanNo}",
                'branch_id' => $loan->branch_id,
                'user_id' => auth()->id(),
            ]);
        }
    }

    /**
     * Delete repayment and all associated records
     * This method deletes all related data created during repayment processing
     */
    public function deleteRepayment($repaymentId)
    {
        DB::beginTransaction();

        try {
            $repayment = Repayment::with(['loan', 'schedule'])->findOrFail($repaymentId);
            $loan = $repayment->loan;
            $originalLoanStatus = $loan->status;

            Log::info('Starting comprehensive repayment deletion', [
                'repayment_id' => $repayment->id,
                'loan_id' => $loan->id,
                'customer_id' => $repayment->customer_id
            ]);

            // 1. Find and delete Receipt and related records
            $this->deleteRepaymentReceipt($repayment);

            // 2. Find and delete Journal and related records
            $this->deleteRepaymentJournal($repayment);

            // 3. Delete all GL transactions related to this repayment
            $this->deleteRepaymentGLTransactions($repayment);

            // 4. Restore cash deposit if applicable
            $this->restoreCashDepositForRepayment($repayment);

            // 5. Update loan status if it was closed due to this repayment
            $this->updateLoanStatusAfterDeletion($loan, $originalLoanStatus);

            // 6. Delete the repayment record
            $repayment->delete();

            DB::commit();

            Log::info('Repayment deletion completed successfully', [
                'repayment_id' => $repaymentId,
                'loan_id' => $loan->id
            ]);

            return [
                'success' => true,
                'message' => 'Repayment and all associated records deleted successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Repayment deletion failed', [
                'repayment_id' => $repaymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Delete receipt and all associated records for a repayment
     */
    private function deleteRepaymentReceipt($repayment)
    {
        // Find receipt by reference (repayment ID) and reference_type
        $receipt = Receipt::where('reference', $repayment->id)
            ->where('reference_type', 'loan_repayment')
            ->first();

        if (!$receipt) {
            Log::info('No receipt found for repayment', ['repayment_id' => $repayment->id]);
            return;
        }

        Log::info('Deleting receipt and associated data', [
            'receipt_id' => $receipt->id,
            'repayment_id' => $repayment->id
        ]);

        // Delete receipt items
        $receiptItemsCount = ReceiptItem::where('receipt_id', $receipt->id)->delete();
        Log::info('Deleted receipt items', [
            'receipt_id' => $receipt->id,
            'count' => $receiptItemsCount
        ]);

        // Delete GL transactions for this receipt
        $receiptGLCount = GlTransaction::where('transaction_id', $receipt->id)
            ->where('transaction_type', 'receipt')
            ->delete();
        Log::info('Deleted GL transactions for receipt', [
            'receipt_id' => $receipt->id,
            'count' => $receiptGLCount
        ]);

        // Delete the receipt
        $receipt->delete();

        Log::info('Receipt deletion completed', [
            'receipt_id' => $receipt->id,
            'receipt_items_deleted' => $receiptItemsCount,
            'gl_transactions_deleted' => $receiptGLCount
        ]);
    }

    /**
     * Delete journal and all associated records for a repayment
     */
    private function deleteRepaymentJournal($repayment)
    {
        // Find journal by reference (repayment ID) and reference_type
        $journal = Journal::where('reference', $repayment->id)
            ->where('reference_type', 'Withdrawal')
            ->first();

        if (!$journal) {
            Log::info('No journal found for repayment', ['repayment_id' => $repayment->id]);
            return;
        }

        Log::info('Deleting journal and associated data', [
            'journal_id' => $journal->id,
            'repayment_id' => $repayment->id
        ]);

        // Delete journal items
        $journalItemsCount = JournalItem::where('journal_id', $journal->id)->delete();
        Log::info('Deleted journal items', [
            'journal_id' => $journal->id,
            'count' => $journalItemsCount
        ]);

        // Delete GL transactions for this journal
        $journalGLCount = GlTransaction::where('transaction_id', $journal->id)
            ->where('transaction_type', 'journal repayment')
            ->delete();
        Log::info('Deleted GL transactions for journal', [
            'journal_id' => $journal->id,
            'count' => $journalGLCount
        ]);

        // Delete the journal
        $journal->delete();

        Log::info('Journal deletion completed', [
            'journal_id' => $journal->id,
            'journal_items_deleted' => $journalItemsCount,
            'gl_transactions_deleted' => $journalGLCount
        ]);
    }

    /**
     * Delete all GL transactions associated with a repayment
     */
    private function deleteRepaymentGLTransactions($repayment)
    {
        $totalDeleted = 0;

        // Delete GL transactions by repayment ID (for settle repayments and direct references)
        $repaymentGLCount = GlTransaction::where('transaction_id', $repayment->id)
            ->whereIn('transaction_type', ['receipt', 'journal repayment', 'Settle Interest', 'Settle Principal'])
            ->delete();
        $totalDeleted += $repaymentGLCount;
        Log::info('Deleted GL transactions by repayment ID', [
            'repayment_id' => $repayment->id,
            'count' => $repaymentGLCount
        ]);

        // Delete GL transactions by receipt ID (if receipt exists)
        $receipt = Receipt::where('reference', $repayment->id)
            ->where('reference_type', 'loan_repayment')
            ->first();
        if ($receipt) {
            $receiptGLCount = GlTransaction::where('transaction_id', $receipt->id)
                ->where('transaction_type', 'receipt')
                ->delete();
            $totalDeleted += $receiptGLCount;
            Log::info('Deleted GL transactions by receipt ID', [
                'receipt_id' => $receipt->id,
                'count' => $receiptGLCount
            ]);
        }

        // Delete GL transactions by journal ID (if journal exists)
        $journal = Journal::where('reference', $repayment->id)
            ->where('reference_type', 'Withdrawal')
            ->first();
        if ($journal) {
            $journalGLCount = GlTransaction::where('transaction_id', $journal->id)
                ->where('transaction_type', 'journal repayment')
                ->delete();
            $totalDeleted += $journalGLCount;
            Log::info('Deleted GL transactions by journal ID', [
                'journal_id' => $journal->id,
                'count' => $journalGLCount
            ]);
        }

        // Delete GL transactions for loan schedule (Mature Interest and Penalty)
        // Note: Only delete if they are specifically related to this repayment
        // We'll be conservative and not delete all schedule-related GL transactions
        // as they might be shared across multiple repayments

        Log::info('Total GL transactions deleted', [
            'repayment_id' => $repayment->id,
            'total_deleted' => $totalDeleted
        ]);
    }

    /**
     * Restore cash deposit if repayment was made from cash deposit
     */
    private function restoreCashDepositForRepayment($repayment)
    {
        // Check if journal exists (indicates cash deposit payment)
        $journal = Journal::where('reference', $repayment->id)
            ->where('reference_type', 'Withdrawal')
            ->first();

        if (!$journal) {
            Log::info('No journal found, not a cash deposit repayment', ['repayment_id' => $repayment->id]);
            return;
        }

        // Find cash deposit from journal items (look for debit entries to cash deposit account)
        $journalItems = JournalItem::where('journal_id', $journal->id)
            ->where('nature', 'debit')
            ->get();

        if ($journalItems->isEmpty()) {
            Log::warning('No debit journal items found for cash deposit restoration', [
                'journal_id' => $journal->id
            ]);
            return;
        }

        // Get the cash deposit account ID from the first debit item
        $cashDepositAccountId = $journalItems->first()->chart_account_id;

        // Find the cash deposit record
        $cashDeposit = \App\Models\CashCollateral::whereHas('type', function ($query) use ($cashDepositAccountId) {
            $query->where('chart_account_id', $cashDepositAccountId);
        })->where('customer_id', $repayment->customer_id)->first();

        if ($cashDeposit) {
            $amountToRestore = $repayment->principal + $repayment->interest + $repayment->fee_amount + $repayment->penalt_amount;
            $cashDeposit->increment('amount', $amountToRestore);

            Log::info('Restored cash deposit amount', [
                'cash_deposit_id' => $cashDeposit->id,
                'amount_restored' => $amountToRestore,
                'new_balance' => $cashDeposit->amount
            ]);
        } else {
            Log::warning('Cash deposit not found for restoration', [
                'customer_id' => $repayment->customer_id,
                'chart_account_id' => $cashDepositAccountId
            ]);
        }
    }

    /**
     * Update loan status after repayment deletion
     */
    private function updateLoanStatusAfterDeletion($loan, $originalStatus)
    {
        // Accept a few possible closed/completed representations
        $closedValues = [
            defined('App\\Models\\Loan::STATUS_COMPLETE') ? \App\Models\Loan::STATUS_COMPLETE : 'completed',
            'complete',
            'closed',
            'completed'
        ];

        if (!in_array($originalStatus, $closedValues, true)) {
            // Loan wasn't closed/completed originally — nothing to do
            return;
        }

        try {
            // Refresh model and ensure schedules & repayments are loaded
            $loan->refresh();
            $loan->loadMissing(['schedule.repayments']);

            // If loan is no longer eligible for closing, revert status to active
            if (!$loan->isEligibleForClosing()) {
                $previous = $loan->status;
                $loan->status = \App\Models\Loan::STATUS_ACTIVE;
                $loan->save();

                Log::info('Loan status reverted to active after repayment deletion', [
                    'loan_id' => $loan->id,
                    'previous_status' => $previous,
                    'original_status' => $originalStatus
                ]);
            } else {
                // If still eligible for closing, ensure status is completed
                if ($loan->status !== \App\Models\Loan::STATUS_COMPLETE) {
                    $loan->status = \App\Models\Loan::STATUS_COMPLETE;
                    $loan->save();
                }
                Log::info('Loan remains eligible for closing after repayment deletion', ['loan_id' => $loan->id]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to update loan status after repayment deletion', [
                'loan_id' => $loan->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
