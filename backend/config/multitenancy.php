<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy Mode
    |--------------------------------------------------------------------------
    |
    | Supported modes: 'schema', 'hybrid'
    | - schema: Full schema-based isolation (recommended for production)
    | - hybrid: Supports both schema-based and tenant_id filtering (migration mode)
    |
    */
    'mode' => env('MULTITENANCY_MODE', 'hybrid'),
    
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
    | Auto-Create Schema
    |--------------------------------------------------------------------------
    |
    | Automatically create tenant schema when a new tenant is registered
    |
    */
    'auto_create_schema' => env('MULTITENANCY_AUTO_CREATE_SCHEMA', true),
    
    /*
    |--------------------------------------------------------------------------
    | Auto-Migrate Schema
    |--------------------------------------------------------------------------
    |
    | Automatically run tenant migrations when a schema is created
    |
    */
    'auto_migrate_schema' => env('MULTITENANCY_AUTO_MIGRATE_SCHEMA', true),
    
    /*
    |--------------------------------------------------------------------------
    | Auto-Seed Schema
    |--------------------------------------------------------------------------
    |
    | Automatically seed tenant data when a schema is created
    |
    */
    'auto_seed_schema' => env('MULTITENANCY_AUTO_SEED_SCHEMA', false),
    
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
    | Backup Configuration
    |--------------------------------------------------------------------------
    |
    | Backup settings for tenant schemas
    |
    */
    'backup' => [
        'enabled' => env('MULTITENANCY_BACKUP_ENABLED', true),
        'path' => storage_path('backups/tenants'),
        'retention_days' => env('TENANT_BACKUP_RETENTION_DAYS', 30),
    ],
    
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
