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
        Schema::create('hotspot_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('hotspot_user_id');
            $table->uuid('payment_id')->nullable();
            
            // Credentials
            $table->string('username', 64);
            $table->string('plain_password', 64);
            
            // SMS delivery
            $table->string('phone_number', 20);
            $table->boolean('sms_sent')->default(false);
            $table->timestamp('sms_sent_at')->nullable();
            $table->string('sms_message_id', 100)->nullable();
            $table->string('sms_status', 50)->nullable();
            
            // Expiry
            $table->timestamp('credentials_expires_at')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('hotspot_user_id')->references('id')->on('hotspot_users')->onDelete('cascade');
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('set null');
            
            // Indexes
            $table->index('tenant_id');
            $table->index('hotspot_user_id');
            $table->index('phone_number');
            $table->index('sms_sent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotspot_credentials');
    }
};
