<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Payment Settings Table (Landlord/Public DB)
 * 
 * Stores the landlord's default MPesa Paybill configuration.
 * Used as fallback when tenants don't have their own Paybill.
 * This replaces .env-based Paybill configuration for the landlord.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op: system_payment_settings is created in 2025_12_31_000009_create_tenant_payments_table.php.
    }

    public function down(): void
    {
        // No-op: retained for migration history compatibility.
    }
};
