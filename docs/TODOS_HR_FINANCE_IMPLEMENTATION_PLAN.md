# Todos, HR & Finance Module Implementation Plan
## WiFi Hotspot System - Based on Livestock Management Architecture
**Date**: December 7, 2025 - 8:30 AM

---

## 🎯 **Objective**

Implement Todos, HR (Human Resources), and Finance modules in the WiFi Hotspot system using the **exact same architecture** as the Livestock Management system, including:

1. **Event-Based System** - Real-time updates via WebSocket
2. **Slide Overlay Forms** - NOT modals
3. **Multi-Tenancy** - Schema-based isolation
4. **Activity Logging** - Full audit trail
5. **Same UI/UX** - Consistent design patterns

---

## 📋 **Module 1: Todos System**

### **Backend Implementation**

#### **1.1 Database Migrations**

```php
// 2025_12_07_000001_create_todos_table.php
Schema::create('todos', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->uuid('user_id')->nullable(); // Assigned to
    $table->uuid('created_by'); // Creator
    $table->string('title');
    $table->text('description')->nullable();
    $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
    $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
    $table->date('due_date')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->string('related_type')->nullable(); // Polymorphic
    $table->uuid('related_id')->nullable();
    $table->json('metadata')->nullable();
    $table->timestamps();
    $table->softDeletes();
    
    $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
    $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
    $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
    
    $table->index(['tenant_id', 'status']);
    $table->index(['user_id', 'status']);
});

// 2025_12_07_000002_create_todo_activities_table.php
Schema::create('todo_activities', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('todo_id');
    $table->uuid('user_id');
    $table->string('action'); // created, updated, completed, assigned, deleted
    $table->json('old_value')->nullable();
    $table->json('new_value')->nullable();
    $table->text('description')->nullable();
    $table->timestamps();
    
    $table->foreign('todo_id')->references('id')->on('todos')->onDelete('cascade');
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
});
```

#### **1.2 Models**

```php
// app/Models/Todo.php
class Todo extends Model
{
    use HasFactory, SoftDeletes, HasUuid, BelongsToTenant;
    
    protected $fillable = [
        'user_id', 'created_by', 'title', 'description',
        'priority', 'status', 'due_date', 'completed_at',
        'related_type', 'related_id', 'metadata'
    ];
    
    protected $casts = [
        'id' => 'string',
        'user_id' => 'string',
        'created_by' => 'string',
        'related_id' => 'string',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];
    
    // Relationships
    public function user() { return $this->belongsTo(User::class, 'user_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function related() { return $this->morphTo(); }
    public function activities() { return $this->hasMany(TodoActivity::class); }
    
    // Scopes
    public function scopePending($query) { return $query->where('status', 'pending'); }
    public function scopeCompleted($query) { return $query->where('status', 'completed'); }
    
    // Methods
    public function markAsCompleted() {
        $this->status = 'completed';
        $this->completed_at = now();
        return $this->save();
    }
}

// app/Models/TodoActivity.php
class TodoActivity extends Model
{
    use HasFactory, HasUuid;
    
    protected $fillable = [
        'todo_id', 'user_id', 'action',
        'old_value', 'new_value', 'description'
    ];
    
    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
    ];
    
    public function todo() { return $this->belongsTo(Todo::class); }
    public function user() { return $this->belongsTo(User::class); }
}
```

#### **1.3 Events (Real-Time)**

