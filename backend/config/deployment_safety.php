<?php

return [
    'require_pre_deploy_snapshot' => env('DEPLOYMENT_REQUIRE_PRE_DEPLOY_SNAPSHOT', true),
    'allow_snapshot_exemption' => env('DEPLOYMENT_ALLOW_SNAPSHOT_EXEMPTION', false),
    'verify_after_deploy' => env('DEPLOYMENT_VERIFY_AFTER_DEPLOY', true),
    'auto_rollback_on_failed_apply' => env('DEPLOYMENT_AUTO_ROLLBACK_ON_FAILED_APPLY', true),
    'auto_rollback_on_failed_checks' => env('DEPLOYMENT_AUTO_ROLLBACK_ON_FAILED_CHECKS', true),
];
