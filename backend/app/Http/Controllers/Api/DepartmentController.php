<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Events\DepartmentCreated;
use App\Events\DepartmentUpdated;
use App\Events\DepartmentDeleted;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class DepartmentController extends Controller
{
    /**
     * Display a listing of departments
     */
    public function index(Request $request): JsonResponse
    {
        $query = Department::with(['manager']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('code', 'ILIKE', "%{$search}%")
                  ->orWhere('location', 'ILIKE', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $departments = $query->paginate($perPage);

        return response()->json($departments);
    }

    /**
     * Store a newly created department
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:departments,code',
            'description' => 'nullable|string',
            'manager_id' => 'nullable|uuid|exists:employees,id',
            'budget' => 'nullable|numeric|min:0',
            'location' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,pending_approval,inactive',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Create department (NO tenant_id - schema isolation provides tenancy)
        $department = Department::create($validator->validated());

        // Dispatch event for real-time updates
        event(new DepartmentCreated($department, auth()->user()->tenant_id ?? null));

        Log::info('Department created', [
            'department_id' => $department->id,
            'name' => $department->name,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Department created successfully',
            'data' => $department->load(['manager'])
        ], 201);
    }

    /**
     * Display the specified department
     */
    public function show($id): JsonResponse
    {
        $department = Department::with([
            'manager',
            'employees' => fn($q) => $q->active()->limit(10),
            'positions' => fn($q) => $q->active()->limit(10)
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $department
        ]);
    }

    /**
     * Update the specified department
     */
    public function update(Request $request, $id): JsonResponse
    {
        $department = Department::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:departments,code,' . $id,
            'description' => 'nullable|string',
            'manager_id' => 'nullable|uuid|exists:employees,id',
            'budget' => 'nullable|numeric|min:0',
            'location' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,pending_approval,inactive',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $originalData = $department->toArray();
        $department->update($validator->validated());

        // Dispatch event for real-time updates
        $changes = array_diff_assoc($validator->validated(), $originalData);
        event(new DepartmentUpdated($department, $changes, auth()->user()->tenant_id ?? null));

        Log::info('Department updated', [
            'department_id' => $department->id,
            'changes' => $changes,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Department updated successfully',
            'data' => $department->load(['manager'])
        ]);
    }

    /**
     * Remove the specified department
     */
    public function destroy($id): JsonResponse
    {
        $department = Department::findOrFail($id);

        // Check if department has employees
        if ($department->employees()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete department with active employees. Please reassign employees first.'
            ], 422);
        }

        $departmentData = $department->toArray();
        $department->delete();

        // Dispatch event for real-time updates
        event(new DepartmentDeleted($department->id, $departmentData, auth()->user()->tenant_id ?? null));

        Log::info('Department deleted', [
            'department_id' => $department->id,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Department deleted successfully'
        ]);
    }

    /**
     * Get department statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total' => Department::count(),
            'active' => Department::where('status', 'active')->count(),
            'pending_approval' => Department::where('status', 'pending_approval')->count(),
            'inactive' => Department::where('status', 'inactive')->count(),
            'total_budget' => Department::where('is_active', true)->sum('budget'),
            'avg_employees_per_dept' => Department::where('is_active', true)->avg('employee_count'),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Approve a department
     */
    public function approve($id): JsonResponse
    {
        $department = Department::findOrFail($id);

        if ($department->status !== 'pending_approval') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending departments can be approved'
            ], 422);
        }

        $department->update([
            'status' => 'active',
            'is_active' => true
        ]);

        event(new DepartmentUpdated($department, ['status' => 'active'], auth()->user()->tenant_id ?? null));

        Log::info('Department approved', [
            'department_id' => $department->id,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Department approved successfully',
            'data' => $department
        ]);
    }
}
