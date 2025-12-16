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
        Schema::create('tenant_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('token')->unique();
            $table->string('tenant_name');
            $table->string('tenant_slug')->unique();
            $table->string('tenant_email')->unique();
            $table->string('tenant_phone')->nullable();
            $table->text('tenant_address')->nullable();
            $table->string('generated_username')->nullable();
            $table->string('generated_password')->nullable();
            $table->boolean('email_verified')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('credentials_sent')->default(false);
            $table->timestamp('credentials_sent_at')->nullable();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('user_id')->nullable();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('status')->default('pending'); // pending, email_sent, verified, completed, failed
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->index('token');
            $table->index('status');
            $table->index('email_verified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_registrations');
    }
};
