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
        Schema::create('health_score_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->decimal('score', 5, 2)->default(100);
            $table->string('grade', 32)->default('healthy');
            $table->json('factors')->nullable();
            $table->json('signals')->nullable();
            $table->string('source_event', 128)->nullable();
            $table->string('source_reference', 128)->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'calculated_at']);
            $table->index(['tenant_id', 'grade']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_score_snapshots');
    }
};
