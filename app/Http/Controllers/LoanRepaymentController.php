<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Repayment;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\GlTransaction;
use App\Services\LoanRepaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class LoanRepaymentController extends Controller
{
    protected $repaymentService;

    public function __construct(LoanRepaymentService $repaymentService)
    {
        $this->repaymentService = $repaymentService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            // Add debugging
            Log::info('Repayment request received', $request->all());

            $request->validate([
                'loan_id' => 'required|exists:loans,id',
                'schedule_id' => 'required|exists:loan_schedules,id',
                'payment_date' => 'required|date',
                'amount' => 'required|numeric|min:0.01',
                'payment_source' => 'required|in:bank,cash_deposit',
                'bank_account_id' => 'required_if:payment_source,bank|nullable|exists:bank_accounts,id',
                'cash_deposit_id' => 'required_if:payment_source,cash_deposit|nullable|exists:cash_collaterals,id',
            ]);

            Log::info('Validation passed');

            // Get loan and check if amount matches settle amount
            $loan = Loan::with(['product', 'customer', 'schedule'])->findOrFail($request->loan_id);
            $settleAmount = $loan->total_amount_to_settle;
            $paymentAmount = $request->amount;

            Log::info('Amount comparison', [
                'payment_amount' => $paymentAmount,
                'settle_amount' => $settleAmount,
                'difference' => abs($paymentAmount - $settleAmount)
            ]);


            Log::info('Processing normal repayment', [
                'loan_id' => $request->loan_id,
                'amount' => $paymentAmount,
                'settle_amount' => $settleAmount
            ]);

            // Use normal repayment process
            $bankAccount = BankAccount::findOrFail($request->bank_account_id);
            $bankChartAccount = $bankAccount->chart_account_id;

            // Check cash deposit balance if using cash deposit
            if ($request->payment_source === 'cash_deposit') {
                $cashDeposit = \App\Models\CashCollateral::findOrFail($request->cash_deposit_id);

                if ($cashDeposit->amount < $request->amount) {
                    return redirect()->back()->with('error', 'Insufficient cash deposit balance. Available: TSHS ' . number_format($cashDeposit->amount, 2));
                }
            }

            // Prepare payment data based on source
            $paymentData = [
                'payment_date' => $request->payment_date,
                'payment_source' => $request->payment_source,
                'bank_chart_account_id' => $bankChartAccount,
            ];

            if ($request->payment_source === 'bank') {
                $paymentData['bank_account_id'] = $request->bank_account_id;
            } else {
                $paymentData['cash_deposit_id'] = $request->cash_deposit_id;
            }

            // Get calculation method from loan product
            $calculationMethod = $loan->product->interest_method ?? 'flat_rate';

            Log::info('Processing normal repayment', [
                'loan_id' => $request->loan_id,
                'amount' => $request->amount,
                'calculation_method' => $calculationMethod,
                'payment_source' => $request->payment_source
            ]);

            // Process repayment using service
            $result = $this->repaymentService->processRepayment(
                $request->loan_id,
                $request->amount,
                $paymentData,
                $calculationMethod
            );

            Log::info('Repayment processing result', $result);

            return redirect()->back()->with('success', 'Repayment recorded successfully!');
        } catch (\Exception $e) {
            Log::error('Loan repayment error: ' . $e->getMessage());
            Log::error('Repayment error stack trace: ' . $e->getTraceAsString());

            return redirect()->back()->with('error', 'Failed to record repayment: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $repayment = Repayment::with(['loan', 'schedule', 'bankAccount', 'customer'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'repayment' => $repayment
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'payment_date' => 'required|date',
                'amount' => 'required|numeric|min:0.01',
                'bank_account_id' => 'required|exists:bank_accounts,id',
            ]);

            $repayment = Repayment::with(['loan', 'bankAccount'])->findOrFail($id);
            $bankAccount = BankAccount::findOrFail($request->bank_account_id);
            $bankChartAccount = $bankAccount->chart_account_id;

            // Store the loan and schedule info before deletion
            $loanId = $repayment->loan_id;

            // Delete the existing repayment (this will also delete receipt, journal, and GL transactions)
            $this->repaymentService->deleteRepayment($repayment->id);

            // Create new repayment with updated details
            $paymentData = [
                'payment_date' => $request->payment_date,
                'bank_account_id' => $request->bank_account_id,
                'bank_chart_account_id' => $bankChartAccount,
            ];

            // Get calculation method from loan product
            $loan = Loan::with('product')->findOrFail($loanId);
            $calculationMethod = $loan->product->interest_method ?? 'flat_rate';

            // Process new repayment using service
            $result = $this->repaymentService->processRepayment(
                $loanId,
                $request->amount,
                $paymentData,
                $calculationMethod
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Repayment updated successfully!'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Repayment update error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update repayment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Internal method to delete repayment and associated records
     * This method delegates to the service for comprehensive deletion
     */
    private function deleteRepaymentInternal($repayment)
    {
        // Use the service method for comprehensive deletion
        return $this->repaymentService->deleteRepayment($repayment->id);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            // Use service method which handles transaction internally
            $result = $this->repaymentService->deleteRepayment($id);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Repayment deleted successfully!'
            ]);
        } catch (\Exception $e) {
            Log::error('Repayment deletion error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete repayment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk delete repayments
     */
    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:repayments,id',
        ]);

        try {
            $deletedCount = 0;
            $errors = [];

            foreach ($validated['ids'] as $repaymentId) {
                try {
                    $this->repaymentService->deleteRepayment($repaymentId);
                    $deletedCount++;
                } catch (\Exception $e) {
                    $errors[] = "Repayment ID {$repaymentId}: " . $e->getMessage();
                    Log::error("Failed to delete repayment {$repaymentId}: " . $e->getMessage());
                }
            }

            $message = "Deleted {$deletedCount} repayment(s) successfully.";
            if (!empty($errors)) {
                $message .= " " . count($errors) . " failed: " . implode('; ', $errors);
            }

            return response()->json([
                'success' => $deletedCount > 0,
                'message' => $message,
                'deleted' => $deletedCount,
                'failed' => count($errors),
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            Log::error('Bulk repayment deletion error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete repayments: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get repayment history for a loan
     */
    public function getRepaymentHistory($loanId)
    {
        $repayments = Repayment::where('loan_id', $loanId)
            ->with(['schedule', 'bankAccount'])
            ->orderBy('payment_date', 'desc')
            ->get();

        return response()->json($repayments);
    }

    /**
     * Get schedule details for repayment
     */
    public function getScheduleDetails($scheduleId)
    {
        $schedule = LoanSchedule::with(['loan'])->findOrFail($scheduleId);

        return response()->json([
            'schedule' => $schedule,
            'total_due' => $schedule->principal + $schedule->interest + $schedule->fee_amount + $schedule->penalty_amount,
        ]);
    }

    /**
     * Remove penalty from schedule
     */
    public function removePenalty(Request $request, $scheduleId)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0',
                'loan_id' => 'required|exists:loans,id',
                'schedule_id' => 'required|exists:loan_schedules,id',
                'reason' => 'nullable|string|max:500',
            ]);
            // Validate that the requested removal amount does not exceed current penalty
            $schedule = LoanSchedule::findOrFail($request->schedule_id);
            $currentPenaltyAmount = (float) $schedule->penalty_amount;
            $requestedAmount = (float) $request->amount;
            if ($requestedAmount > $currentPenaltyAmount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Amount cannot exceed current penalty amount.'
                ], 422);
            }

            $result = $this->repaymentService->removePenalty(
                $request->schedule_id,
                $request->reason,
                $request->amount,
                $request->loan_id
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Penalty removal error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove penalty: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate loan schedule
     */
    public function calculateSchedule(Request $request, $loanId)
    {
        try {
            $request->validate([
                'method' => 'required|in:flat_rate,reducing_equal_installment,reducing_equal_principal',
            ]);

            $loan = Loan::findOrFail($loanId);
            $schedules = $this->repaymentService->calculateSchedule($loan, $request->method);

            return response()->json([
                'success' => true,
                'schedules' => $schedules
            ]);
        } catch (\Exception $e) {
            Log::error('Schedule calculation error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate schedule: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk repayment processing
     */
    public function bulkRepayment(Request $request)
    {
        try {
            $request->validate([
                'repayments' => 'required|array|min:1',
                'repayments.*.loan_id' => 'required|exists:loans,id',
                'repayments.*.amount' => 'required|numeric|min:0.01',
                'repayments.*.payment_date' => 'required|date',
                'repayments.*.bank_account_id' => 'required|exists:bank_accounts,id',
            ]);
            $bankAccount = BankAccount::findOrFail($request->repayments[0]['bank_account_id']);
            $bankChartAccount = $bankAccount->chart_account_id;

            $results = [];
            $successCount = 0;
            $errorCount = 0;

            foreach ($request->repayments as $repaymentData) {
                try {
                    $paymentData = [
                        'payment_date' => $repaymentData['payment_date'],
                        'bank_account_id' => $repaymentData['bank_account_id'],
                        'bank_chart_account_id' => $bankChartAccount,
                    ];

                    $loan = Loan::with('product')->findOrFail($repaymentData['loan_id']);
                    $calculationMethod = $loan->product->interest_method ?? 'flat_rate';

                    $result = $this->repaymentService->processRepayment(
                        $repaymentData['loan_id'],
                        $repaymentData['amount'],
                        $paymentData,
                        $calculationMethod
                    );

                    $results[] = [
                        'loan_id' => $repaymentData['loan_id'],
                        'success' => true,
                        'result' => $result
                    ];
                    $successCount++;
                } catch (\Exception $e) {
                    $results[] = [
                        'loan_id' => $repaymentData['loan_id'],
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                    $errorCount++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Processed {$successCount} repayments successfully, {$errorCount} failed",
                'results' => $results,
                'summary' => [
                    'total' => count($request->repayments),
                    'success' => $successCount,
                    'failed' => $errorCount
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk repayment error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to process bulk repayment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Print receipt for repayment
     */
    public function printReceipt($id)
    {
        try {
            $repayment = Repayment::with([
                'loan.customer',
                'schedule',
                'chartAccount',
                'receipt.receiptItems.chartAccount'
            ])->findOrFail($id);

            // Generate receipt data for thermal printer
            $receiptData = [
                'receipt_number' => $repayment->receipt->reference ?? 'N/A',
                'date' => $repayment->payment_date,
                'customer_name' => $repayment->customer->name,
                'loan_number' => $repayment->loan->loanNo,
                'amount_paid' => $repayment->amount_paid,
                'schedule_number' => $repayment->schedule_number,
                'due_date' => $repayment->due_date,
                'remain_schedule' => $repayment->remain_schedule,
                'remaining_schedules_count' => $repayment->remaining_schedules_count,
                'remaining_schedules_amount' => $repayment->remaining_schedules_amount,
                'payment_breakdown' => [
                    'principal' => $repayment->principal,
                    'interest' => $repayment->interest,
                    'penalty' => $repayment->penalt_amount,
                    'fee' => $repayment->fee_amount,
                ],
                'bank_account' => $repayment->chartAccount()->name ?? 'N/A',
                'received_by' => Auth::check() ? Auth::user()->name : 'System',
                'branch' => Auth::check() && Auth::user()->branch ? Auth::user()->branch->name : 'N/A',
            ];

            return response()->json([
                'success' => true,
                'receipt_data' => $receiptData
            ]);
        } catch (\Exception $e) {
            Log::error('Receipt print error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate receipt: ' . $e->getMessage()
            ], 500);
        }
    }

    public function storeSettlementRepayment(Request $request)
    {
        try {
            $request->validate([
                'loan_id' => 'required|exists:loans,id',
                'payment_date' => 'required|date',
                'amount' => 'required|numeric|min:0.01',
                'payment_source' => 'required|in:bank,cash_deposit',
                'bank_account_id' => 'required_if:payment_source,bank|nullable|exists:bank_accounts,id',
                'cash_deposit_id' => 'required_if:payment_source,cash_deposit|nullable|exists:cash_collaterals,id',
            ]);

            info("request data >>>>>>>>>>>>>>", ['request' => $request->all()]);

            // Get loan and check if amount matches settle amount
            $loan = Loan::with(['product', 'customer', 'schedule.repayments'])->findOrFail($request->loan_id);
            $settleAmount = $loan->total_amount_to_settle;
            $paymentAmount = $request->amount;
            $isSettleRepayment = abs($paymentAmount - $settleAmount) <= 0.01;

            if (!$isSettleRepayment) {
                return redirect()->back()->with('error', 'Amount does not match the settle amount. Expected: TZS ' . number_format($settleAmount, 2));
            }

            Log::info('Processing settle repayment', [
                'loan_id' => $request->loan_id,
                'amount' => $paymentAmount,
                'settle_amount' => $settleAmount,
                'payment_source' => $request->payment_source
            ]);

            // Check cash deposit balance if using cash deposit
            if ($request->payment_source === 'cash_deposit') {
                $cashDeposit = \App\Models\CashCollateral::findOrFail($request->cash_deposit_id);

                if ($cashDeposit->amount < $request->amount) {
                    return redirect()->back()->with('error', 'Insufficient cash deposit balance. Available: TSHS ' . number_format($cashDeposit->amount, 2));
                }
            }

            // Prepare payment data based on source
            $paymentData = [
                'payment_date' => $request->payment_date,
                'payment_source' => $request->payment_source,
                'notes' => 'Settle repayment - pays current interest and all remaining principal'
            ];

            if ($request->payment_source === 'bank') {
                $bankAccount = BankAccount::findOrFail($request->bank_account_id);
                $paymentData['bank_chart_account_id'] = $bankAccount->chart_account_id;
                $paymentData['bank_account_id'] = $request->bank_account_id;
            } else {
                $paymentData['cash_deposit_id'] = $request->cash_deposit_id;
            }

            $result = $this->repaymentService->processSettleRepayment($request->loan_id, $paymentAmount, $paymentData);

            if ($result['success']) {
                $message = "Loan settled successfully. ";
                $message .= "Interest paid: TZS " . number_format($result['current_interest_paid'], 2) . ". ";
                $message .= "Principal paid: TZS " . number_format($result['total_principal_paid'], 2) . ".";

                if ($result['loan_closed']) {
                    $message .= " Loan has been closed.";
                }

                return redirect()->back()->with('success', $message);
            } else {
                return redirect()->back()->with('error', 'Failed to process settle repayment.');
            }
        } catch (\Exception $e) {
            Log::error('Settle repayment error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to process settle repayment: ' . $e->getMessage());
        }
    }
}
