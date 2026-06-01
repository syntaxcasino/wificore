<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_compliance_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('router_id')->index();
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('grade', 8)->default('D');
            $table->string('status', 32)->default('non_compliant');
            $table->json('checks')->nullable();
            $table->json('missing_controls')->nullable();
            $table->json('passed_controls')->nullable();
            $table->text('summary')->nullable();
            $table->uuid('source_snapshot_id')->nullable()->index();
            $table->timestamp('evaluated_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('router_id')->references('id')->on('routers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_compliance_snapshots');
    }
};
