<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('pppoe_user_id')->index();
            $table->string('account_number')->index();
            $table->string('ticket_number', 32)->unique();
            $table->string('subject');
            $table->string('category', 32)->default('general')->index();
            $table->string('priority', 16)->default('normal')->index();
            $table->string('status', 32)->default('open')->index();
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('pppoe_user_id')->references('id')->on('pppoe_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_support_tickets');
    }
};
