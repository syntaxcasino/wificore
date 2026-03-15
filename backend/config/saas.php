<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SaaS Billing Configuration
    |--------------------------------------------------------------------------
    |
    | Default billing rates and configuration for the multi-tenant SaaS system.
    | These values are used when a tenant does not have custom billing settings.
    |
    */

    // Default M-Pesa Paybill for SaaS payments (landlord receives payments here)
    'default_paybill' => env('SAAS_DEFAULT_PAYBILL', ''),
    'default_paybill_name' => env('SAAS_PAYBILL_NAME', 'WifiCore SaaS'),

    // PPPoE User Billing Rate (per user per month)
    'pppoe_rate' => (float) env('SAAS_PPPOE_RATE', 30.00), // KES per PPPoE user

    // Hotspot Revenue Percentage (landlord takes % of tenant hotspot revenue)
    'hotspot_revenue_pct' => (float) env('SAAS_HOTSPOT_REVENUE_PCT', 2.0), // 2%

    // Resource-based usage factors
    'resource_factors' => [
        'router_rate' => (float) env('SAAS_ROUTER_RATE', 100.00), // KES per router per month
        'bandwidth_rate' => (float) env('SAAS_BANDWIDTH_RATE', 0.50), // KES per GB
        'storage_rate' => (float) env('SAAS_STORAGE_RATE', 10.00), // KES per GB stored
    ],

    // Minimum subscription amount
    'minimum_subscription' => (float) env('SAAS_MINIMUM_SUBSCRIPTION', 500.00),

    // Subscription Plans
    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'max_pppoe_users' => 50,
            'max_hotspot_users' => 100,
            'max_routers' => 3,
            'base_price' => 1000.00,
        ],
        'professional' => [
            'name' => 'Professional',
            'max_pppoe_users' => 200,
            'max_hotspot_users' => 500,
            'max_routers' => 10,
            'base_price' => 3000.00,
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'max_pppoe_users' => -1, // Unlimited
            'max_hotspot_users' => -1, // Unlimited
            'max_routers' => -1, // Unlimited
            'base_price' => 10000.00,
        ],
    ],

    // Subscription Enforcement Settings
    'enforcement' => [
        'warning_days' => 5, // Days before expiry to send warning
        'grace_period_days' => 0, // No grace period for SaaS tenants
        'auto_suspend' => true, // Automatically suspend on expiry
        'check_interval_minutes' => 5, // How often to check subscriptions
    ],

    'pppoe_billing' => [
        'legacy_status_job_enabled' => env('PPPOE_LEGACY_STATUS_JOB_ENABLED', false),
    ],

    // Notification Settings
    'notifications' => [
        'invoice_enabled' => true,
        'receipt_enabled' => true,
        'expiry_warning_enabled' => true,
        'disconnection_enabled' => true,
        'queue' => 'notifications',
    ],

    // Landlord Settings
    'landlord' => [
        'tenant_slug' => 'system-landlord',
        'tenant_name' => 'System Landlord',
        'default_admin_email' => env('SAAS_LANDLORD_EMAIL', 'landlord@wificore.local'),
        'default_admin_password' => env('SAAS_LANDLORD_PASSWORD', 'Landlord@123!'),
    ],
];