```php
// app/Events/TodoCreated.php
class TodoCreated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, InteractsWithQueue;
    
    public $connection = 'database';
    public $queue = 'broadcasts';
    public $todoData;
    public $tenantId;
    
    public function __construct(Todo $todo, ?string $tenantId = null) {
        $this->tenantId = $tenantId ?? $todo->user?->tenant_id;
        $this->todoData = [
            'id' => $todo->id,
            'title' => $todo->title,
            'description' => $todo->description,
            'priority' => $todo->priority,
            'status' => $todo->status,
            'due_date' => $todo->due_date,
            'user_id' => $todo->user_id,
            'tenant_id' => $this->tenantId,
            'creator' => $todo->creator ? [
                'id' => $todo->creator->id,
                'name' => $todo->creator->name,
            ] : null,
            'user' => $todo->user ? [
                'id' => $todo->user->id,
                'name' => $todo->user->name,
            ] : null,
            'created_at' => $todo->created_at->toIso8601String(),
        ];
    }
    
    public function broadcastOn(): array {
        $channels = [];
        if ($this->tenantId) {
            $channels[] = new PrivateChannel('tenant.' . $this->tenantId . '.todos');
        }
        if (!empty($this->todoData['user_id'])) {
            $channels[] = new PrivateChannel('user.' . $this->todoData['user_id'] . '.todos');
        }
        return $channels;
    }
    
    public function broadcastAs(): string { return 'todo.created'; }
    public function broadcastWith(): array {
        return [
            'todo' => $this->todoData,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}

// app/Events/TodoUpdated.php
// app/Events/TodoDeleted.php
// app/Events/TodoActivityCreated.php
// Similar structure to TodoCreated
```

#### **1.4 Controller**

```php
// app/Http/Controllers/Api/TodoController.php
class TodoController extends Controller
{
    public function index(Request $request) {
        $user = auth()->user();
        $isTenantAdmin = $user->role === 'tenant_admin';
        
        $query = Todo::with(['creator', 'user']);
        
        // Tenant admin sees all, others see only assigned
        if (!$isTenantAdmin) {
            $query->where('user_id', auth()->id());
        }
        
        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        return response()->json($query->latest()->get());
    }
    
    public function store(Request $request) {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'required|in:low,medium,high',
            'status' => 'nullable|in:pending,in_progress,completed',
            'due_date' => 'nullable|date',
            'user_id' => 'nullable|uuid|exists:users,id',
        ]);
        
        $validated['tenant_id'] = auth()->user()->tenant_id;
        $validated['created_by'] = auth()->id();
        $validated['user_id'] = $validated['user_id'] ?? auth()->id();
        
        $todo = Todo::create($validated);
        $todo->load(['creator', 'user']);
        
        // Log activity
        TodoActivity::create([
            'todo_id' => $todo->id,
            'user_id' => auth()->id(),
            'action' => 'created',
            'new_value' => $todo->toArray(),
            'description' => 'Todo created',
        ]);
        
        // Broadcast events
        event(new TodoCreated($todo, auth()->user()->tenant_id));
        
        return response()->json(['message' => 'Todo created', 'todo' => $todo], 201);
    }
    
    public function update(Request $request, $id) {
        $todo = Todo::findOrFail($id);
        
        // Authorization check
        // Validation
        // Update logic
        // Activity logging
        // Event broadcasting
        
        event(new TodoUpdated($todo, $changes));
        return response()->json(['message' => 'Todo updated', 'todo' => $todo]);
    }
    
    public function destroy($id) {
        $todo = Todo::findOrFail($id);
        // Authorization
        // Delete
        event(new TodoDeleted($todoId, $userId, $tenantId));
        return response()->json(['message' => 'Todo deleted']);
    }
    
    public function statistics(Request $request) {
        // Return stats for dashboard
    }
    
    public function activities($id) {
        $todo = Todo::findOrFail($id);
        return response()->json($todo->activities()->with('user')->get());
    }
}
```

#### **1.5 Routes**

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'tenant.context'])->group(function () {
    Route::prefix('admin')->group(function () {
        Route::apiResource('todos', TodoController::class);
        Route::get('todos/{id}/activities', [TodoController::class, 'activities']);
        Route::get('todos/statistics', [TodoController::class, 'statistics']);
        Route::post('todos/{id}/assign', [TodoController::class, 'assign']);
        Route::post('todos/{id}/complete', [TodoController::class, 'markAsCompleted']);
    });
});
```

---

### **Frontend Implementation**

#### **2.1 Composable (Event-Driven)**

```javascript
// frontend/src/composables/useTodos.js
import { ref, computed } from 'vue'
import axios from '@/services/api/axios'
import { getTenantStore } from '@/services/websocket/secureEventHandlers'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

