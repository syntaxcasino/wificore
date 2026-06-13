<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.system_logs DROP CONSTRAINT IF EXISTS system_logs_tenant_id_foreign');
        DB::statement('ALTER TABLE public.system_logs ALTER COLUMN tenant_id DROP NOT NULL');
        DB::statement('ALTER TABLE public.system_logs ADD CONSTRAINT system_logs_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.system_logs DROP CONSTRAINT IF EXISTS system_logs_tenant_id_foreign');
        DB::statement('ALTER TABLE public.system_logs ALTER COLUMN tenant_id SET NOT NULL');
        DB::statement('ALTER TABLE public.system_logs ADD CONSTRAINT system_logs_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE');
    }
};
