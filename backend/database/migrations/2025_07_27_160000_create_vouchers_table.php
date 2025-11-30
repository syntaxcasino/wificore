<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code')->unique();
            $table->uuid('package_id');
            $table->uuid('router_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->uuid('used_by')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('package_id')->references('id')->on('packages')->onDelete('cascade');
            $table->foreign('router_id')->references('id')->on('routers')->onDelete('set null');
            $table->foreign('used_by')->references('id')->on('users')->onDelete('set null');
            
            $table->index('tenant_id');
            $table->index('code');
            $table->index('status');
            $table->index('package_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
