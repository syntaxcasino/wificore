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
        Schema::create('mpesa_transaction_maps', function (Blueprint $table) {
            $table->id();
            $table->string('checkout_request_id')->unique();
            $table->string('merchant_request_id')->nullable();
            $table->uuid('tenant_id');
            $table->string('payment_type')->default('hotspot'); // hotspot, pppoe, tenant_subscription
            $table->uuid('related_id')->nullable(); // ID of the payment record in the respective schema
            $table->timestamps();

            $table->index('checkout_request_id');
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_transaction_maps');
    }
};
