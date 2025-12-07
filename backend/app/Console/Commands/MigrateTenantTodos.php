<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;

class MigrateTenantTodos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:migrate-todos {--tenant=* : Specific tenant IDs to migrate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create todos tables in all tenant schemas';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting tenant todos migration...');
        
        // Get tenants to migrate
        $tenantIds = $this->option('tenant');
        $query = Tenant::where('schema_created', true);
        
        if (!empty($tenantIds)) {
            $query->whereIn('id', $tenantIds);
        }
        
        $tenants = $query->get();
        
        if ($tenants->isEmpty()) {
            $this->warn('No tenants found with created schemas.');
            return 0;
        }
        
        $this->info("Found {$tenants->count()} tenant(s) to migrate.");
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($tenants as $tenant) {
            $this->line('');
            $this->info("📋 Processing tenant: {$tenant->name} ({$tenant->schema_name})");
            
            try {
                // Set search path to tenant schema
                DB::statement("SET search_path TO {$tenant->schema_name}, public");
                
                // Check if todos table already exists
                if (Schema::hasTable('todos')) {
                    $this->warn("  ⚠️  Todos table already exists in {$tenant->schema_name}");
                    continue;
                }
                
                // Create todos table
                $this->line("  Creating todos table...");
                Schema::create('todos', function ($table) {
                    $table->uuid('id')->primary();
                    $table->uuid('user_id')->nullable()->comment('Assigned to user');
                    $table->uuid('created_by')->comment('User who created the todo');
                    $table->string('title');
                    $table->text('description')->nullable();
                    $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
                    $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
                    $table->date('due_date')->nullable();
                    $table->timestamp('completed_at')->nullable();
                    $table->string('related_type')->nullable();
                    $table->uuid('related_id')->nullable();
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                    $table->softDeletes();
                    
                    $table->foreign('user_id')->references('id')->on('public.users')->onDelete('set null');
                    $table->foreign('created_by')->references('id')->on('public.users')->onDelete('cascade');
                    
                    $table->index(['user_id', 'status']);
                    $table->index(['created_by']);
                    $table->index(['due_date']);
                    $table->index(['related_type', 'related_id']);
                    $table->index(['status']);
                });
                
                // Create todo_activities table
                $this->line("  Creating todo_activities table...");
                Schema::create('todo_activities', function ($table) {
                    $table->uuid('id')->primary();
                    $table->uuid('todo_id');
                    $table->uuid('user_id');
                    $table->string('action');
                    $table->json('old_value')->nullable();
                    $table->json('new_value')->nullable();
                    $table->text('description')->nullable();
                    $table->timestamps();
                    
                    $table->foreign('todo_id')->references('id')->on('todos')->onDelete('cascade');
                    $table->foreign('user_id')->references('id')->on('public.users')->onDelete('cascade');
                    
                    $table->index(['todo_id', 'created_at']);
                    $table->index(['user_id']);
                    $table->index(['action']);
                });
                
                $this->info("  ✅ Successfully migrated {$tenant->schema_name}");
                $successCount++;
                
            } catch (\Exception $e) {
                $this->error("  ❌ Error migrating {$tenant->schema_name}: {$e->getMessage()}");
                $errorCount++;
            } finally {
                // Reset search path
                DB::statement("SET search_path TO public");
            }
        }
        
        // Summary
        $this->line('');
        $this->info('📊 Migration Summary:');
        $this->info("  ✅ Successful: {$successCount}");
        if ($errorCount > 0) {
            $this->error("  ❌ Failed: {$errorCount}");
        }
        
        return $errorCount > 0 ? 1 : 0;
    }
}
