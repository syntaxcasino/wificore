<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('router_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('type', 64)->index();
            $table->string('status', 32)->index();
            $table->unsignedSmallInteger('progress')->default(0);
            $table->string('message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'router_id', 'created_at'], 'router_tasks_router_created_idx');
            $table->index(['tenant_id', 'status', 'created_at'], 'router_tasks_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_tasks');
    }
};
