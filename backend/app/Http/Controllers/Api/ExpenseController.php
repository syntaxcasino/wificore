<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Events\ExpenseCreated;
use App\Events\ExpenseUpdated;
use App\Events\ExpenseDeleted;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ExpenseController extends Controller
{
    /**
     * Display a listing of expenses
     */
    public function index(Request $request): JsonResponse
    {
        $query = Expense::with(['submitter', 'approver']);

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by payment method
        if ($request->has('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('expense_date', [$request->start_date, $request->end_date]);
        }

        // Filter by submitter
        if ($request->has('submitted_by')) {
            $query->where('submitted_by', $request->submitted_by);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('expense_number', 'ILIKE', "%{$search}%")
                  ->orWhere('description', 'ILIKE', "%{$search}%")
                  ->orWhere('vendor_name', 'ILIKE', "%{$search}%")
                  ->orWhere('receipt_number', 'ILIKE', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'expense_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $expenses = $query->paginate($perPage);

        return response()->json($expenses);
    }

    /**
     * Store a newly created expense
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'category' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'payment_method' => 'nullable|in:cash,bank_transfer,credit_card,mobile_money,check',
            'vendor_name' => 'nullable|string|max:255',
            'receipt_number' => 'nullable|string|max:255',
            'receipt_file' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Create expense (NO tenant_id - schema isolation provides tenancy)
        // Expense number auto-generated in model boot method
        $data = $validator->validated();
        $data['submitted_by'] = auth()->id();
        $data['status'] = 'pending';

        $expense = Expense::create($data);

        // Dispatch event for real-time updates
        event(new ExpenseCreated($expense, auth()->user()->tenant_id ?? null));

        Log::info('Expense created', [
            'expense_id' => $expense->id,
            'expense_number' => $expense->expense_number,
            'amount' => $expense->amount,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Expense created successfully',
            'data' => $expense->load(['submitter', 'approver'])
        ], 201);
    }

    /**
     * Display the specified expense
     */
    public function show($id): JsonResponse
    {
        $expense = Expense::with(['submitter', 'approver'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $expense
        ]);
    }

    /**
     * Update the specified expense
     */
    public function update(Request $request, $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        // Only allow updates if expense is pending
        if ($expense->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending expenses can be updated'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'category' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'sometimes|required|numeric|min:0',
            'expense_date' => 'sometimes|required|date',
            'payment_method' => 'nullable|in:cash,bank_transfer,credit_card,mobile_money,check',
            'vendor_name' => 'nullable|string|max:255',
            'receipt_number' => 'nullable|string|max:255',
            'receipt_file' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $originalData = $expense->toArray();
        $expense->update($validator->validated());

        // Dispatch event for real-time updates
        $changes = array_diff_assoc($validator->validated(), $originalData);
        event(new ExpenseUpdated($expense, $changes, auth()->user()->tenant_id ?? null));

        Log::info('Expense updated', [
            'expense_id' => $expense->id,
            'changes' => $changes,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Expense updated successfully',
            'data' => $expense->load(['submitter', 'approver'])
        ]);
    }

    /**
     * Remove the specified expense
     */
    public function destroy($id): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        // Only allow deletion if expense is pending
        if ($expense->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending expenses can be deleted'
            ], 422);
        }

        $expenseData = $expense->toArray();
        $expense->delete();

        // Dispatch event for real-time updates
        event(new ExpenseDeleted($expense->id, $expenseData, auth()->user()->tenant_id ?? null));

        Log::info('Expense deleted', [
            'expense_id' => $expense->id,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Expense deleted successfully'
        ]);
    }

    /**
     * Approve an expense
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        if ($expense->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending expenses can be approved'
            ], 422);
        }

        $expense->approve(auth()->id());

        event(new ExpenseUpdated($expense, ['status' => 'approved'], auth()->user()->tenant_id ?? null));

        Log::info('Expense approved', [
            'expense_id' => $expense->id,
            'approved_by' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Expense approved successfully',
            'data' => $expense->load(['submitter', 'approver'])
        ]);
    }

    /**
     * Reject an expense
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        if ($expense->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending expenses can be rejected'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $expense->reject(auth()->id(), $request->rejection_reason);

        event(new ExpenseUpdated($expense, ['status' => 'rejected'], auth()->user()->tenant_id ?? null));

        Log::info('Expense rejected', [
            'expense_id' => $expense->id,
            'rejected_by' => auth()->id(),
            'reason' => $request->rejection_reason
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Expense rejected successfully',
            'data' => $expense->load(['submitter', 'approver'])
        ]);
    }

    /**
     * Mark expense as paid
     */
    public function markAsPaid($id): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        if ($expense->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Only approved expenses can be marked as paid'
            ], 422);
        }

        $expense->markAsPaid();

        event(new ExpenseUpdated($expense, ['status' => 'paid'], auth()->user()->tenant_id ?? null));

        Log::info('Expense marked as paid', [
            'expense_id' => $expense->id,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Expense marked as paid successfully',
            'data' => $expense
        ]);
    }

    /**
     * Get expense statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $startDate = $request->get('start_date', now()->startOfMonth());
        $endDate = $request->get('end_date', now()->endOfMonth());

        $stats = [
            'total_expenses' => Expense::whereBetween('expense_date', [$startDate, $endDate])->count(),
            'total_amount' => Expense::whereBetween('expense_date', [$startDate, $endDate])->sum('amount'),
            'by_status' => [
                'pending' => Expense::where('status', 'pending')->whereBetween('expense_date', [$startDate, $endDate])->sum('amount'),
                'approved' => Expense::where('status', 'approved')->whereBetween('expense_date', [$startDate, $endDate])->sum('amount'),
                'rejected' => Expense::where('status', 'rejected')->whereBetween('expense_date', [$startDate, $endDate])->sum('amount'),
                'paid' => Expense::where('status', 'paid')->whereBetween('expense_date', [$startDate, $endDate])->sum('amount'),
            ],
            'by_category' => Expense::selectRaw('category, SUM(amount) as total')
                ->whereBetween('expense_date', [$startDate, $endDate])
                ->groupBy('category')
                ->get(),
            'by_payment_method' => Expense::selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
                ->whereBetween('expense_date', [$startDate, $endDate])
                ->groupBy('payment_method')
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
