<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('router_id')->index();
            $table->uuid('router_task_id')->nullable()->index();
            $table->uuid('triggered_by_user_id')->nullable()->index();
            $table->string('source', 32)->default('router_task')->index();
            $table->string('mode', 24)->default('deploy')->index();
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedSmallInteger('progress')->default(0);
            $table->string('current_stage', 64)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'router_id', 'created_at'], 'prov_runs_tenant_router_created_idx');
            $table->index(['tenant_id', 'status', 'created_at'], 'prov_runs_tenant_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_runs');
    }
};

