<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateTenantHRFinance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:migrate-hr-finance {--tenant=* : Specific tenant IDs to migrate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create HR and Finance tables in all tenant schemas';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting tenant HR & Finance migration...');
        
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
                
                // Create departments table
                if (!Schema::hasTable('departments')) {
                    $this->line("  Creating departments table...");
                    $this->createDepartmentsTable();
                }
                
                // Create positions table
                if (!Schema::hasTable('positions')) {
                    $this->line("  Creating positions table...");
                    $this->createPositionsTable();
                }
                
                // Create employees table
                if (!Schema::hasTable('employees')) {
                    $this->line("  Creating employees table...");
                    $this->createEmployeesTable();
                }
                
                // Create expenses table
                if (!Schema::hasTable('expenses')) {
                    $this->line("  Creating expenses table...");
                    $this->createExpensesTable();
                }
                
                // Create revenues table
                if (!Schema::hasTable('revenues')) {
                    $this->line("  Creating revenues table...");
                    $this->createRevenuesTable();
                }
                
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
    
    private function createDepartmentsTable()
    {
        Schema::create('departments', function ($table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->uuid('manager_id')->nullable();
            $table->decimal('budget', 15, 2)->nullable();
            $table->string('location')->nullable();
            $table->enum('status', ['active', 'pending_approval', 'inactive'])->default('pending_approval');
            $table->boolean('is_active')->default(true);
            $table->integer('employee_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('name');
            $table->index('code');
            $table->index('manager_id');
            $table->index('status');
            $table->index('is_active');
        });
    }
    
    private function createPositionsTable()
    {
        Schema::create('positions', function ($table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->uuid('department_id')->nullable();
            $table->string('level')->nullable();
            $table->decimal('min_salary', 12, 2)->nullable();
            $table->decimal('max_salary', 12, 2)->nullable();
            $table->text('requirements')->nullable();
            $table->text('responsibilities')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
            
            $table->index('title');
            $table->index('code');
            $table->index('department_id');
            $table->index('level');
            $table->index('is_active');
        });
    }
    
    private function createEmployeesTable()
    {
        Schema::create('employees', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('employee_number')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('national_id')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->uuid('department_id')->nullable();
            $table->uuid('position_id')->nullable();
            $table->enum('employment_type', ['full_time', 'part_time', 'contract', 'intern'])->default('full_time');
            $table->date('hire_date');
            $table->date('contract_end_date')->nullable();
            $table->enum('employment_status', ['active', 'on_leave', 'suspended', 'terminated'])->default('active');
            $table->decimal('salary', 12, 2)->nullable();
            $table->string('salary_currency', 3)->default('USD');
            $table->enum('payment_frequency', ['monthly', 'bi_weekly', 'weekly'])->default('monthly');
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('emergency_contact_relationship')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('user_id')->references('id')->on('public.users')->onDelete('set null');
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
            $table->foreign('position_id')->references('id')->on('positions')->onDelete('set null');
            
            $table->index('employee_number');
            $table->index('user_id');
            $table->index('department_id');
            $table->index('position_id');
            $table->index('employment_status');
            $table->index('employment_type');
            $table->index('is_active');
            $table->index(['first_name', 'last_name']);
        });
    }
    
    private function createExpensesTable()
    {
        Schema::create('expenses', function ($table) {
            $table->uuid('id')->primary();
            $table->string('expense_number')->unique();
            $table->string('category');
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'credit_card', 'mobile_money', 'check'])->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('receipt_number')->nullable();
            $table->string('receipt_file')->nullable();
            $table->uuid('submitted_by');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('submitted_by')->references('id')->on('public.users')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('public.users')->onDelete('set null');
            
            $table->index('expense_number');
            $table->index('category');
            $table->index('expense_date');
            $table->index('status');
            $table->index('submitted_by');
            $table->index('approved_by');
            $table->index('payment_method');
        });
    }
    
    private function createRevenuesTable()
    {
        Schema::create('revenues', function ($table) {
            $table->uuid('id')->primary();
            $table->string('revenue_number')->unique();
            $table->string('source');
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('revenue_date');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'credit_card', 'mobile_money', 'check'])->nullable();
            $table->string('reference_number')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->uuid('recorded_by');
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('confirmed');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('customer_id')->references('id')->on('public.users')->onDelete('set null');
            $table->foreign('recorded_by')->references('id')->on('public.users')->onDelete('cascade');
            
            $table->index('revenue_number');
            $table->index('source');
            $table->index('revenue_date');
            $table->index('status');
            $table->index('customer_id');
            $table->index('recorded_by');
            $table->index('payment_method');
        });
    }
}