export function useTodos() {
  const loading = ref(false)
  const error = ref(null)
  const { toast } = useToast()
  const authStore = useAuthStore()
  
  // Get tenant-isolated store (event-driven, reactive)
  const tenantStore = computed(() => getTenantStore(authStore.tenantId))
  
  // Reactive computed properties from secure tenant store
  const todos = computed(() => tenantStore.value?.todos || [])
  const stats = computed(() => tenantStore.value?.stats?.todos || {})
  
  // Computed filters
  const pendingTodos = computed(() => 
    todos.value.filter(todo => todo.status === 'pending')
  )
  
  const completedTodos = computed(() => 
    todos.value.filter(todo => todo.status === 'completed')
  )
  
  const inProgressTodos = computed(() => 
    todos.value.filter(todo => todo.status === 'in_progress')
  )
  
  // API Functions (trigger events, no polling needed)
  const fetchTodos = async () => {
    loading.value = true
    try {
      const response = await axios.get('/admin/todos')
      const store = tenantStore.value
      if (store) {
        store.todos = response.data
        updateStats()
      }
    } catch (err) {
      error.value = err.response?.data?.message
      toast.error(error.value)
    } finally {
      loading.value = false
    }
  }
  
  const createTodo = async (todoData) => {
    loading.value = true
    try {
      const response = await axios.post('/admin/todos', todoData)
      toast.success('Todo created successfully')
      return response.data.todo
    } catch (err) {
      error.value = err.response?.data?.message
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }
  
  const updateTodo = async (todoId, updates) => {
    loading.value = true
    try {
      const response = await axios.put(`/admin/todos/${todoId}`, updates)
      toast.success('Todo updated successfully')
      return response.data.todo
    } catch (err) {
      error.value = err.response?.data?.message
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }
  
  const deleteTodo = async (todoId) => {
    loading.value = true
    try {
      await axios.delete(`/admin/todos/${todoId}`)
      toast.success('Todo deleted successfully')
    } catch (err) {
      error.value = err.response?.data?.message
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }
  
  const updateStats = () => {
    const store = tenantStore.value
    if (!store) return
    
    const total = store.todos.length
    const completed = store.todos.filter(t => t.status === 'completed').length
    const pending = store.todos.filter(t => t.status === 'pending').length
    const in_progress = store.todos.filter(t => t.status === 'in_progress').length
    
    Object.assign(store.stats.todos, {
      total, completed, pending, in_progress
    })
  }
  
  return {
    todos, stats, pendingTodos, completedTodos, inProgressTodos,
    loading, error,
    fetchTodos, createTodo, updateTodo, deleteTodo,
    updateStats
  }
}
```

#### **2.2 Vue Component (Slide Overlay)**

```vue
<!-- frontend/src/modules/tenant/views/TodosView.vue -->
<template>
  <PageContainer>
    <PageHeader
      title="My Todos"
      subtitle="Manage your tasks and priorities"
      icon="CheckSquare"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="openCreateForm" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Add Todo
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Stats Cards -->
    <div class="px-6 py-4 bg-slate-50 border-b border-slate-200">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <StatCard title="Total Tasks" :value="todos.length" icon="ListTodo" color="blue" />
        <StatCard title="Pending" :value="pendingTodos.length" icon="Clock" color="orange" />
        <StatCard title="In Progress" :value="inProgressTodos.length" icon="PlayCircle" color="blue" />
        <StatCard title="Completed" :value="completedTodos.length" icon="CheckCircle2" color="green" />
      </div>
    </div>

    <!-- Filters -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-2">
        <button
          v-for="filter in filters"
          :key="filter.value"
          @click="activeFilter = filter.value"
          class="px-4 py-2 rounded-lg text-sm font-medium transition-all"
          :class="activeFilter === filter.value 
            ? 'bg-blue-100 text-blue-700' 
            : 'text-slate-600 hover:bg-slate-100'"
        >
          {{ filter.label }}
          <span class="ml-1 px-2 py-0.5 rounded-full text-xs">
            {{ getFilterCount(filter.value) }}
          </span>
        </button>
      </div>
    </div>

    <!-- Todo List -->
    <PageContent>
      <div v-if="loading" class="space-y-4">
        <BaseLoading type="card" :rows="3" />
      </div>

      <div v-else-if="filteredTodos.length === 0" class="text-center py-12">
        <BaseEmpty
          title="No todos found"
          description="Create your first todo to get started"
          icon="CheckSquare"
          actionText="Add Todo"
          @action="openCreateForm"
        />
      </div>

      <div v-else class="space-y-3">
        <TodoCard
          v-for="todo in filteredTodos"
          :key="todo.id"
          :todo="todo"
          @edit="openEditForm"
          @delete="deleteTodo"
          @view="viewTodoDetails"
          @start="markAsInProgress"
          @complete="markAsCompleted"
        />
      </div>
    </PageContent>

    <!-- Slide Overlay for Create/Edit -->
    <SlideOverlay
      v-model="showForm"
      :title="isEdit ? 'Edit Todo' : 'Create New Todo'"
      :subtitle="isEdit ? 'Update todo details' : 'Add a new task to your list'"
      icon="CheckSquare"
      width="40%"
    >
      <TodoForm
        :todo="selectedTodo"
        :is-edit="isEdit"
        @submit="handleSubmit"
        @cancel="closeForm"
      />
    </SlideOverlay>

    <!-- View Todo Details Slide Overlay -->
    <SlideOverlay
      v-model="showViewModal"
      title="Todo Details"
      subtitle="View task information and activity history"
      icon="Eye"
      width="50%"
    >
      <TodoDetails :todo="viewingTodo" />
    </SlideOverlay>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useTodos } from '@/composables/useTodos'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
// ... other imports

const { todos, loading, pendingTodos, inProgressTodos, completedTodos, fetchTodos } = useTodos()

// State
const showForm = ref(false)
const isEdit = ref(false)
const activeFilter = ref('all')
const selectedTodo = ref(null)
const showViewModal = ref(false)
const viewingTodo = ref(null)

// Methods
const openCreateForm = () => {
  isEdit.value = false
  selectedTodo.value = null
  showForm.value = true
}

const openEditForm = (todo) => {
  isEdit.value = true
  selectedTodo.value = todo
  showForm.value = true
}

const closeForm = () => {
  showForm.value = false
  selectedTodo.value = null
}

onMounted(() => {
  fetchTodos()
})
</script>
```

#### **2.3 SlideOverlay Component**

```vue
<!-- frontend/src/modules/common/components/base/SlideOverlay.vue -->
<template>
  <Teleport to="body">
    <Transition name="overlay">
      <div v-if="modelValue" class="fixed inset-0 z-50 overflow-hidden">
        <!-- Backdrop -->
        <Transition name="backdrop">
          <div
            v-if="modelValue"
            class="absolute inset-0 bg-black/[0.03]"
            @click="close"
          />
        </Transition>

        <!-- Slide Panel -->
        <Transition name="slide">
          <div
            v-if="modelValue"
            class="absolute right-0 top-0 h-full bg-white shadow-2xl flex flex-col"
            :class="widthClass"
            @click.stop
          >
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-slate-50">
              <div class="flex items-center gap-3">
                <component :is="iconComponent" class="w-5 h-5 text-slate-600" />
                <div>
                  <h2 class="text-lg font-semibold text-slate-900">{{ title }}</h2>
                  <p v-if="subtitle" class="text-sm text-slate-500">{{ subtitle }}</p>
                </div>
              </div>
              <button @click="close" class="p-2 hover:bg-slate-200 rounded-lg transition-colors">
                <X class="w-5 h-5 text-slate-600" />
              </button>
            </div>

            <!-- Content -->
            <div class="flex-1 overflow-y-auto p-6">
              <slot />
            </div>

            <!-- Footer (optional) -->
            <div v-if="$slots.footer" class="px-6 py-4 border-t border-slate-200 bg-slate-50">
              <slot name="footer" />
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue'
import { X } from 'lucide-vue-next'
import * as LucideIcons from 'lucide-vue-next'

const props = defineProps({
  modelValue: Boolean,
  title: { type: String, required: true },
  subtitle: String,
  icon: String,
  width: {
    type: String,
    default: '50%',
    validator: (v) => ['30%', '40%', '50%', '60%', '70%', '80%', '90%', 'full'].includes(v)
  },
  closeOnEscape: { type: Boolean, default: true },
  closeOnBackdrop: { type: Boolean, default: true }
})

const emit = defineEmits(['update:modelValue', 'close'])

const iconComponent = computed(() => 
  props.icon ? LucideIcons[props.icon] || LucideIcons.Circle : null
)

const widthClass = computed(() => {
  const widthMap = {
    '30%': 'w-[30%] min-w-[400px]',
    '40%': 'w-[40%] min-w-[500px]',
    '50%': 'w-[50%] min-w-[600px]',
    '60%': 'w-[60%] min-w-[700px]',
    '70%': 'w-[70%] min-w-[800px]',
    '80%': 'w-[80%] min-w-[900px]',
    '90%': 'w-[90%] min-w-[1000px]',
    'full': 'w-full'
  }
  return widthMap[props.width] || widthMap['50%']
})

const close = () => {
  if (props.closeOnBackdrop) {
    emit('update:modelValue', false)
    emit('close')
  }
}
</script>

<style scoped>
/* Slide transitions */
.slide-enter-active,
.slide-leave-active {
  transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.slide-enter-from,
.slide-leave-to {
  transform: translateX(100%);
}
</style>
```

---

## 📋 **Module 2: HR (Human Resources)**

### **Key Features**:
1. Employee Management
2. Department Management
3. Attendance Tracking
4. Leave Management
5. Performance Reviews
6. Payroll Integration

### **Implementation** (Similar to Todos):
- Event-based architecture
- Slide overlay forms
- Activity logging
- Multi-tenancy support

---

## 📋 **Module 3: Finance**

### **Key Features**:
1. Expense Tracking
2. Invoice Management
3. Payment Processing
4. Budget Management
5. Financial Reports
6. Tax Calculations

### **Implementation** (Similar to Todos):
- Event-based architecture
- Slide overlay forms
- Activity logging
- Multi-tenancy support

---

## 🔄 **Event System Architecture**

### **WebSocket Event Flow**:

```
1. User Action (Frontend)
   ↓
2. API Request (axios)
   ↓
3. Controller Action (Backend)
   ↓
4. Event Dispatch (TodoCreated, etc.)
   ↓
5. Soketi Broadcast (WebSocket)
   ↓
6. Frontend Listener (secureEventHandlers)
   ↓
7. Tenant Store Update (Reactive)
   ↓
8. UI Auto-Update (No Polling!)
```

### **Event Handlers**:

```javascript
// frontend/src/services/websocket/secureEventHandlers.js
export function setupTodoEventHandlers(channel, tenantId) {
  // Listen for todo.created
  channel.listen('.todo.created', (event) => {
    const store = getTenantStore(tenantId)
    if (store) {
      store.todos.push(event.todo)
      updateTodoStats(store)
    }
  })
  
  // Listen for todo.updated
  channel.listen('.todo.updated', (event) => {
    const store = getTenantStore(tenantId)
    if (store) {
      const index = store.todos.findIndex(t => t.id === event.todo.id)
      if (index !== -1) {
        store.todos[index] = event.todo
        updateTodoStats(store)
      }
    }
  })
  
  // Listen for todo.deleted
  channel.listen('.todo.deleted', (event) => {
    const store = getTenantStore(tenantId)
    if (store) {
      store.todos = store.todos.filter(t => t.id !== event.todoId)
      updateTodoStats(store)
    }
  })
}
```

---

## 📁 **File Structure**

### **Backend**:
```
backend/
├── app/
│   ├── Models/
│   │   ├── Todo.php
│   │   ├── TodoActivity.php
│   │   ├── Employee.php
│   │   ├── Department.php
│   │   ├── Expense.php
│   │   └── Invoice.php
│   ├── Http/Controllers/Api/
│   │   ├── TodoController.php
│   │   ├── EmployeeController.php
│   │   ├── DepartmentController.php
│   │   ├── ExpenseController.php
│   │   └── InvoiceController.php
│   ├── Events/
│   │   ├── TodoCreated.php
│   │   ├── TodoUpdated.php
│   │   ├── TodoDeleted.php
│   │   ├── EmployeeCreated.php
│   │   └── ExpenseCreated.php
│   └── Services/
│       ├── TodoService.php
│       ├── HRService.php
│       └── FinanceService.php
└── database/migrations/
    ├── 2025_12_07_000001_create_todos_table.php
    ├── 2025_12_07_000002_create_todo_activities_table.php
    ├── 2025_12_07_000003_create_employees_table.php
    ├── 2025_12_07_000004_create_departments_table.php
    ├── 2025_12_07_000005_create_expenses_table.php
    └── 2025_12_07_000006_create_invoices_table.php
```

### **Frontend**:
```
frontend/
├── src/
│   ├── modules/
│   │   ├── tenant/
│   │   │   ├── views/
│   │   │   │   ├── TodosView.vue
│   │   │   │   ├── EmployeesView.vue
│   │   │   │   ├── DepartmentsView.vue
│   │   │   │   ├── ExpensesView.vue
│   │   │   │   └── InvoicesView.vue
│   │   │   └── components/
│   │   │       ├── TodoCard.vue
│   │   │       ├── TodoForm.vue
│   │   │       ├── TodoActivityLog.vue
│   │   │       ├── EmployeeCard.vue
│   │   │       └── ExpenseCard.vue
│   │   └── common/
│   │       └── components/
│   │           └── base/
│   │               ├── SlideOverlay.vue
│   │               ├── BaseButton.vue
│   │               ├── BaseBadge.vue
│   │               └── BaseToast.vue
│   ├── composables/
│   │   ├── useTodos.js
│   │   ├── useEmployees.js
│   │   ├── useDepartments.js
│   │   └── useExpenses.js
│   ├── services/
│   │   ├── api/
│   │   │   ├── todoService.js
│   │   │   ├── employeeService.js
│   │   │   └── expenseService.js
│   │   └── websocket/
│   │       └── secureEventHandlers.js
│   └── stores/
│       ├── auth.js
│       └── tenant.js
```

---

## ✅ **Implementation Checklist**

### **Phase 1: Todos Module** (Priority 1)
- [ ] Backend migrations
- [ ] Backend models
- [ ] Backend events
- [ ] Backend controller
- [ ] Backend routes
- [ ] Frontend composable
- [ ] Frontend views
- [ ] Frontend components
- [ ] WebSocket event handlers
- [ ] Testing

### **Phase 2: HR Module** (Priority 2)
- [ ] Backend implementation
- [ ] Frontend implementation
- [ ] Event system
- [ ] Testing

### **Phase 3: Finance Module** (Priority 3)
- [ ] Backend implementation
- [ ] Frontend implementation
- [ ] Event system
- [ ] Testing

---

## 🎨 **UI/UX Consistency**

### **Design Principles**:
1. **Slide Overlays** - NOT modals
2. **Consistent Colors** - Blue (primary), Orange (warning), Green (success), Red (danger)
3. **Stat Cards** - Same layout as Livestock Management
4. **Activity Logs** - Timeline view with user avatars
5. **Badges** - Consistent priority/status indicators
6. **Loading States** - Skeleton loaders
7. **Empty States** - Friendly messages with actions

---

## 🚀 **Next Steps**

1. **Review this plan** - Confirm architecture and approach
2. **Approve implementation** - Start with Todos module
3. **Iterative development** - One module at a time
4. **Testing** - Each module before moving to next
5. **Documentation** - Update as we build

---

**Status**: ⏳ **AWAITING APPROVAL**  
**Estimated Time**: 
- Todos Module: 4-6 hours
- HR Module: 6-8 hours
- Finance Module: 6-8 hours
- **Total**: 16-22 hours

**Ready to proceed?** Please review and approve this plan, and I'll start with the Todos module implementation.
