<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard: vouchers table may already exist from 2025_12_31_000013_move_hotspot_entities_to_tenant
        if (Schema::hasTable('vouchers')) {
            // Add columns that the older migration didn't include
            Schema::table('vouchers', function (Blueprint $table) {
                if (!Schema::hasColumn('vouchers', 'prefix')) {
                    $table->string('prefix', 20)->nullable();
                }
                if (!Schema::hasColumn('vouchers', 'notes')) {
                    $table->text('notes')->nullable();
                }
                if (!Schema::hasColumn('vouchers', 'batch_id')) {
                    $table->string('batch_id', 50)->nullable()->index();
                }
            });

            // Update status default from 'active' to 'unused'
            try {
                \DB::statement("ALTER TABLE vouchers ALTER COLUMN status SET DEFAULT 'unused'");
            } catch (\Exception $e) {
                \Log::warning('Could not update vouchers status default: ' . $e->getMessage());
            }

            return;
        }

        Schema::create('vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->uuid('package_id');
            $table->uuid('router_id')->nullable();
            $table->string('status', 20)->default('unused'); // unused, used, expired, revoked
            $table->uuid('used_by')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('prefix', 20)->nullable();
            $table->text('notes')->nullable();
            $table->string('batch_id', 50)->nullable()->index(); // groups vouchers from same generation
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('package_id')->references('id')->on('packages')->onDelete('cascade');
            $table->foreign('router_id')->references('id')->on('routers')->onDelete('set null');

            $table->index('status');
            $table->index('code');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
