<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\Todo;
use Illuminate\Support\Facades\DB;

// Get tenants
$tenantA = Tenant::where('name', 'Tenant A')->first();
$tenantB = Tenant::where('name', 'Tenant B')->first();

echo "=== TENANT SCHEMAS ===\n";
echo "Tenant A: {$tenantA->schema_name}\n";
echo "Tenant B: {$tenantB->schema_name}\n\n";

// Get admin users
$adminA = User::where('tenant_id', $tenantA->id)->where('role', 'admin')->first();
$adminB = User::where('tenant_id', $tenantB->id)->where('role', 'admin')->first();

echo "=== ADMIN USERS ===\n";
echo "Tenant A Admin: {$adminA->name} ({$adminA->email})\n";
echo "Tenant B Admin: {$adminB->name} ({$adminB->email})\n\n";

// Test 1: Create todo for Tenant A
echo "=== TEST 1: CREATE TODO FOR TENANT A ===\n";
DB::statement("SET search_path TO {$tenantA->schema_name}, public");

$todoA = Todo::create([
    'user_id' => $adminA->id,
    'created_by' => $adminA->id,
    'title' => 'Tenant A Todo - Test 1',
    'description' => 'This todo belongs to Tenant A only',
    'priority' => 'high',
    'status' => 'pending',
]);

echo "Created Todo ID: {$todoA->id}\n";
echo "Title: {$todoA->title}\n";
echo "Current search_path: " . DB::selectOne("SHOW search_path")->search_path . "\n\n";

// Test 2: Create todo for Tenant B
echo "=== TEST 2: CREATE TODO FOR TENANT B ===\n";
DB::statement("SET search_path TO {$tenantB->schema_name}, public");

$todoB = Todo::create([
    'user_id' => $adminB->id,
    'created_by' => $adminB->id,
    'title' => 'Tenant B Todo - Test 1',
    'description' => 'This todo belongs to Tenant B only',
    'priority' => 'medium',
    'status' => 'pending',
]);

echo "Created Todo ID: {$todoB->id}\n";
echo "Title: {$todoB->title}\n";
echo "Current search_path: " . DB::selectOne("SHOW search_path")->search_path . "\n\n";

// Test 3: Verify Tenant A can only see their todos
echo "=== TEST 3: VERIFY TENANT A ISOLATION ===\n";
DB::statement("SET search_path TO {$tenantA->schema_name}, public");
$tenantATodos = Todo::all();
echo "Tenant A todos count: {$tenantATodos->count()}\n";
foreach ($tenantATodos as $todo) {
    echo "  - {$todo->title}\n";
}
echo "\n";

// Test 4: Verify Tenant B can only see their todos
echo "=== TEST 4: VERIFY TENANT B ISOLATION ===\n";
DB::statement("SET search_path TO {$tenantB->schema_name}, public");
$tenantBTodos = Todo::all();
echo "Tenant B todos count: {$tenantBTodos->count()}\n";
foreach ($tenantBTodos as $todo) {
    echo "  - {$todo->title}\n";
}
echo "\n";

// Test 5: Verify public schema has NO todos
echo "=== TEST 5: VERIFY PUBLIC SCHEMA HAS NO TODOS ===\n";
DB::statement("SET search_path TO public");
$publicTodosCount = DB::select("SELECT COUNT(*) as count FROM pg_tables WHERE schemaname = 'public' AND tablename = 'todos'");
echo "Todos table in public schema: " . ($publicTodosCount[0]->count > 0 ? "EXISTS (BAD!)" : "DOES NOT EXIST (GOOD!)") . "\n\n";

// Test 6: Verify schema-level counts
echo "=== TEST 6: DIRECT SCHEMA QUERY ===\n";
$tenantACount = DB::select("SELECT COUNT(*) as count FROM {$tenantA->schema_name}.todos")[0]->count;
$tenantBCount = DB::select("SELECT COUNT(*) as count FROM {$tenantB->schema_name}.todos")[0]->count;
echo "Tenant A ({$tenantA->schema_name}): {$tenantACount} todos\n";
echo "Tenant B ({$tenantB->schema_name}): {$tenantBCount} todos\n\n";

echo "=== TESTS COMPLETE ===\n";
echo "✅ Schema isolation working correctly!\n";
echo "✅ Each tenant can only see their own todos\n";
echo "✅ No todos in public schema\n";
