<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Helper to check if table exists in current schema
        $hasTableInCurrentSchema = function (string $tableName): bool {
            $schemaName = DB::connection()->getConfig('search_path') ?? 'public';
            $schemaName = explode(',', $schemaName)[0];
            $schemaName = trim($schemaName);
            
            $result = DB::select("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = ? 
                    AND table_name = ?
                )
            ", [$schemaName, $tableName]);
            
            return $result[0]->exists ?? false;
        };

        // 1. GenieACS Devices - Track TR-069/CWMP devices
        if (!$hasTableInCurrentSchema('genieacs_devices')) {
            Schema::create('genieacs_devices', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // NO tenant_id - schema isolation provides tenancy
                $table->string('device_id')->unique()->comment('GenieACS device ID (OUI-ProductClass-Serial)');
                $table->uuid('access_point_id')->nullable();
                $table->string('serial_number')->nullable();
                $table->string('mac_address')->nullable();
                $table->string('manufacturer')->nullable();
                $table->string('model')->nullable();
                $table->string('software_version')->nullable();
                $table->string('hardware_version')->nullable();
                $table->inet('ip_address')->nullable();
                $table->string('connection_status', 50)->default('unknown')->comment('online, offline, error, unknown');
                $table->timestamp('last_inform')->nullable()->comment('Last time device contacted ACS');
                $table->timestamp('last_boot')->nullable()->comment('Device last boot time');
                $table->jsonb('tags')->nullable()->comment('GenieACS tags for device categorization');
                $table->jsonb('parameters')->nullable()->comment('Device parameters from TR-069');
                $table->string('provisioning_status', 50)->default('pending')->comment('pending, provisioned, failed');
                $table->timestamp('provisioned_at')->nullable();
                $table->text('provisioning_error')->nullable();
                $table->timestamps();
                
                // Indexes
                $table->index('access_point_id');
                $table->index('serial_number');
                $table->index('mac_address');
                $table->index('connection_status');
                $table->index('provisioning_status');
                $table->index('last_inform');
                
                // Foreign key
                $table->foreign('access_point_id')
                    ->references('id')
                    ->on('access_points')
                    ->onDelete('set null');
            });
        }

        // 2. GenieACS Presets - Provisioning configurations
        if (!$hasTableInCurrentSchema('genieacs_presets')) {
            Schema::create('genieacs_presets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // NO tenant_id - schema isolation provides tenancy
                $table->string('name')->unique()->comment('Preset name in GenieACS');
                $table->string('device_id')->nullable()->comment('Target specific device ID');
                $table->integer('weight')->default(10)->comment('Preset priority (higher = higher priority)');
                $table->jsonb('precondition')->nullable()->comment('Conditions for preset to apply');
                $table->jsonb('configurations')->comment('Preset configurations (tags, parameters, etc)');
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();
                
                // Indexes
                $table->index('device_id');
                $table->index('is_active');
                $table->index('weight');
            });
        }

        // 3. GenieACS Tasks - Track device operations
        if (!$hasTableInCurrentSchema('genieacs_tasks')) {
            Schema::create('genieacs_tasks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // NO tenant_id - schema isolation provides tenancy
                $table->uuid('genieacs_device_id');
                $table->string('device_id')->comment('GenieACS device ID');
                $table->string('task_name', 100)->comment('reboot, factoryReset, download, setParameterValues, etc');
                $table->jsonb('parameters')->nullable()->comment('Task parameters');
                $table->string('status', 50)->default('pending')->comment('pending, running, completed, failed');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->jsonb('result')->nullable()->comment('Task result data');
                $table->timestamps();
                
                // Indexes
                $table->index('genieacs_device_id');
                $table->index('device_id');
                $table->index('task_name');
                $table->index('status');
                $table->index('created_at');
                
                // Foreign key
                $table->foreign('genieacs_device_id')
                    ->references('id')
                    ->on('genieacs_devices')
                    ->onDelete('cascade');
            });
        }

        // 4. GenieACS Faults - Track device faults
        if (!$hasTableInCurrentSchema('genieacs_faults')) {
            Schema::create('genieacs_faults', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // NO tenant_id - schema isolation provides tenancy
                $table->uuid('genieacs_device_id');
                $table->string('device_id');
                $table->string('fault_code', 50);
                $table->string('fault_string');
                $table->text('detail')->nullable();
                $table->timestamp('timestamp');
                $table->boolean('resolved')->default(false);
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                
                // Indexes
                $table->index('genieacs_device_id');
                $table->index('device_id');
                $table->index('fault_code');
                $table->index('resolved');
                $table->index('timestamp');
                
                // Foreign key
                $table->foreign('genieacs_device_id')
                    ->references('id')
                    ->on('genieacs_devices')
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('genieacs_faults');
        Schema::dropIfExists('genieacs_tasks');
        Schema::dropIfExists('genieacs_presets');
        Schema::dropIfExists('genieacs_devices');
    }
};
