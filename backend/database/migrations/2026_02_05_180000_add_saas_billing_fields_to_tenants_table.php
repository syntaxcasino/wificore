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
        Schema::table('tenants', function (Blueprint $table) {
            // SaaS Billing Rates (override defaults from config/saas.php)
            if (!Schema::hasColumn('tenants', 'pppoe_rate')) {
                $table->decimal('pppoe_rate', 10, 2)->nullable()->after('subscription_ends_at')
                    ->comment('Custom PPPoE user rate (KES per user), null = use default');
            }
            
            if (!Schema::hasColumn('tenants', 'hotspot_revenue_pct')) {
                $table->decimal('hotspot_revenue_pct', 5, 2)->nullable()->after('pppoe_rate')
                    ->comment('Custom hotspot revenue percentage, null = use default');
            }
            
            if (!Schema::hasColumn('tenants', 'router_rate')) {
                $table->decimal('router_rate', 10, 2)->nullable()->after('hotspot_revenue_pct')
                    ->comment('Custom router rate (KES per router), null = use default');
            }

            // Landlord Override Controls
            if (!Schema::hasColumn('tenants', 'landlord_override')) {
                $table->boolean('landlord_override')->default(false)->after('router_rate')
                    ->comment('If true, landlord prevents automatic service disconnection');
            }
            
            if (!Schema::hasColumn('tenants', 'landlord_override_reason')) {
                $table->string('landlord_override_reason')->nullable()->after('landlord_override')
                    ->comment('Reason for landlord override');
            }
            
            if (!Schema::hasColumn('tenants', 'landlord_override_until')) {
                $table->timestamp('landlord_override_until')->nullable()->after('landlord_override_reason')
                    ->comment('Override expiry date (null = indefinite)');
            }

            // Billing Tracking
            if (!Schema::hasColumn('tenants', 'last_invoice_at')) {
                $table->timestamp('last_invoice_at')->nullable()->after('landlord_override_until');
            }
            
            if (!Schema::hasColumn('tenants', 'last_invoice_amount')) {
                $table->decimal('last_invoice_amount', 12, 2)->nullable()->after('last_invoice_at');
            }
            
            if (!Schema::hasColumn('tenants', 'subscription_warning_sent_at')) {
                $table->timestamp('subscription_warning_sent_at')->nullable()->after('last_invoice_amount')
                    ->comment('When the 5-day pre-expiry warning was sent');
            }

            // Custom Paybill (if tenant uses landlord paybill, this is null)
            if (!Schema::hasColumn('tenants', 'custom_paybill')) {
                $table->string('custom_paybill')->nullable()->after('subscription_warning_sent_at')
                    ->comment('Tenant custom paybill, null = use landlord default');
            }

            // Landlord Tenant Flag
            if (!Schema::hasColumn('tenants', 'is_landlord')) {
                $table->boolean('is_landlord')->default(false)->after('is_active')
                    ->comment('True if this is the system landlord tenant');
            }
        });

        // Add indexes for performance
        Schema::table('tenants', function (Blueprint $table) {
            // Index for subscription enforcement queries
            if (!$this->hasIndex('tenants', 'tenants_subscription_enforcement_idx')) {
                $table->index(['subscription_ends_at', 'is_active', 'landlord_override'], 'tenants_subscription_enforcement_idx');
            }
            
            if (!$this->hasIndex('tenants', 'tenants_is_landlord_idx')) {
                $table->index('is_landlord', 'tenants_is_landlord_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'pppoe_rate',
                'hotspot_revenue_pct',
                'router_rate',
                'landlord_override',
                'landlord_override_reason',
                'landlord_override_until',
                'last_invoice_at',
                'last_invoice_amount',
                'subscription_warning_sent_at',
                'custom_paybill',
                'is_landlord',
            ]);
        });
    }

    /**
     * Check if an index exists on the table.
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $conn = Schema::getConnection();
        $dbSchemaManager = $conn->getDoctrineSchemaManager();
        
        try {
            $indexes = $dbSchemaManager->listTableIndexes($table);
            return isset($indexes[strtolower($indexName)]);
        } catch (\Exception $e) {
            return false;
        }
    }
};
