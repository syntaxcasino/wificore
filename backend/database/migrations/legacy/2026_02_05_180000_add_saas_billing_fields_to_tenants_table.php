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
        // No-op: SaaS billing fields are created in 0001_01_01_000000_create_tenants_table.php.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: retained for migration history compatibility.
    }

    /**
     * Check if an index exists using PostgreSQL system catalog (Laravel 11 compatible).
     */
    private function indexExists(string $schema, string $table, string $indexName): bool
    {
        $result = DB::selectOne("
            SELECT 1 FROM pg_indexes 
            WHERE schemaname = ? 
            AND tablename = ? 
            AND indexname = ?
        ", [$schema, $table, $indexName]);
        
        return $result !== null;
    }
};
