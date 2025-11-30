# Fix Migration Order Script
# Renames all migrations to correct dependency order

Write-Host "Fixing migration order..." -ForegroundColor Cyan

$migrations = @{
    # Level 0 - No dependencies
    "0001_01_01_000000_create_tenants_table.php" = "2025_01_01_000001_create_tenants_table.php"
    "0001_01_01_000002_create_cache_table.php" = "2025_01_01_000002_create_cache_table.php"
    "0001_01_01_000003_create_jobs_table.php" = "2025_01_01_000003_create_jobs_table.php"
    "2025_06_22_115324_create_personal_access_tokens_table.php" = "2025_01_01_000004_create_personal_access_tokens_table.php"
    
    # Level 1 - Depend on tenants
    "0001_01_01_000001_create_users_table.php" = "2025_01_02_000001_create_users_table.php"
    "2025_06_22_124849_create_packages_table.php" = "2025_01_02_000002_create_packages_table.php"
    "2025_07_27_143410_create_routers_table.php" = "2025_01_02_000003_create_routers_table.php"
    
    # Level 2 - Depend on Level 1
    "2025_06_22_120557_create_user_sessions_table.php" = "2025_01_03_000001_create_user_sessions_table.php"
    "2025_06_22_120601_create_system_logs_table.php" = "2025_01_03_000002_create_system_logs_table.php"
    "2025_06_28_054023_create_vouchers_table.php" = "2025_01_03_000003_create_vouchers_table.php"
    "2025_07_01_000001_create_hotspot_users_table.php" = "2025_01_03_000004_create_hotspot_users_table.php"
    "2025_07_27_150000_create_payments_table.php" = "2025_01_03_000005_create_payments_table.php"
    "2025_07_28_000001_create_router_vpn_configs_table.php" = "2025_01_03_000006_create_router_vpn_configs_table.php"
    "2025_10_11_085900_create_router_services_table.php" = "2025_01_03_000007_create_router_services_table.php"
    "2025_10_11_090000_create_access_points_table.php" = "2025_01_03_000008_create_access_points_table.php"
    "2025_10_17_000001_create_performance_metrics_table.php" = "2025_01_03_000009_create_performance_metrics_table.php"
    
    # Level 3 - Depend on Level 2
    "2025_07_01_000002_create_hotspot_sessions_table.php" = "2025_01_04_000001_create_hotspot_sessions_table.php"
    "2025_10_11_090100_create_ap_active_sessions_table.php" = "2025_01_04_000002_create_ap_active_sessions_table.php"
    "2025_10_11_090200_create_service_control_logs_table.php" = "2025_01_04_000003_create_service_control_logs_table.php"
    "2025_10_11_090300_create_payment_reminders_table.php" = "2025_01_04_000004_create_payment_reminders_table.php"
    
    # Level 4 - Partitioning (last)
    "2025_10_28_000003_implement_table_partitioning.php" = "2025_01_05_000001_implement_table_partitioning.php"
}

$basePath = "database\migrations"

foreach ($old in $migrations.Keys) {
    $new = $migrations[$old]
    $oldPath = Join-Path $basePath $old
    $newPath = Join-Path $basePath $new
    
    if (Test-Path $oldPath) {
        Rename-Item -Path $oldPath -NewName $new -Force
        Write-Host "Renamed: $old -> $new" -ForegroundColor Green
    } else {
        Write-Host "Not found: $old" -ForegroundColor Yellow
    }
}

Write-Host ""
Write-Host "Migration order fixed!" -ForegroundColor Green
Write-Host "All migrations now run in correct dependency order" -ForegroundColor Cyan
