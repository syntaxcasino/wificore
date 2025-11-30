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
        Schema::table('tenants', function (Blueprint $table) {
            // Add schema-based multi-tenancy fields
            $table->string('schema_name', 63)->unique()->nullable()->after('slug');
            $table->boolean('schema_created')->default(false)->after('schema_name');
            $table->timestamp('schema_created_at')->nullable()->after('schema_created');
            
            // Add index for schema_name
            $table->index('schema_name');
            $table->index('schema_created');
        });
        
        // Update existing tenants with schema names based on their slugs
        DB::statement("
            UPDATE tenants 
            SET schema_name = CONCAT('tenant_', slug)
            WHERE schema_name IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['schema_name']);
            $table->dropIndex(['schema_created']);
            $table->dropColumn(['schema_name', 'schema_created', 'schema_created_at']);
        });
    }
};
