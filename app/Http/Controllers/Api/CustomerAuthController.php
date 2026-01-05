<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class CustomerAuthController extends Controller
{
    /**
     * Customer login API
     */
    public function login(Request $request)
    {
        try {
            // Validate input
            $request->validate([
                'username' => 'required|string',
                'password' => 'required|string',
            ]);

            // Clean phone number
            $phone = preg_replace('/\D/', '', $request->username);
            $phone = ltrim($phone, '0');
            $username = '255' . $phone;

            // Fetch customer by phone
            $customer = Customer::where('phone1', $username)
                ->orWhere('phone1', $request->username)
                ->first();

            if (!$customer) {
                return response()->json([
                    'message' => 'User Does Not Exist',
                    'status' => 401
                ], 401);
            }

            // Verify password
            if (!Hash::check($request->password, $customer->password)) {
                return response()->json([
                    'message' => 'Invalid credentials',
                    'status' => 401
                ], 401);
            }

            // Get customer's group
            $groupMembership = DB::table('group_members')
                ->where('customer_id', $customer->id)
                ->first();

            $groupId = $groupMembership->group_id ?? null;
            $group = null;

            if ($groupId) {
                $group = DB::table('groups')->where('id', $groupId)->first();
            }

            // Get customer's loans with repayments
            $customerLoans = $this->getLoansWithRepayments($customer->id);

            // Calculate total balance for all customer loans
            $totalLoanBalance = collect($customerLoans)->sum('total_due');
            $totalLoanAmount = collect($customerLoans)->sum('total_amount');
            $totalRepaid = collect($customerLoans)->sum('total_repaid');

            // Get group members and their loans
            $members = [];
            if ($groupId) {
                $groupMembers = DB::table('group_members')
                    ->join('customers', 'group_members.customer_id', '=', 'customers.id')
                    ->where('group_members.group_id', $groupId)
                    ->select('customers.*')
                    ->orderBy('customers.name', 'asc')
                    ->get();

                foreach ($groupMembers as $member) {
                    $members[] = [
                        'id' => $member->id,
                        'name' => $member->name,
                        'phone1' => $member->phone1,
                        'phone2' => $member->phone2,
                        'sex' => $member->sex,
                        'picture' => $member->photo ? asset('storage/' . $member->photo) : null,
                        'loans' => $this->getLoansWithRepayments($member->id),
                    ];
                }
            }

            // Return successful response
            return response()->json([
                'message' => 'Login successful',
                'status' => 200,
                'user_id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone1,
                'branch' => $customer->branch->name ?? '',
                'group_id' => $groupId,
                'group_name' => $group->name ?? '',
                'email' => '',
                'memberno' => $customer->customerNo,
                'gender' => $customer->sex,
                'role' => 'customer',
                'loans' => $customerLoans,
                'total_loan_balance' => $totalLoanBalance,
                'total_loan_amount' => $totalLoanAmount,
                'total_repaid' => $totalRepaid,
                'loans_count' => count($customerLoans),
                'members' => $members,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer profile
     */
    public function profile(Request $request)
    {
        try {
            $customerId = $request->input('customer_id');

            if (!$customerId) {
                return response()->json([
                    'message' => 'Customer ID is required',
                    'status' => 400
                ], 400);
            }

            $customer = Customer::with(['branch', 'region', 'district'])->find($customerId);

            if (!$customer) {
                return response()->json([
                    'message' => 'Customer not found',
                    'status' => 404
                ], 404);
            }

            // Get customer's group
            $groupMembership = DB::table('group_members')
                ->where('customer_id', $customer->id)
                ->first();

            $groupId = $groupMembership->group_id ?? null;
            $group = null;

            if ($groupId) {
                $group = DB::table('groups')->where('id', $groupId)->first();
            }

            return response()->json([
                'status' => 200,
                'customer' => [
                    'id' => $customer->id,
                    'customerNo' => $customer->customerNo,
                    'name' => $customer->name,
                    'description' => $customer->description,
                    'phone1' => $customer->phone1,
                    'phone2' => $customer->phone2,
                    'work' => $customer->work,
                    'workAddress' => $customer->workAddress,
                    'idType' => $customer->idType,
                    'idNumber' => $customer->idNumber,
                    'dob' => $customer->dob,
                    'sex' => $customer->sex,
                    'category' => $customer->category,
                    'dateRegistered' => $customer->dateRegistered,
                    'photo' => $customer->photo ? asset('storage/' . $customer->photo) : null,
                    'branch' => $customer->branch->name ?? '',
                    'region' => $customer->region->name ?? '',
                    'district' => $customer->district->name ?? '',
                    'group_id' => $groupId,
                    'group_name' => $group->name ?? '',
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer loans
     */
    public function loans(Request $request)
    {
        try {
            $customerId = $request->input('customer_id');

            if (!$customerId) {
                return response()->json([
                    'message' => 'Customer ID is required',
                    'status' => 400
                ], 400);
            }

            $loans = $this->getLoansWithRepayments($customerId);

            // Calculate totals
            $totalLoanBalance = collect($loans)->sum('total_due');
            $totalLoanAmount = collect($loans)->sum('total_amount');
            $totalRepaid = collect($loans)->sum('total_repaid');

            return response()->json([
                'status' => 200,
                'loans' => $loans,
                'total_loan_balance' => $totalLoanBalance,
                'total_loan_amount' => $totalLoanAmount,
                'total_repaid' => $totalRepaid,
                'loans_count' => count($loans),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get group members
     */
    public function groupMembers(Request $request)
    {
        try {
            $customerId = $request->input('customer_id');

            if (!$customerId) {
                return response()->json([
                    'message' => 'Customer ID is required',
                    'status' => 400
                ], 400);
            }

            // Get customer's group
            $groupMembership = DB::table('group_members')
                ->where('customer_id', $customerId)
                ->first();

            if (!$groupMembership) {
                return response()->json([
                    'status' => 200,
                    'members' => [],
                ], 200);
            }

            $groupId = $groupMembership->group_id;

            // Get all group members
            $groupMembers = DB::table('group_members')
                ->join('customers', 'group_members.customer_id', '=', 'customers.id')
                ->where('group_members.group_id', $groupId)
                ->select('customers.*')
                ->orderBy('customers.name', 'asc')
                ->get();

            $members = [];
            foreach ($groupMembers as $member) {
                $members[] = [
                    'id' => $member->id,
                    'name' => $member->name,
                    'phone1' => $member->phone1,
                    'phone2' => $member->phone2,
                    'sex' => $member->sex,
                    'picture' => $member->photo ? asset('storage/' . $member->photo) : null,
                    'customerNo' => $member->customerNo,
                    'loans' => $this->getLoansWithRepayments($member->id),
                ];
            }

            return response()->json([
                'status' => 200,
                'members' => $members,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all loan products
     */
    public function loanProducts(Request $request)
    {
        try {
            $products = LoanProduct::where('is_active', true)
                ->orderBy('name', 'asc')
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'product_type' => $product->product_type,
                        'min_amount' => $product->minimum_principal,
                        'max_amount' => $product->maximum_principal,
                        'min_interest_rate' => $product->minimum_interest_rate,
                        'max_interest_rate' => $product->maximum_interest_rate,
                        'interest_cycle' => $product->interest_cycle,
                        'interest_method' => $product->interest_method,
                        'min_period' => $product->minimum_period,
                        'max_period' => $product->maximum_period,
                        'grace_period' => $product->grace_period ?? 0,
                        'has_cash_collateral' => $product->has_cash_collateral ?? false,
                        'cash_collateral_type' => $product->cash_collateral_type,
                        'cash_collateral_value_type' => $product->cash_collateral_value_type,
                        'cash_collateral_value' => $product->cash_collateral_value ?? 0,
                    ];
                });

            return response()->json([
                'status' => 200,
                'products' => $products,
                'total_products' => $products->count(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update customer photo
     */
    public function updatePhoto(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'required|integer',
                'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            $customer = Customer::find($request->customer_id);

            if (!$customer) {
                return response()->json([
                    'message' => 'Customer not found',
                    'status' => 404
                ], 404);
            }

            // Delete old photo if exists
            if ($customer->photo && \Storage::disk('public')->exists($customer->photo)) {
                \Storage::disk('public')->delete($customer->photo);
            }

            // Store new photo
            $photoPath = $request->file('photo')->store('photos', 'public');
            $customer->photo = $photoPath;
            $customer->save();

            return response()->json([
                'message' => 'Photo updated successfully',
                'status' => 200,
                'photo_url' => asset('storage/' . $photoPath),
                'photo_path' => $photoPath,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper function to get loans with repayments
     */
    private function getLoansWithRepayments($customerId)
    {
        $loans = Loan::where('customer_id', $customerId)
            ->with(['product', 'loanOfficer'])
            ->orderBy('id', 'desc')
            ->get();

        $result = [];
        foreach ($loans as $loan) {
            // Get repayments from repayments table
            $repayments = DB::table('repayments')
                ->where('loan_id', $loan->id)
                ->orderBy('payment_date', 'asc')
                ->get()
                ->map(function ($repayment) {
                    $totalAmount = ($repayment->principal ?? 0) + 
                                   ($repayment->interest ?? 0) + 
                                   ($repayment->penalt_amount ?? 0) + 
                                   ($repayment->fee_amount ?? 0);
                    return [
                        'id' => $repayment->id,
                        'amount' => $totalAmount,
                        'principal' => $repayment->principal ?? 0,
                        'interest' => $repayment->interest ?? 0,
                        'penalty' => $repayment->penalt_amount ?? 0,
                        'fee' => $repayment->fee_amount ?? 0,
                        'date' => $repayment->payment_date,
                        'due_date' => $repayment->due_date,
                    ];
                });

            // Calculate totals
            $totalRepaid = $repayments->sum('amount');
            $totalDue = ($loan->amount_total ?? 0) - $totalRepaid;

            $result[] = [
                'loanid' => $loan->id,
                'loan_no' => $loan->loanNo,
                'amount' => $loan->amount,
                'interest' => $loan->interest,
                'interest_amount' => $loan->interest_amount,
                'total_amount' => $loan->amount_total,
                'period' => $loan->period,
                'disbursed_on' => $loan->disbursed_on,
                'last_repayment_date' => $loan->last_repayment_date,
                'status' => $loan->status,
                'product_name' => $loan->product->name ?? '',
                'loan_officer' => $loan->loanOfficer->name ?? '',
                'repayments' => $repayments,
                'total_repaid' => $totalRepaid,
                'total_due' => $totalDue,
            ];
        }

        return $result;
    }
}
