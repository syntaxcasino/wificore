<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Events\PositionCreated;
use App\Events\PositionUpdated;
use App\Events\PositionDeleted;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PositionController extends Controller
{
    private function bustStatsCache(): void
    {
        $tenantId = auth()->user()->tenant_id ?? 'global';
        Cache::forget("position_stats_{$tenantId}");
    }

    /**
     * Display a listing of positions
     * OPTIMIZED: Selective eager loading with specific columns
     */
    public function index(Request $request): JsonResponse
    {
        $query = Position::with(['department:id,name'])
            ->select(['id', 'code', 'title', 'description', 'department_id', 'level', 'is_active', 'created_at']);

        // Filter by department
        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        // Filter by level
        if ($request->has('level')) {
            $query->where('level', $request->level);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ILIKE', "%{$search}%")
                  ->orWhere('code', 'ILIKE', "%{$search}%")
                  ->orWhere('description', 'ILIKE', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $positions = $query->paginate($perPage);

        return response()->json($positions);
    }

    /**
     * Store a newly created position
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:positions,code',
            'description' => 'nullable|string',
            'department_id' => 'nullable|uuid|exists:departments,id',
            'level' => 'nullable|string|max:255',
            'min_salary' => 'nullable|numeric|min:0',
            'max_salary' => 'nullable|numeric|min:0|gte:min_salary',
            'requirements' => 'nullable|string',
            'responsibilities' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Create position (NO tenant_id - schema isolation provides tenancy)
        $position = Position::create($validator->validated());

        // Dispatch event for real-time updates
        event(new PositionCreated($position, auth()->user()->tenant_id ?? null));
        $this->bustStatsCache();

        Log::info('Position created', [
            'position_id' => $position->id,
            'title' => $position->title,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Position created successfully',
            'data' => $position->load(['department'])
        ], 201);
    }

    /**
     * Display the specified position
     * OPTIMIZED: Selective eager loading with specific columns
     */
    public function show($id): JsonResponse
    {
        $position = Position::with([
            'department:id,name,manager_id',
            'employees' => fn($q) => $q->active()->limit(10)
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $position
        ]);
    }

    /**
     * Update the specified position
     */
    public function update(Request $request, $id): JsonResponse
    {
        $position = Position::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:positions,code,' . $id,
            'description' => 'nullable|string',
            'department_id' => 'nullable|uuid|exists:departments,id',
            'level' => 'nullable|string|max:255',
            'min_salary' => 'nullable|numeric|min:0',
            'max_salary' => 'nullable|numeric|min:0|gte:min_salary',
            'requirements' => 'nullable|string',
            'responsibilities' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $originalData = $position->toArray();
        $position->update($validator->validated());

        // Dispatch event for real-time updates
        $changes = array_diff_assoc($validator->validated(), $originalData);
        event(new PositionUpdated($position, $changes, auth()->user()->tenant_id ?? null));
        $this->bustStatsCache();

        Log::info('Position updated', [
            'position_id' => $position->id,
            'changes' => $changes,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Position updated successfully',
            'data' => $position->load(['department'])
        ]);
    }

    /**
     * Remove the specified position
     */
    public function destroy($id): JsonResponse
    {
        $position = Position::findOrFail($id);

        // Check if position has employees
        if ($position->employees()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete position with active employees. Please reassign employees first.'
            ], 422);
        }

        $positionData = $position->toArray();
        $position->delete();

        // Dispatch event for real-time updates
        event(new PositionDeleted($position->id, $positionData, auth()->user()->tenant_id ?? null));
        $this->bustStatsCache();

        Log::info('Position deleted', [
            'position_id' => $position->id,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Position deleted successfully'
        ]);
    }

    /**
     * Get position statistics
     */
    public function statistics(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id ?? 'global';

        $stats = Cache::remember("position_stats_{$tenantId}", 60, function () {
            $agg = Position::selectRaw("
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE is_active = true)  as active,
                COUNT(*) FILTER (WHERE is_active = false) as inactive
            ")->first();

            return [
                'total'    => (int) ($agg->total    ?? 0),
                'active'   => (int) ($agg->active   ?? 0),
                'inactive' => (int) ($agg->inactive ?? 0),
                'by_level' => Position::selectRaw('level, COUNT(*) as count')
                    ->groupBy('level')
                    ->get(),
                'by_department' => Position::with('department:id,name')
                    ->selectRaw('department_id, COUNT(*) as count')
                    ->groupBy('department_id')
                    ->get(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
