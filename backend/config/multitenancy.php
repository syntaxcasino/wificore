<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Multitenancy Mode
    |--------------------------------------------------------------------------
    |
    | Defines the multitenancy strategy (SINGLE MODE ONLY):
    | - 'schema': Each tenant gets their own PostgreSQL schema (DEFAULT)
    | - 'shared': All tenants share tables with tenant_id (legacy mode)
    |
    | NOTE: System operates in SINGLE MODE only. No hybrid support.
    | Default is 'schema' mode for better isolation and performance.
    |
    */
    'mode' => env('MULTITENANCY_MODE', 'schema'),
    
    /*
    |--------------------------------------------------------------------------
    | System Schema
    |--------------------------------------------------------------------------
    |
    | The default PostgreSQL schema for system-wide tables
    |
    */
    'system_schema' => 'public',
    
    /*
    |--------------------------------------------------------------------------
    | Tenant Schema Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for tenant schema names. Schema names will be: {prefix}{slug}
    | Example: tenant_abc_company
    |
    */
    'tenant_schema_prefix' => 'tenant_',
    
    /*
    |--------------------------------------------------------------------------
    | System Tables
    |--------------------------------------------------------------------------
    |
    | Tables that exist in the public schema and are shared across all tenants
    |
    */
    'system_tables' => [
        'tenants',
        'users',
        'migrations',
        'failed_jobs',
        'jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
        'personal_access_tokens',
        'radius_user_schema_mapping',
        'system_logs',
        'system_metrics',
        'performance_metrics',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Tenant Tables
    |--------------------------------------------------------------------------
    |
    | Tables that should exist in each tenant schema for data isolation
    | These are WiFi hotspot management specific tables
    |
    */
    'tenant_tables' => [
        // Core WiFi Management
        'routers',
        'router_configs',
        'router_services',
        'router_vpn_configs',
        
        // Access Points
        'access_points',
        'ap_active_sessions',
        
        // Packages & Subscriptions
        'packages',
        'user_subscriptions',
        
        // Hotspot Users & Sessions
        'hotspot_users',
        'hotspot_sessions',
        'hotspot_credentials',
        'radius_sessions',
        'session_disconnections',
        
        // Payments & Vouchers
        'payments',
        'payment_reminders',
        'vouchers',
        
        // WireGuard VPN
        'wireguard_peers',
        
        // Monitoring & Logs
        'data_usage_logs',
        'service_control_logs',
        
        // RADIUS Tables (per tenant)
        'radcheck',
        'radreply',
        'radacct',
        'radpostauth',
        'radusergroup',
        'radgroupcheck',
        'radgroupreply',
        'nas',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Auto Create Schema
    |--------------------------------------------------------------------------
    |
    | Automatically create tenant schema when a new tenant is registered
    |
    */
    'auto_create_schema' => env('AUTO_CREATE_TENANT_SCHEMA', true),
    
    /*
    |--------------------------------------------------------------------------
    | Auto Migrate Schema
    |--------------------------------------------------------------------------
    |
    | Automatically run migrations when a tenant schema is created
    |
    */
    'auto_migrate_schema' => env('AUTO_MIGRATE_TENANT_SCHEMA', true),
    
    /*
    |--------------------------------------------------------------------------
    | Auto Seed Schema
    |--------------------------------------------------------------------------
    |
    | Automatically seed default data when a tenant schema is created
    |
    */
    'auto_seed_schema' => env('AUTO_SEED_TENANT_SCHEMA', false),
    
    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Cache settings for tenant objects to improve performance
    |
    */
    'cache' => [
        'enabled' => env('MULTITENANCY_CACHE_ENABLED', true),
        'prefix' => 'tenant:',
        'ttl' => env('MULTITENANCY_CACHE_TTL', 3600), // 1 hour
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Tenant Limits
    |--------------------------------------------------------------------------
    |
    | Resource limits per tenant
    |
    */
    'limits' => [
        'max_size_mb' => env('TENANT_MAX_SIZE_MB', 10240), // 10 GB
        'max_routers' => env('TENANT_MAX_ROUTERS', 100),
        'max_users' => env('TENANT_MAX_USERS', 10000),
        'max_packages' => env('TENANT_MAX_PACKAGES', 50),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Schema Backup
    |--------------------------------------------------------------------------
    |
    | Enable automatic schema backups
    |
    */
    'backup_enabled' => env('TENANT_BACKUP_ENABLED', true),
    'backup_path' => storage_path('app/tenant-backups'),
    'backup_retention_days' => env('TENANT_BACKUP_RETENTION_DAYS', 30),
    
    /*
    |--------------------------------------------------------------------------
    | Migration Path
    |--------------------------------------------------------------------------
    |
    | Path to tenant-specific migrations
    |
    */
    'migrations_path' => database_path('migrations/tenant'),
    
    /*
    |--------------------------------------------------------------------------
    | Seeder Class
    |--------------------------------------------------------------------------
    |
    | Default seeder class for tenant data
    |
    */
    'seeder_class' => 'Database\\Seeders\\TenantSeeder',
    
    /*
    |--------------------------------------------------------------------------
    | Excluded Routes
    |--------------------------------------------------------------------------
    |
    | Routes that should not have tenant context set
    |
    */
    'excluded_routes' => [
        'health',
        'sanctum/*',
        'broadcasting/auth',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | System Admin Roles
    |--------------------------------------------------------------------------
    |
    | Roles that should not have tenant context set (system-wide access)
    |
    */
    'system_admin_roles' => [
        'system_admin',
        'super_admin',
    ],
];
