<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use App\Models\TodoActivity;
use App\Events\TodoCreated;
use App\Events\TodoUpdated;
use App\Events\TodoDeleted;
use App\Events\TodoActivityCreated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class TodoController extends Controller
{
    /**
     * Display a listing of todos for the authenticated user
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        
        // ONLY tenant_admin can see ALL todos
        // Other roles only see their own assigned tasks
        $isTenantAdmin = ($user->role === 'tenant_admin');

        $query = Todo::with(['creator', 'user']);

        // ONLY tenant_admin sees ALL todos in their tenant
        if ($isTenantAdmin) {
            Log::info("Tenant admin viewing all todos", [
                'user_id' => $user->id,
                'role' => $user->role
            ]);
            // No filter - tenant admin sees everything
        } 
        // Everyone else only sees their own assigned tasks
        else {
            $query->where('user_id', auth()->id());
            Log::info("User viewing own assigned todos", [
                'user_id' => $user->id,
                'role' => $user->role
            ]);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by priority
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        // Filter by assignee (for tenant admin viewing all)
        if ($request->has('assignee_id') && $isTenantAdmin) {
            if ($request->assignee_id === 'unassigned') {
                $query->whereNull('user_id');
            } else {
                $query->where('user_id', $request->assignee_id);
            }
        }

        $todos = $query->latest()->get();

        return response()->json($todos);
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
            'user_id' => 'nullable|uuid|exists:users,id',
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

        $data = $validator->validated();
        // NO tenant_id needed - schema isolation provides tenancy
        $data['created_by'] = auth()->id();
        
        // If no user_id specified, assign to self
        if (!isset($data['user_id'])) {
            $data['user_id'] = auth()->id();
        }

        $todo = Todo::create($data);
        $todo->load(['creator', 'user']);

        // Log activity
        $activity = TodoActivity::create([
            'todo_id' => $todo->id,
            'user_id' => auth()->id(),
            'action' => 'created',
            'new_value' => $todo->toArray(),
            'description' => 'Todo created',
        ]);

        // Dispatch events for real-time updates
        event(new TodoCreated($todo, auth()->user()->tenant_id ?? null));
        event(new TodoActivityCreated($activity));

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
        $todo = Todo::with(['creator', 'user'])->findOrFail($id);

        $user = auth()->user();
        $isAdmin = $user->role === 'tenant_admin';

        // Check if user has access (assigned user, creator, or admin)
        if ($todo->user_id !== auth()->id() && 
            $todo->created_by !== auth()->id() && 
            !$isAdmin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($todo);
    }

    /**
     * Update the specified todo
     */
    public function update(Request $request, $id)
    {
        $todo = Todo::findOrFail($id);

        // Prevent editing completed todos
        if ($todo->status === 'completed' && !$request->has('status')) {
            return response()->json([
                'message' => 'Cannot edit completed todos'
            ], 422);
        }

        $user = auth()->user();
        $isAdmin = $user->role === 'tenant_admin';

        // Check if user has access
        if ($todo->user_id !== auth()->id() && 
            $todo->created_by !== auth()->id() && 
            !$isAdmin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'sometimes|required|in:low,medium,high',
            'status' => 'sometimes|required|in:pending,in_progress,completed',
            'due_date' => 'nullable|date',
            'user_id' => 'nullable|uuid|exists:users,id',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $originalData = $todo->toArray();
        
        // If marking as completed, set completed_at
        if (isset($data['status']) && $data['status'] === 'completed' && $todo->status !== 'completed') {
            $data['completed_at'] = now();
        }

        $todo->update($data);
        $todo->load(['creator', 'user']);

        // Log activity
        $changes = array_diff_assoc($todo->toArray(), $originalData);
        if (!empty($changes)) {
            $action = isset($data['status']) && $data['status'] === 'completed' ? 'completed' : 'updated';
            $description = isset($data['status']) && $data['status'] === 'completed' 
                ? 'Todo marked as completed' 
                : 'Todo updated';

            $activity = TodoActivity::create([
                'todo_id' => $todo->id,
                'user_id' => auth()->id(),
                'action' => $action,
                'old_value' => $originalData,
                'new_value' => $todo->toArray(),
                'description' => $description,
            ]);

            event(new TodoActivityCreated($activity));
        }

        // Dispatch event for real-time updates
        event(new TodoUpdated($todo, $changes, auth()->user()->tenant_id ?? null));

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
        $todo = Todo::findOrFail($id);

        // Prevent deleting completed todos
        if ($todo->status === 'completed') {
            return response()->json([
                'message' => 'Cannot delete completed todos'
            ], 422);
        }

        $user = auth()->user();
        $isAdmin = $user->role === 'tenant_admin';

        // Check if user has access
        if ($todo->user_id !== auth()->id() && 
            $todo->created_by !== auth()->id() && 
            !$isAdmin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Store data for event before deletion
        $todoId = $todo->id;
        $userId = $todo->user_id;
        $tenantId = $todo->user->tenant_id;

        $todo->delete();

        // Dispatch event for real-time updates
        event(new TodoDeleted($todoId, $userId, $tenantId));

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
        $userId = auth()->id();
        $isTenantAdmin = $user->role === 'tenant_admin';

        // Tenant admin can view tenant-wide statistics
        if ($request->boolean('tenant_wide') && $isTenantAdmin) {
            $stats = [
                'total' => Todo::count(),
                'pending' => Todo::where('status', 'pending')->count(),
                'in_progress' => Todo::where('status', 'in_progress')->count(),
                'completed' => Todo::where('status', 'completed')->count(),
                'unassigned' => Todo::whereNull('user_id')->where('status', '!=', 'completed')->count(),
                'overdue' => Todo::where('status', '!=', 'completed')
                    ->where('due_date', '<', now())
                    ->count(),
                'by_assignee' => Todo::selectRaw('user_id, COUNT(*) as count')
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->groupBy('user_id')
                    ->with('user:id,name,email')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'user_id' => $item->user_id,
                            'user_name' => $item->user ? $item->user->name : 'Unassigned',
                            'count' => $item->count
                        ];
                    }),
            ];
        } else {
            // Regular user statistics
            $stats = [
                'total' => Todo::where('user_id', $userId)->count(),
                'pending' => Todo::where('user_id', $userId)->where('status', 'pending')->count(),
                'in_progress' => Todo::where('user_id', $userId)->where('status', 'in_progress')->count(),
                'completed' => Todo::where('user_id', $userId)->where('status', 'completed')->count(),
                'overdue' => Todo::where('user_id', $userId)
                    ->where('status', '!=', 'completed')
                    ->where('due_date', '<', now())
                    ->count(),
            ];
        }

        return response()->json($stats);
    }

    /**
     * Mark todo as completed
     */
    public function markAsCompleted($id)
    {
        $todo = Todo::findOrFail($id);

        // Check if user has access
        if ($todo->user_id !== auth()->id() && $todo->created_by !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $originalData = $todo->toArray();
        $todo->markAsCompleted();

        // Log activity
        $activity = TodoActivity::create([
            'todo_id' => $todo->id,
            'user_id' => auth()->id(),
            'action' => 'completed',
            'old_value' => $originalData,
            'new_value' => $todo->toArray(),
            'description' => 'Todo marked as completed',
        ]);

        event(new TodoActivityCreated($activity));
        event(new TodoUpdated($todo, ['status' => 'completed', 'completed_at' => $todo->completed_at], auth()->user()->tenant_id ?? null));

        return response()->json([
            'message' => 'Todo marked as completed',
            'todo' => $todo->fresh(['creator', 'user'])
        ]);
    }

    /**
     * Assign a todo to a user
     */
    public function assign(Request $request, $id)
    {
        $todo = Todo::findOrFail($id);

        // Only admins can assign tasks
        $user = auth()->user();
        if ($user->role !== 'tenant_admin') {
            return response()->json(['message' => 'Unauthorized. Only admins can assign tasks.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|uuid|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $originalData = $todo->toArray();
        $todo->user_id = $request->user_id;
        $todo->save();
        $todo->load(['creator', 'user']);

        // Log activity
        $activity = TodoActivity::create([
            'todo_id' => $todo->id,
            'user_id' => auth()->id(),
            'action' => 'assigned',
            'old_value' => $originalData,
            'new_value' => $todo->toArray(),
            'description' => 'Todo assigned to ' . $todo->user->name,
        ]);

        event(new TodoActivityCreated($activity));
        event(new TodoUpdated($todo, ['user_id' => $request->user_id], auth()->user()->tenant_id ?? null));

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
        $todo = Todo::findOrFail($id);

        $user = auth()->user();
        $isAdmin = $user->role === 'tenant_admin';

        // Check if user has access
        if ($todo->user_id !== auth()->id() && 
            $todo->created_by !== auth()->id() && 
            !$isAdmin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $activities = $todo->activities()->with('user:id,name,email')->get();

        return response()->json($activities);
    }
}
