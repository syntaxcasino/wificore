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
        'app/Services/RouterTaskExecutionService.php',
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
    ],
];
