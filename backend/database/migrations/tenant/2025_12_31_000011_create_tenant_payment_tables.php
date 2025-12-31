<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Helper to check if table exists in CURRENT schema
        $hasTableInCurrentSchema = function($tableName) {
            $result = DB::selectOne("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = CURRENT_SCHEMA()
                    AND table_name = ?
                ) as exists
            ", [$tableName]);
            return $result->exists;
        };

        // 1. Create Payments Table
        if (!$hasTableInCurrentSchema('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->uuid('user_id')->nullable();
                $table->string('mac_address', 17);
                $table->string('phone_number', 15);
                $table->uuid('package_id')->nullable();
                $table->uuid('router_id')->nullable();
                $table->decimal('amount', 10, 2);
                $table->string('transaction_id', 255)->unique();
                $table->string('mpesa_receipt', 255)->nullable();
                $table->string('status', 20)->default('pending');
                $table->string('payment_method', 50)->default('mpesa');
                $table->json('callback_response')->nullable();
                $table->timestamps();
                
                // Foreign keys
                // Users are in public schema
                $table->foreign('user_id')
                    ->references('id')
                    ->on(new \Illuminate\Database\Query\Expression('public.users'))
                    ->onDelete('set null');
                
                // Packages are in public schema (for now, assuming shared packages or tenant-tagged public packages)
                $table->foreign('package_id')
                    ->references('id')
                    ->on(new \Illuminate\Database\Query\Expression('public.packages'))
                    ->onDelete('cascade');
                
                // Routers are in tenant schema
                $table->foreign('router_id')
                    ->references('id')
                    ->on('routers')
                    ->onDelete('set null');
                
                $table->index('user_id');
                $table->index('status');
                $table->index('phone_number');
                $table->index('created_at');
            });

            $this->migrateFromPublic('payments');
        }

        // 2. Create User Subscriptions Table
        if (!$hasTableInCurrentSchema('user_subscriptions')) {
            Schema::create('user_subscriptions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->uuid('user_id');
                $table->uuid('package_id');
                $table->uuid('payment_id')->nullable();
                $table->string('mac_address', 17);
                $table->timestamp('start_time');
                $table->timestamp('end_time');
                $table->string('status', 20)->default('active');
                $table->string('mikrotik_username')->nullable();
                $table->string('mikrotik_password')->nullable();
                $table->bigInteger('data_used_mb')->default(0);
                $table->integer('time_used_minutes')->default(0);
                $table->date('next_payment_date')->nullable();
                $table->integer('grace_period_days')->default(3);
                $table->timestamp('grace_period_ends_at')->nullable();
                $table->boolean('auto_renew')->default(false);
                $table->timestamp('disconnected_at')->nullable();
                $table->string('disconnection_reason')->nullable();
                $table->timestamp('last_reminder_sent_at')->nullable();
                $table->integer('reminder_count')->default(0);
                $table->timestamps();
                
                // Foreign keys
                $table->foreign('user_id')
                    ->references('id')
                    ->on(new \Illuminate\Database\Query\Expression('public.users'))
                    ->onDelete('cascade');
                    
                $table->foreign('package_id')
                    ->references('id')
                    ->on(new \Illuminate\Database\Query\Expression('public.packages'))
                    ->onDelete('cascade');
                    
                $table->foreign('payment_id')
                    ->references('id')
                    ->on('payments')
                    ->onDelete('set null');
                
                $table->index('user_id');
                $table->index('status');
                $table->index('end_time');
                $table->index('next_payment_date');
            });

            // Check constraint (PostgreSQL)
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE user_subscriptions ADD CONSTRAINT user_subscriptions_status_check CHECK (status IN ('active', 'expired', 'suspended', 'grace_period', 'disconnected', 'cancelled'))");
            }

            $this->migrateFromPublic('user_subscriptions');
        }

        // 3. Payment Reminders
        if (!$hasTableInCurrentSchema('payment_reminders')) {
            Schema::create('payment_reminders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->uuid('user_id');
                $table->uuid('subscription_id')->nullable();
                $table->string('reminder_type', 50);
                $table->integer('days_before_due')->nullable();
                $table->timestamp('sent_at')->useCurrent();
                $table->string('channel', 20);
                $table->string('status', 20)->default('sent');
                $table->json('response')->nullable();
                $table->timestamps();

                // Foreign keys
                $table->foreign('user_id')
                    ->references('id')
                    ->on(new \Illuminate\Database\Query\Expression('public.users'))
                    ->onDelete('cascade');

                $table->foreign('subscription_id')
                    ->references('id')
                    ->on('user_subscriptions')
                    ->onDelete('cascade');

                // Indexes
                $table->index('user_id');
                $table->index('subscription_id');
                $table->index('reminder_type');
                $table->index('sent_at');
            });

            // Check constraints (PostgreSQL)
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE payment_reminders ADD CONSTRAINT payment_reminders_type_check CHECK (reminder_type IN ('due_soon', 'overdue', 'grace_period', 'disconnected', 'final_warning'))");
                DB::statement("ALTER TABLE payment_reminders ADD CONSTRAINT payment_reminders_channel_check CHECK (channel IN ('email', 'sms', 'in_app', 'push'))");
                DB::statement("ALTER TABLE payment_reminders ADD CONSTRAINT payment_reminders_status_check CHECK (status IN ('sent', 'failed', 'pending', 'delivered'))");
            }

            $this->migrateFromPublic('payment_reminders');
        }

        // 4. Service Control Logs
        if (!$hasTableInCurrentSchema('service_control_logs')) {
            Schema::create('service_control_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->uuid('user_id')->nullable();
                $table->uuid('subscription_id')->nullable();
                $table->string('action', 50);
                $table->string('reason', 255)->nullable();
                $table->string('status', 20)->default('pending');
                $table->json('radius_response')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();

                // Foreign keys
                $table->foreign('user_id')
                    ->references('id')
                    ->on(new \Illuminate\Database\Query\Expression('public.users'))
                    ->onDelete('set null');

                $table->foreign('subscription_id')
                    ->references('id')
                    ->on('user_subscriptions')
                    ->onDelete('set null');

                // Indexes
                $table->index('user_id');
                $table->index('subscription_id');
                $table->index('action');
                $table->index('status');
                $table->index('created_at');
            });

            // Check constraints (PostgreSQL)
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE service_control_logs ADD CONSTRAINT service_control_logs_action_check CHECK (action IN ('disconnect', 'reconnect', 'suspend', 'activate', 'terminate'))");
                DB::statement("ALTER TABLE service_control_logs ADD CONSTRAINT service_control_logs_status_check CHECK (status IN ('pending', 'completed', 'failed', 'retrying'))");
            }

            $this->migrateFromPublic('service_control_logs');
        }
    }

    /**
     * Helper to migrate data from public schema
     */
    protected function migrateFromPublic(string $tableName): void
    {
        // Check if public table exists
        $publicTableExists = DB::selectOne("
            SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name = ?
            )
        ", [$tableName]);

        if (!$publicTableExists->exists) {
            return;
        }

        // Get tenant info
        $result = DB::select("SHOW search_path");
        $searchPath = $result[0]->search_path;
        $schemas = explode(',', $searchPath);
        $tenantSchema = trim($schemas[0]);
        
        $tenantId = DB::table('public.tenants')->where('schema_name', $tenantSchema)->value('id');
        
        if (!$tenantId) {
            \Log::warning("Could not determine tenant ID for schema {$tenantSchema} during {$tableName} migration");
            return;
        }

        \Log::info("Migrating {$tableName} for tenant {$tenantId} ({$tenantSchema})");

        // Check if table has tenant_id column
        $hasTenantId = Schema::connection('pgsql')->hasColumn("public.{$tableName}", 'tenant_id');

        if (!$hasTenantId) {
            \Log::warning("Skipping migration for {$tableName}: No tenant_id column in public table.");
            return;
        }

        $query = DB::table("public.{$tableName}")->where('tenant_id', $tenantId);
        $data = $query->get();
        
        foreach ($data as $row) {
            $rowArray = (array)$row;
            if (isset($rowArray['tenant_id'])) {
                unset($rowArray['tenant_id']);
            }
            
            try {
                DB::table($tableName)->insert($rowArray);
            } catch (\Exception $e) {
                \Log::warning("Failed to migrate row in {$tableName}: " . $e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
        Schema::dropIfExists('payments');
    }
};
