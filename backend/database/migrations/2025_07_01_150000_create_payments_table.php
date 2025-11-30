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
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->string('mac_address', 17);
            $table->string('phone_number', 15);
            $table->uuid('package_id')->nullable();
            $table->uuid('router_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('transaction_id', 255)->unique();
            $table->string('mpesa_receipt', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('payment_method', 50)->default('mpesa');
            $table->json('callback_response')->nullable();
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('package_id')->references('id')->on('packages')->onDelete('cascade');
            $table->foreign('router_id')->references('id')->on('routers')->onDelete('set null');
            
            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('phone_number');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
