<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('provisioning_run_id')->index();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('router_id')->index();
            $table->uuid('router_task_id')->nullable()->index();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('stage', 64)->index();
            $table->string('action', 128);
            $table->string('status', 32)->default('running')->index();
            $table->text('command')->nullable();
            $table->json('command_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('trap_message')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('is_terminal')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['provisioning_run_id', 'sequence'], 'prov_steps_run_sequence_uniq');
            $table->index(['provisioning_run_id', 'status'], 'prov_steps_run_status_idx');
            $table->index(['tenant_id', 'router_id', 'created_at'], 'prov_steps_tenant_router_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_steps');
    }
};

