<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Events\EmployeeCreated;
use App\Events\EmployeeUpdated;
use App\Events\EmployeeDeleted;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class EmployeeController extends Controller
{
    /**
     * Display a listing of employees
     */
    public function index(Request $request): JsonResponse
    {
        $query = Employee::with(['department', 'position', 'user']);

        // Filter by department
        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        // Filter by position
        if ($request->has('position_id')) {
            $query->where('position_id', $request->position_id);
        }

        // Filter by employment status
        if ($request->has('employment_status')) {
            $query->where('employment_status', $request->employment_status);
        }

        // Filter by employment type
        if ($request->has('employment_type')) {
            $query->where('employment_type', $request->employment_type);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ILIKE', "%{$search}%")
                  ->orWhere('last_name', 'ILIKE', "%{$search}%")
                  ->orWhere('employee_number', 'ILIKE', "%{$search}%")
                  ->orWhere('email', 'ILIKE', "%{$search}%")
                  ->orWhere('phone', 'ILIKE', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $employees = $query->paginate($perPage);

        return response()->json($employees);
    }

    /**
     * Store a newly created employee
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_number' => 'nullable|string|unique:employees,employee_number',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:employees,email',
            'phone' => 'nullable|string|max:20',
            'national_id' => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'department_id' => 'nullable|uuid|exists:departments,id',
            'position_id' => 'required|uuid|exists:positions,id',
            'employment_type' => 'required|in:full_time,part_time,contract,intern',
            'hire_date' => 'required|date',
            'contract_end_date' => 'nullable|date|after:hire_date',
            'employment_status' => 'nullable|in:active,on_leave,suspended,terminated',
            'salary' => 'nullable|numeric|min:0',
            'salary_currency' => 'nullable|string|size:3',
            'payment_frequency' => 'nullable|in:monthly,bi_weekly,weekly',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_branch' => 'nullable|string|max:255',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'emergency_contact_relationship' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Create employee (NO tenant_id - schema isolation provides tenancy)
        // Employee number auto-generated in model boot method
        $employee = Employee::create($validator->validated());

        // Update department employee count
        if ($employee->department_id) {
            $employee->department->updateEmployeeCount();
        }

        // Dispatch event for real-time updates
        event(new EmployeeCreated($employee, auth()->user()->tenant_id ?? null));

        Log::info('Employee created', [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'name' => $employee->full_name,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee created successfully',
            'data' => $employee->load(['department', 'position', 'user'])
        ], 201);
    }

    /**
     * Display the specified employee
     */
    public function show($id): JsonResponse
    {
        $employee = Employee::with([
            'department',
            'position',
            'user'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $employee
        ]);
    }

    /**
     * Update the specified employee
     */
    public function update(Request $request, $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'employee_number' => 'sometimes|required|string|unique:employees,employee_number,' . $id,
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|unique:employees,email,' . $id,
            'phone' => 'nullable|string|max:20',
            'national_id' => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'department_id' => 'nullable|uuid|exists:departments,id',
            'position_id' => 'sometimes|required|uuid|exists:positions,id',
            'employment_type' => 'sometimes|required|in:full_time,part_time,contract,intern',
            'hire_date' => 'sometimes|required|date',
            'contract_end_date' => 'nullable|date|after:hire_date',
            'employment_status' => 'nullable|in:active,on_leave,suspended,terminated',
            'salary' => 'nullable|numeric|min:0',
            'salary_currency' => 'nullable|string|size:3',
            'payment_frequency' => 'nullable|in:monthly,bi_weekly,weekly',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_branch' => 'nullable|string|max:255',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'emergency_contact_relationship' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $originalData = $employee->toArray();
        $oldDepartmentId = $employee->department_id;
        
        $employee->update($validator->validated());

        // Update department employee counts if department changed
        if ($oldDepartmentId !== $employee->department_id) {
            if ($oldDepartmentId) {
                $oldDepartment = \App\Models\Department::find($oldDepartmentId);
                if ($oldDepartment) {
                    $oldDepartment->updateEmployeeCount();
                }
            }
            if ($employee->department_id) {
                $employee->department->updateEmployeeCount();
            }
        }

        // Dispatch event for real-time updates
        $changes = array_diff_assoc($validator->validated(), $originalData);
        event(new EmployeeUpdated($employee, $changes, auth()->user()->tenant_id ?? null));

        Log::info('Employee updated', [
            'employee_id' => $employee->id,
            'changes' => $changes,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee updated successfully',
            'data' => $employee->load(['department', 'position', 'user'])
        ]);
    }

    /**
     * Remove the specified employee
     */
    public function destroy($id): JsonResponse
    {
        $employee = Employee::findOrFail($id);
        $departmentId = $employee->department_id;
        $employeeData = $employee->toArray();
        
        $employee->delete();

        // Update department employee count
        if ($departmentId) {
            $department = \App\Models\Department::find($departmentId);
            if ($department) {
                $department->updateEmployeeCount();
            }
        }

        // Dispatch event for real-time updates
        event(new EmployeeDeleted($employee->id, $employeeData, auth()->user()->tenant_id ?? null));

        Log::info('Employee deleted', [
            'employee_id' => $employee->id,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee deleted successfully'
        ]);
    }

    /**
     * Get employee statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total' => Employee::count(),
            'active' => Employee::where('employment_status', 'active')->count(),
            'on_leave' => Employee::where('employment_status', 'on_leave')->count(),
            'suspended' => Employee::where('employment_status', 'suspended')->count(),
            'terminated' => Employee::where('employment_status', 'terminated')->count(),
            'by_type' => [
                'full_time' => Employee::where('employment_type', 'full_time')->count(),
                'part_time' => Employee::where('employment_type', 'part_time')->count(),
                'contract' => Employee::where('employment_type', 'contract')->count(),
                'intern' => Employee::where('employment_type', 'intern')->count(),
            ],
            'by_department' => Employee::with('department:id,name')
                ->selectRaw('department_id, COUNT(*) as count')
                ->groupBy('department_id')
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Terminate an employee
     */
    public function terminate(Request $request, $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'termination_date' => 'required|date',
            'reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $employee->update([
            'employment_status' => 'terminated',
            'is_active' => false,
            'contract_end_date' => $request->termination_date,
        ]);

        event(new EmployeeUpdated($employee, ['employment_status' => 'terminated'], auth()->user()->tenant_id ?? null));

        Log::info('Employee terminated', [
            'employee_id' => $employee->id,
            'termination_date' => $request->termination_date,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee terminated successfully',
            'data' => $employee
        ]);
    }
}
