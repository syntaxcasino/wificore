<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 20); // sms, whatsapp, email
            $table->string('provider', 50); // africastalking, twilio, whatsapp_business, custom
            $table->text('credentials'); // encrypted JSON: api_key, api_secret, account_sid, auth_token, etc.
            $table->string('sender_id', 50)->nullable(); // Sender ID for SMS
            $table->string('phone_number', 20)->nullable(); // Phone number for WhatsApp
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // Default channel for this type
            $table->json('settings')->nullable(); // Additional provider-specific settings
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable(); // success, failed
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index(['is_default', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_channels');
    }
};
