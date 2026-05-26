<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use App\Models\TodoActivity;
use App\Events\TodoCreated;
use App\Events\TodoUpdated;
use App\Events\TodoDeleted;
use App\Events\TodoActivityCreated;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class TodoController extends Controller
{
    private const LIST_CACHE_TTL_SECONDS = 15;
    private const LIST_VERSION_PREFIX = 'todo_list_version:';

    /**
     * Display a listing of todos for the authenticated user
     */
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $cacheKey = $this->todoListCacheKey($user, $request);
            $todos = Cache::remember($cacheKey, self::LIST_CACHE_TTL_SECONDS, function () use ($request, $user) {
                $userId = $user->id;
                $isTenantAdmin = $this->isTenantAdmin($user);

                $query = Todo::select([
                        'id', 'user_id', 'created_by', 'title', 'description',
                        'priority', 'status', 'due_date', 'completed_at',
                        'related_type', 'related_id', 'created_at', 'updated_at', 'deleted_at',
                    ])->with([
                        'creator:id,name,email',
                        'user:id,name,email',
                    ]);

                if (!$isTenantAdmin) {
                    $query->where('user_id', $userId);
                }

                if ($request->has('status')) {
                    $query->where('status', $request->status);
                }

                if ($request->has('priority')) {
                    $query->where('priority', $request->priority);
                }

                if ($request->has('assignee_id') && $isTenantAdmin) {
                    if ($request->assignee_id === 'unassigned') {
                        $query->whereNull('user_id');
                    } else {
                        $query->where('user_id', $request->assignee_id);
                    }
                }

                return $query->latest()->get();
            });

            return response()->json($todos)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
                ->header('Pragma', 'no-cache');
        } catch (\Throwable $e) {
            Log::error('Failed to fetch todos', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Failed to fetch todos',
            ], 500);
        }
    }

    /**
     * Store a newly created todo
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'required|in:low,medium,high',
            'status' => 'nullable|in:pending,in_progress,completed',
            'due_date' => 'nullable|date',
            'user_id' => 'nullable|uuid|exists:public.users,id',
            'related_type' => 'nullable|string',
            'related_id' => 'nullable|uuid',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();
        $userId = $user->id;

        $data = $validator->validated();
        // NO tenant_id needed - schema isolation provides tenancy
        $data['created_by'] = $userId;
        
        // If no user_id specified, assign to self
        if (!isset($data['user_id'])) {
            $data['user_id'] = $userId;
        }

        $todo = Todo::create($data);
        $todo->load(['creator:id,name,email', 'user:id,name,email']);

        // Log activity
        $activity = TodoActivity::create([
            'todo_id' => $todo->id,
            'user_id' => $userId,
            'action' => 'created',
            'new_value' => $todo->toArray(),
            'description' => 'Todo created',
        ]);

        // Dispatch events for real-time updates
        event(new TodoCreated($todo, $user->tenant_id ?? null));
        event(new TodoActivityCreated($activity, $user->tenant_id ?? null));
        $this->bustTodoCache($user, (string) $todo->user_id, (string) ($user->tenant_id ?? ''));

        return response()->json([
            'message' => 'Todo created successfully',
            'todo' => $todo
        ], 201);
    }

    /**
     * Display the specified todo
     */
    public function show($id)
    {
        $user = auth()->user();
        $userId = $user->id;
        $todo = Todo::with(['creator:id,name,email', 'user:id,name,email'])->findOrFail($id);

        if ($todo->user_id !== $userId && 
            $todo->created_by !== $userId && 
            !$this->isTenantAdmin($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($todo);
    }

    /**
     * Update the specified todo
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        $userId = $user->id;
        $todo = Todo::findOrFail($id);

        // Prevent editing completed todos
        if ($todo->status === 'completed' && !$request->has('status')) {
            return response()->json([
                'message' => 'Cannot edit completed todos'
            ], 422);
        }

        // Check if user has access
        if ($todo->user_id !== $userId && 
            $todo->created_by !== $userId && 
            !$this->isTenantAdmin($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'sometimes|required|in:low,medium,high',
            'status' => 'sometimes|required|in:pending,in_progress,completed',
            'due_date' => 'nullable|date',
            'user_id' => 'nullable|uuid|exists:public.users,id',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        // If marking as completed, set completed_at
        if (isset($data['status']) && $data['status'] === 'completed' && $todo->status !== 'completed') {
            $data['completed_at'] = now();
        }

        $originalData = $todo->toArray();
        $previousUserId = (string) $todo->user_id;
        $todo->fill($data);
        $changes = $todo->getDirty();
        $todo->save();
        $todo->load(['creator:id,name,email', 'user:id,name,email']);

        // Log activity
        if (!empty($changes)) {
            $action = isset($data['status']) && $data['status'] === 'completed' ? 'completed' : 'updated';
            $description = isset($data['status']) && $data['status'] === 'completed' 
                ? 'Todo marked as completed' 
                : 'Todo updated';

            $activity = TodoActivity::create([
                'todo_id' => $todo->id,
                'user_id' => $userId,
                'action' => $action,
                'old_value' => $originalData,
                'new_value' => $todo->toArray(),
                'description' => $description,
            ]);

            event(new TodoActivityCreated($activity, $user->tenant_id ?? null));
        }

        // Dispatch event for real-time updates
        event(new TodoUpdated($todo, $changes, $user->tenant_id ?? null));
        $this->bustTodoCache($user, (string) $todo->user_id, (string) ($user->tenant_id ?? ''), $previousUserId);

        return response()->json([
            'message' => 'Todo updated successfully',
            'todo' => $todo
        ]);
    }

    /**
     * Remove the specified todo
     */
    public function destroy($id)
    {
        $user = auth()->user();
        $userId = $user->id;
        $todo = Todo::findOrFail($id);

        // Prevent deleting completed todos
        if ($todo->status === 'completed') {
            return response()->json([
                'message' => 'Cannot delete completed todos'
            ], 422);
        }

        // Check if user has access
        if ($todo->user_id !== $userId && 
            $todo->created_by !== $userId && 
            !$this->isTenantAdmin($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Store data for event before deletion
        $todoId = $todo->id;
        $assignedUserId = $todo->user_id;
        $tenantId = $user->tenant_id;

        $todo->delete();

        // Dispatch event for real-time updates
        event(new TodoDeleted($todoId, $assignedUserId, $tenantId));
        $this->bustTodoCache($user, (string) $assignedUserId, (string) $tenantId);

        return response()->json([
            'message' => 'Todo deleted successfully'
        ]);
    }

    /**
     * Get todo statistics
     */
    public function statistics(Request $request)
    {
        $user = auth()->user();
        $userId = $user->id;
        $isTenantAdmin = $this->isTenantAdmin($user);
        $scope = $isTenantAdmin && $request->boolean('tenant_wide')
            ? 'tenant:' . (string) ($user->tenant_id ?? $user->id)
            : 'user:' . (string) $userId;
        $cacheKey = 'todo_stats:' . $scope . ':v' . $this->todoListVersion($scope) . ':' . ($request->boolean('tenant_wide') ? 'tenant' : 'user');

        $stats = Cache::remember($cacheKey, self::LIST_CACHE_TTL_SECONDS, function () use ($userId, $isTenantAdmin, $request) {
            // Tenant admin can view tenant-wide statistics
            if ($request->boolean('tenant_wide') && $isTenantAdmin) {
                // Single aggregated query for all counts
                $counts = Todo::selectRaw(
                    "COUNT(*) as total,
                    COUNT(*) FILTER (WHERE status = 'pending') as pending,
                    COUNT(*) FILTER (WHERE status = 'in_progress') as in_progress,
                    COUNT(*) FILTER (WHERE status = 'completed') as completed,
                    COUNT(*) FILTER (WHERE user_id IS NULL AND status != 'completed') as unassigned,
                    COUNT(*) FILTER (WHERE status != 'completed' AND due_date < NOW()) as overdue"
                )->first();

                // Single JOIN query for by_assignee (no N+1)
                $byAssignee = Todo::selectRaw('todos.user_id, COUNT(*) as count, u.name as user_name')
                    ->leftJoin('public.users as u', 'todos.user_id', '=', 'u.id')
                    ->whereIn('todos.status', ['pending', 'in_progress'])
                    ->groupBy('todos.user_id', 'u.name')
                    ->get()
                    ->map(fn ($item) => [
                        'user_id'   => $item->user_id,
                        'user_name' => $item->user_name ?? 'Unassigned',
                        'count'     => (int) $item->count,
                    ]);

                return [
                    'total'      => (int) $counts->total,
                    'pending'    => (int) $counts->pending,
                    'in_progress'=> (int) $counts->in_progress,
                    'completed'  => (int) $counts->completed,
                    'unassigned' => (int) $counts->unassigned,
                    'overdue'    => (int) $counts->overdue,
                    'by_assignee'=> $byAssignee,
                ];
            }

            // Single aggregated query for current user
            $counts = Todo::selectRaw(
                "COUNT(*) as total,
                COUNT(*) FILTER (WHERE status = 'pending') as pending,
                COUNT(*) FILTER (WHERE status = 'in_progress') as in_progress,
                COUNT(*) FILTER (WHERE status = 'completed') as completed,
                COUNT(*) FILTER (WHERE status != 'completed' AND due_date < NOW()) as overdue"
            )->where('user_id', $userId)->first();

            return [
                'total'       => (int) $counts->total,
                'pending'     => (int) $counts->pending,
                'in_progress' => (int) $counts->in_progress,
                'completed'   => (int) $counts->completed,
                'overdue'     => (int) $counts->overdue,
            ];
        });

        return response()->json($stats);
    }

    /**
     * Mark todo as completed
     */
    public function markAsCompleted($id)
    {
        $user = auth()->user();
        $userId = $user->id;
        $todo = Todo::findOrFail($id);

        // Check if user has access
        if ($todo->user_id !== $userId && $todo->created_by !== $userId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $originalData = $todo->toArray();
        $todo->markAsCompleted();

        // Log activity
        $activity = TodoActivity::create([
            'todo_id' => $todo->id,
            'user_id' => $userId,
            'action' => 'completed',
            'old_value' => $originalData,
            'new_value' => $todo->toArray(),
            'description' => 'Todo marked as completed',
        ]);

        event(new TodoActivityCreated($activity, $user->tenant_id ?? null));
        event(new TodoUpdated($todo, ['status' => 'completed', 'completed_at' => $todo->completed_at], $user->tenant_id ?? null));
        $this->bustTodoCache($user, (string) $todo->user_id, (string) ($user->tenant_id ?? ''));

        $todo->load(['creator:id,name,email', 'user:id,name,email']);

        return response()->json([
            'message' => 'Todo marked as completed',
            'todo' => $todo
        ]);
    }

    /**
     * Assign a todo to a user
     */
    public function assign(Request $request, $id)
    {
        $user = auth()->user();
        $userId = $user->id;

        if (!$this->isTenantAdmin($user)) {
            return response()->json(['message' => 'Unauthorized. Only admins can assign tasks.'], 403);
        }

        $todo = Todo::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|uuid|exists:public.users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $originalData = $todo->toArray();
        $previousUserId = (string) $todo->user_id;
        $todo->user_id = $request->user_id;
        $todo->save();
        $todo->load(['creator:id,name,email', 'user:id,name,email']);

        // Log activity
        $activity = TodoActivity::create([
            'todo_id' => $todo->id,
            'user_id' => $userId,
            'action' => 'assigned',
            'old_value' => $originalData,
            'new_value' => $todo->toArray(),
            'description' => 'Todo assigned to ' . $todo->user->name,
        ]);

        event(new TodoActivityCreated($activity, $user->tenant_id ?? null));
        event(new TodoUpdated($todo, ['user_id' => $request->user_id], $user->tenant_id ?? null));
        $this->bustTodoCache($user, (string) $todo->user_id, (string) ($user->tenant_id ?? ''), $previousUserId);

        return response()->json([
            'message' => 'Todo assigned successfully',
            'todo' => $todo
        ]);
    }

    /**
     * Get activities for a specific todo
     */
    public function activities($id)
    {
        $user = auth()->user();
        $userId = $user->id;
        $todo = Todo::findOrFail($id);

        // Check if user has access
        if ($todo->user_id !== $userId && 
            $todo->created_by !== $userId && 
            !$this->isTenantAdmin($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $activities = $todo->activities()->with('user:id,name,email')->get();

        return response()->json($activities);
    }

    private function isTenantAdmin($user): bool
    {
        if (!$user) {
            return false;
        }

        return $user->role === User::ROLE_ADMIN;
    }

    private function todoListCacheKey(User $user, Request $request): string
    {
        $scope = $this->isTenantAdmin($user)
            ? 'tenant:' . (string) ($user->tenant_id ?? $user->id)
            : 'user:' . (string) $user->id;

        $filters = implode('|', [
            'status=' . ($request->input('status', '*') ?? '*'),
            'priority=' . ($request->input('priority', '*') ?? '*'),
            'assignee=' . ($request->input('assignee_id', '*') ?? '*'),
        ]);

        return 'todos:' . $scope . ':v' . $this->todoListVersion($scope) . ':' . md5($filters);
    }

    private function todoListVersion(string $scope): int
    {
        return (int) Cache::rememberForever(self::LIST_VERSION_PREFIX . $scope, static fn (): int => 1);
    }

    private function bustTodoCache(User $user, ?string $todoUserId = null, ?string $tenantId = null, ?string $previousTodoUserId = null): void
    {
        $scopes = [
            'user:' . (string) $user->id,
            'tenant:' . (string) ($tenantId ?: $user->tenant_id ?: $user->id),
        ];

        if (!empty($todoUserId)) {
            $scopes[] = 'user:' . $todoUserId;
        }

        if (!empty($previousTodoUserId)) {
            $scopes[] = 'user:' . $previousTodoUserId;
        }

        foreach (array_unique($scopes) as $scope) {
            Cache::forever(self::LIST_VERSION_PREFIX . $scope, $this->todoListVersion($scope) + 1);
        }
    }
}
