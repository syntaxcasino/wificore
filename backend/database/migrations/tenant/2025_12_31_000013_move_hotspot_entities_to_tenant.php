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

        // 1. Hotspot Users
        if (!$hasTableInCurrentSchema('hotspot_users')) {
            Schema::create('hotspot_users', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->string('username')->unique();
                $table->string('password');
                $table->string('phone_number')->unique();
                $table->string('mac_address', 17)->nullable();
                $table->boolean('has_active_subscription')->default(false);
                $table->string('package_name')->nullable();
                $table->uuid('package_id')->nullable();
                $table->timestamp('subscription_starts_at')->nullable();
                $table->timestamp('subscription_expires_at')->nullable();
                $table->bigInteger('data_limit')->nullable()->comment('in bytes');
                $table->bigInteger('data_used')->default(0)->comment('in bytes');
                $table->timestamp('last_login_at')->nullable();
                $table->string('last_login_ip', 45)->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('status', 20)->default('active');
                $table->timestamps();
                $table->softDeletes();
                
                // Use explicit schema for foreign keys to public tables
                $table->foreign('package_id')->references('id')->on(new \Illuminate\Database\Query\Expression('public.packages'))->onDelete('set null');
                
                $table->index('username');
                $table->index('phone_number');
                $table->index('package_id');
            });
            $this->migrateFromPublic('hotspot_users');
        }

        // 2. Hotspot Sessions
        if (!$hasTableInCurrentSchema('hotspot_sessions')) {
            Schema::create('hotspot_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->uuid('hotspot_user_id');
                $table->string('mac_address', 17)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('session_start');
                $table->timestamp('session_end')->nullable();
                $table->timestamp('last_activity')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->bigInteger('bytes_uploaded')->default(0);
                $table->bigInteger('bytes_downloaded')->default(0);
                $table->bigInteger('total_bytes')->default(0);
                $table->string('user_agent')->nullable();
                $table->string('device_type', 50)->nullable();
                $table->timestamps();
                
                $table->foreign('hotspot_user_id')->references('id')->on('hotspot_users')->onDelete('cascade');
                
                $table->index('hotspot_user_id');
                $table->index('is_active');
            });
            $this->migrateFromPublic('hotspot_sessions');
        }

        // 3. User Sessions
        if (!$hasTableInCurrentSchema('user_sessions')) {
            Schema::create('user_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->uuid('payment_id')->nullable();
                $table->string('voucher')->unique();
                $table->string('mac_address', 17);
                $table->timestamp('start_time');
                $table->timestamp('end_time');
                $table->string('status', 20)->default('active');
                
                // Data usage tracking columns
                $table->bigInteger('data_used')->default(0)->comment('Total data used in bytes');
                $table->bigInteger('data_upload')->default(0)->comment('Upload data in bytes');
                $table->bigInteger('data_download')->default(0)->comment('Download data in bytes');
                
                $table->timestamps();
                
                // payments table is in the same tenant schema now
                $table->foreign('payment_id')->references('id')->on('payments')->onDelete('cascade');
                
                $table->index('payment_id');
                $table->index('status');
                $table->index('data_used');
            });
            $this->migrateFromPublic('user_sessions');
        }

        // 4. Vouchers
        if (!$hasTableInCurrentSchema('vouchers')) {
            Schema::create('vouchers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->string('code')->unique();
                $table->uuid('package_id');
                $table->uuid('router_id')->nullable();
                $table->string('status', 20)->default('active');
                $table->uuid('used_by')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                
                // Foreign keys to public tables
                $table->foreign('package_id')->references('id')->on(new \Illuminate\Database\Query\Expression('public.packages'))->onDelete('cascade');
                $table->foreign('used_by')->references('id')->on(new \Illuminate\Database\Query\Expression('public.users'))->onDelete('set null');
                
                // Foreign keys to tenant tables
                $table->foreign('router_id')->references('id')->on('routers')->onDelete('set null');
                
                $table->index('code');
                $table->index('status');
                $table->index('package_id');
            });
            $this->migrateFromPublic('vouchers');
        }

        // 5. Radius Sessions
        if (!$hasTableInCurrentSchema('radius_sessions')) {
            Schema::create('radius_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->uuid('hotspot_user_id');
                $table->uuid('payment_id')->nullable();
                $table->uuid('package_id')->nullable();
                $table->bigInteger('radacct_id')->nullable();
                $table->string('username', 64);
                $table->string('mac_address', 17)->nullable();
                $table->ipAddress('ip_address')->nullable();
                $table->ipAddress('nas_ip_address')->nullable();
                $table->timestamp('session_start');
                $table->timestamp('session_end')->nullable();
                $table->timestamp('expected_end');
                $table->bigInteger('duration_seconds')->default(0);
                $table->bigInteger('bytes_in')->default(0);
                $table->bigInteger('bytes_out')->default(0);
                $table->bigInteger('total_bytes')->default(0);
                $table->string('status', 20)->default('active');
                $table->string('disconnect_reason', 100)->nullable();
                $table->timestamps();
                
                // Foreign keys
                $table->foreign('hotspot_user_id')->references('id')->on('hotspot_users')->onDelete('cascade');
                $table->foreign('payment_id')->references('id')->on('payments')->onDelete('set null');
                $table->foreign('package_id')->references('id')->on(new \Illuminate\Database\Query\Expression('public.packages'))->onDelete('set null');
                
                $table->index('hotspot_user_id');
                $table->index('status');
                $table->index('username');
            });
            $this->migrateFromPublic('radius_sessions');
        }

        // 6. Hotspot Credentials
        if (!$hasTableInCurrentSchema('hotspot_credentials')) {
            Schema::create('hotspot_credentials', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->uuid('hotspot_user_id');
                $table->uuid('payment_id')->nullable();
                
                // Credentials
                $table->string('username', 64);
                $table->string('plain_password', 64);
                
                // SMS delivery
                $table->string('phone_number', 20);
                $table->boolean('sms_sent')->default(false);
                $table->timestamp('sms_sent_at')->nullable();
                $table->string('sms_message_id', 100)->nullable();
                $table->string('sms_status', 50)->nullable();
                
                // Expiry
                $table->timestamp('credentials_expires_at')->nullable();
                
                $table->timestamp('created_at')->useCurrent();
                
                // Foreign keys
                $table->foreign('hotspot_user_id')->references('id')->on('hotspot_users')->onDelete('cascade');
                $table->foreign('payment_id')->references('id')->on('payments')->onDelete('set null');
                
                // Indexes
                $table->index('hotspot_user_id');
                $table->index('phone_number');
                $table->index('sms_sent');
            });
            $this->migrateFromPublic('hotspot_credentials');
        }

        // 7. Session Disconnections
        if (!$hasTableInCurrentSchema('session_disconnections')) {
            Schema::create('session_disconnections', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->uuid('radius_session_id');
                $table->uuid('hotspot_user_id');
                
                // Disconnection details
                $table->string('disconnect_method', 50)->nullable();
                $table->string('disconnect_reason')->nullable();
                $table->timestamp('disconnected_at');
                $table->uuid('disconnected_by')->nullable();
                
                // Session summary
                $table->bigInteger('total_duration')->nullable();
                $table->bigInteger('total_data_used')->nullable();
                
                $table->timestamp('created_at')->useCurrent();
                
                // Foreign keys
                $table->foreign('radius_session_id')->references('id')->on('radius_sessions')->onDelete('cascade');
                $table->foreign('hotspot_user_id')->references('id')->on('hotspot_users')->onDelete('cascade');
                $table->foreign('disconnected_by')->references('id')->on(new \Illuminate\Database\Query\Expression('public.users'))->onDelete('set null');
                
                // Indexes
                $table->index('hotspot_user_id');
                $table->index('disconnected_at');
                $table->index('disconnect_method');
            });
            $this->migrateFromPublic('session_disconnections');
        }

        // 8. Data Usage Logs
        if (!$hasTableInCurrentSchema('data_usage_logs')) {
            Schema::create('data_usage_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->uuid('hotspot_user_id');
                $table->uuid('radius_session_id');
                
                // Usage data
                $table->bigInteger('bytes_in');
                $table->bigInteger('bytes_out');
                $table->bigInteger('total_bytes');
                
                // Snapshot time
                $table->timestamp('recorded_at')->useCurrent();
                
                // Source
                $table->string('source', 50)->default('radius_accounting');
                
                // Foreign keys
                $table->foreign('hotspot_user_id')->references('id')->on('hotspot_users')->onDelete('cascade');
                $table->foreign('radius_session_id')->references('id')->on('radius_sessions')->onDelete('cascade');
                
                // Indexes
                $table->index('hotspot_user_id');
                $table->index('radius_session_id');
                $table->index('recorded_at');
            });
            $this->migrateFromPublic('data_usage_logs');
        }

        // Add triggers
        $this->addTriggers();
    }

    protected function addTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Hotspot Users Trigger
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION update_hotspot_users_updated_at()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.updated_at = CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trigger_hotspot_users_updated_at ON hotspot_users;
            CREATE TRIGGER trigger_hotspot_users_updated_at
                BEFORE UPDATE ON hotspot_users
                FOR EACH ROW
                EXECUTE FUNCTION update_hotspot_users_updated_at();
        SQL);

        // Hotspot Sessions Trigger
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION update_hotspot_sessions_updated_at()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.updated_at = CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trigger_hotspot_sessions_updated_at ON hotspot_sessions;
            CREATE TRIGGER trigger_hotspot_sessions_updated_at
                BEFORE UPDATE ON hotspot_sessions
                FOR EACH ROW
                EXECUTE FUNCTION update_hotspot_sessions_updated_at();
        SQL);

        // Radius Sessions Trigger
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION update_radius_sessions_updated_at()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.updated_at = CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trigger_radius_sessions_updated_at ON radius_sessions;
            CREATE TRIGGER trigger_radius_sessions_updated_at
                BEFORE UPDATE ON radius_sessions
                FOR EACH ROW
                EXECUTE FUNCTION update_radius_sessions_updated_at();
        SQL);
    }

    protected function migrateFromPublic(string $tableName): void
    {
        // Helper to migrate from public schema
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

        $result = DB::select("SHOW search_path");
        $searchPath = $result[0]->search_path;
        $schemas = explode(',', $searchPath);
        $tenantSchema = trim($schemas[0]);
        
        $tenantId = DB::table('public.tenants')->where('schema_name', $tenantSchema)->value('id');
        
        if (!$tenantId) return;

        \Log::info("Migrating {$tableName} for tenant {$tenantId}");

        if (Schema::connection('pgsql')->hasColumn("public.{$tableName}", 'tenant_id')) {
            $query = DB::table("public.{$tableName}")->where('tenant_id', $tenantId);
            $data = $query->get();
            
            foreach ($data as $row) {
                $rowArray = (array)$row;
                if (isset($rowArray['tenant_id'])) unset($rowArray['tenant_id']);
                try {
                    DB::table($tableName)->insert($rowArray);
                } catch (\Exception $e) {
                    \Log::warning("Failed to migrate row in {$tableName}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_usage_logs');
        Schema::dropIfExists('session_disconnections');
        Schema::dropIfExists('hotspot_credentials');
        Schema::dropIfExists('radius_sessions');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('hotspot_sessions');
        Schema::dropIfExists('hotspot_users');
    }
};
