<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tenant Isolation Guardrails
    |--------------------------------------------------------------------------
    |
    | Phase-A safety gate scanner: tracks high-risk query bypass patterns in
    | provisioning-critical execution files. Keep this list tight and explicit.
    |
    */
    'critical_files' => [
        'app/Http/Controllers/Api/InternalProvisioningTaskController.php',
        'app/Jobs/RouterProvisioningJob.php',
        'app/Jobs/RollbackRouterConfigJob.php',
        'app/Jobs/DeployRouterConfigJob.php',
        'app/Jobs/ExecuteProvisioningServiceRouterTaskJob.php',
        'app/Http/Controllers/Api/HotspotController.php',
        'app/Http/Controllers/Api/PppoeUserController.php',
        'app/Http/Controllers/Api/PaymentController.php',
        'app/Http/Controllers/Api/PppoePortalController.php',
        'app/Http/Controllers/Api/PppoeSessionController.php',
        'app/Http/Controllers/Api/ConnectionStatsController.php',
        'app/Http/Controllers/Api/UnifiedStreamController.php',
        'app/Http/Middleware/SetTenantContext.php',
        'app/Http/Middleware/PppoePortalAuth.php',
        'app/Http/Middleware/PppoePortalAuthOptimized.php',
        'app/Models/HotspotUser.php',
        'app/Models/Payment.php',
        'app/Models/PppoeUser.php',
        'app/Models/RadiusSession.php',
        'app/Models/UserSession.php',
        'app/Services/RouterTaskExecutionService.php',
        'app/Services/ProvisioningRunAuditService.php',
        'app/Services/TenantContext.php',
        'app/Traits/TenantAwareJob.php',
    ],

    'forbidden_patterns' => [
        '/withoutGlobalScope\s*\(/',
        '/withoutGlobalScopes\s*\(/',
        '/DB::table\s*\(/',
    ],

    // Files that are explicitly reviewed and allowed to use forbidden patterns.
    'allowlist' => [
        'app/Services/TenantContext.php',
        'app/Http/Controllers/Api/HotspotController.php',
        'app/Http/Controllers/Api/PppoeUserController.php',
        'app/Http/Controllers/Api/PaymentController.php',
        'app/Http/Controllers/Api/PppoePortalController.php',
        'app/Http/Controllers/Api/PppoeSessionController.php',
        'app/Http/Controllers/Api/ConnectionStatsController.php',
        'app/Http/Controllers/Api/UnifiedStreamController.php',
        'app/Http/Middleware/PppoePortalAuth.php',
        'app/Http/Middleware/PppoePortalAuthOptimized.php',
        'app/Models/PppoeUser.php',
    ],
];
