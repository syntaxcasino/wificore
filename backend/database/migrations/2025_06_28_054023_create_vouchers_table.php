<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('mac_address');
            $table->foreignId('payment_id')->constrained()->onDelete('cascade');
            $table->foreignId('package_id')->constrained()->onDelete('cascade');
            $table->integer('duration_hours');
            $table->string('status')->default('unused');
            $table->timestamp('expires_at');
            $table->json('mikrotik_response')->nullable();
            $table->timestamps();

            // ✅ Indexes for performance
            $table->index(['mac_address', 'status'], 'idx_mac_status');
            $table->index(['mac_address', 'expires_at'], 'idx_mac_expires');
            $table->index('payment_id', 'idx_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
