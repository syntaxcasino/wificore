<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop existing foreign key constraint
        DB::statement('ALTER TABLE pppoe_users DROP CONSTRAINT IF EXISTS pppoe_users_package_id_foreign');

        // Make package_id nullable
        DB::statement('ALTER TABLE pppoe_users ALTER COLUMN package_id DROP NOT NULL');

        // Re-add foreign key with set null on delete
        DB::statement('ALTER TABLE pppoe_users ADD CONSTRAINT pppoe_users_package_id_foreign FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pppoe_users DROP CONSTRAINT IF EXISTS pppoe_users_package_id_foreign');
        DB::statement('ALTER TABLE pppoe_users ALTER COLUMN package_id SET NOT NULL');
        DB::statement('ALTER TABLE pppoe_users ADD CONSTRAINT pppoe_users_package_id_foreign FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT');
    }
};
