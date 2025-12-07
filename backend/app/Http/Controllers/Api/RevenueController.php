<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Revenue;
use App\Events\RevenueCreated;
use App\Events\RevenueUpdated;
use App\Events\RevenueDeleted;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class RevenueController extends Controller
{
    /**
     * Display a listing of revenues
     */
    public function index(Request $request): JsonResponse
    {
        $query = Revenue::with(['customer', 'recorder']);

        // Filter by source
        if ($request->has('source')) {
            $query->where('source', $request->source);
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
            $query->whereBetween('revenue_date', [$request->start_date, $request->end_date]);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('revenue_number', 'ILIKE', "%{$search}%")
                  ->orWhere('description', 'ILIKE', "%{$search}%")
                  ->orWhere('reference_number', 'ILIKE', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'revenue_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $revenues = $query->paginate($perPage);

        return response()->json($revenues);
    }

    /**
     * Store a newly created revenue
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'revenue_date' => 'required|date',
            'payment_method' => 'nullable|in:cash,bank_transfer,credit_card,mobile_money,check',
            'reference_number' => 'nullable|string|max:255',
            'customer_id' => 'nullable|uuid|exists:public.users,id',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Create revenue (NO tenant_id - schema isolation provides tenancy)
        // Revenue number auto-generated in model boot method
        $data = $validator->validated();
        $data['recorded_by'] = auth()->id();
        $data['status'] = 'confirmed';

        $revenue = Revenue::create($data);

        // Dispatch event for real-time updates
        event(new RevenueCreated($revenue, auth()->user()->tenant_id ?? null));

        Log::info('Revenue created', [
            'revenue_id' => $revenue->id,
            'revenue_number' => $revenue->revenue_number,
            'amount' => $revenue->amount,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Revenue created successfully',
            'data' => $revenue->load(['customer', 'recorder'])
        ], 201);
    }

    /**
     * Display the specified revenue
     */
    public function show($id): JsonResponse
    {
        $revenue = Revenue::with(['customer', 'recorder'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $revenue
        ]);
    }

    /**
     * Update the specified revenue
     */
    public function update(Request $request, $id): JsonResponse
    {
        $revenue = Revenue::findOrFail($id);

        // Only allow updates if revenue is pending
        if ($revenue->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Cancelled revenues cannot be updated'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'source' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'sometimes|required|numeric|min:0',
            'revenue_date' => 'sometimes|required|date',
            'payment_method' => 'nullable|in:cash,bank_transfer,credit_card,mobile_money,check',
            'reference_number' => 'nullable|string|max:255',
            'customer_id' => 'nullable|uuid|exists:public.users,id',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $originalData = $revenue->toArray();
        $revenue->update($validator->validated());

        // Dispatch event for real-time updates
        $changes = array_diff_assoc($validator->validated(), $originalData);
        event(new RevenueUpdated($revenue, $changes, auth()->user()->tenant_id ?? null));

        Log::info('Revenue updated', [
            'revenue_id' => $revenue->id,
            'changes' => $changes,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Revenue updated successfully',
            'data' => $revenue->load(['customer', 'recorder'])
        ]);
    }

    /**
     * Remove the specified revenue
     */
    public function destroy($id): JsonResponse
    {
        $revenue = Revenue::findOrFail($id);

        // Only allow deletion if revenue is pending
        if ($revenue->status === 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Confirmed revenues cannot be deleted. Please cancel them instead.'
            ], 422);
        }

        $revenueData = $revenue->toArray();
        $revenue->delete();

        // Dispatch event for real-time updates
        event(new RevenueDeleted($revenue->id, $revenueData, auth()->user()->tenant_id ?? null));

        Log::info('Revenue deleted', [
            'revenue_id' => $revenue->id,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Revenue deleted successfully'
        ]);
    }

    /**
     * Confirm a revenue
     */
    public function confirm($id): JsonResponse
    {
        $revenue = Revenue::findOrFail($id);

        if ($revenue->status === 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Revenue is already confirmed'
            ], 422);
        }

        $revenue->confirm();

        event(new RevenueUpdated($revenue, ['status' => 'confirmed'], auth()->user()->tenant_id ?? null));

        Log::info('Revenue confirmed', [
            'revenue_id' => $revenue->id,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Revenue confirmed successfully',
            'data' => $revenue
        ]);
    }

    /**
     * Cancel a revenue
     */
    public function cancel(Request $request, $id): JsonResponse
    {
        $revenue = Revenue::findOrFail($id);

        if ($revenue->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Revenue is already cancelled'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $revenue->cancel();
        if ($request->has('reason')) {
            $revenue->update(['notes' => ($revenue->notes ? $revenue->notes . "\n\n" : '') . "Cancellation reason: " . $request->reason]);
        }

        event(new RevenueUpdated($revenue, ['status' => 'cancelled'], auth()->user()->tenant_id ?? null));

        Log::info('Revenue cancelled', [
            'revenue_id' => $revenue->id,
            'user_id' => auth()->id(),
            'reason' => $request->reason
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Revenue cancelled successfully',
            'data' => $revenue
        ]);
    }

    /**
     * Get revenue statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $startDate = $request->get('start_date', now()->startOfMonth());
        $endDate = $request->get('end_date', now()->endOfMonth());

        $stats = [
            'total_revenues' => Revenue::whereBetween('revenue_date', [$startDate, $endDate])->count(),
            'total_amount' => Revenue::where('status', 'confirmed')->whereBetween('revenue_date', [$startDate, $endDate])->sum('amount'),
            'by_status' => [
                'pending' => Revenue::where('status', 'pending')->whereBetween('revenue_date', [$startDate, $endDate])->sum('amount'),
                'confirmed' => Revenue::where('status', 'confirmed')->whereBetween('revenue_date', [$startDate, $endDate])->sum('amount'),
                'cancelled' => Revenue::where('status', 'cancelled')->whereBetween('revenue_date', [$startDate, $endDate])->sum('amount'),
            ],
            'by_source' => Revenue::selectRaw('source, SUM(amount) as total')
                ->where('status', 'confirmed')
                ->whereBetween('revenue_date', [$startDate, $endDate])
                ->groupBy('source')
                ->get(),
            'by_payment_method' => Revenue::selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
                ->where('status', 'confirmed')
                ->whereBetween('revenue_date', [$startDate, $endDate])
                ->groupBy('payment_method')
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
