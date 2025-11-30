# PowerShell Deployment Script - WiFi Hotspot Management System
# Automatically runs migrations and seeders after build

param(
    [string]$Environment = "production",
    [switch]$FreshInstall = $false
)

$ErrorActionPreference = "Stop"

Write-Host "🚀 Starting deployment..." -ForegroundColor Green
Write-Host ""
Write-Host "📍 Environment: $Environment" -ForegroundColor Cyan
Write-Host ""

###############################################################################
# 1. WAIT FOR DATABASE
###############################################################################
Write-Host "⏳ Waiting for database to be ready..." -ForegroundColor Yellow

$maxTries = 30
$tries = 0
$dbReady = $false

while (-not $dbReady -and $tries -lt $maxTries) {
    $tries++
    Write-Host "   Attempt $tries/$maxTries..."
    
    try {
        php artisan db:show 2>&1 | Out-Null
        $dbReady = $true
    }
    catch {
        Start-Sleep -Seconds 2
    }
}

if (-not $dbReady) {
    Write-Host "❌ Database connection failed after $maxTries attempts" -ForegroundColor Red
    exit 1
}

Write-Host "✅ Database is ready" -ForegroundColor Green
Write-Host ""

###############################################################################
# 2. RUN MIGRATIONS
###############################################################################
Write-Host "🔄 Running database migrations..." -ForegroundColor Yellow

if ($Environment -eq "production") {
    # Production: Run migrations without prompts
    php artisan migrate --force
}
else {
    # Development/Staging
    if ($FreshInstall) {
        Write-Host "⚠️  Running fresh migrations (will drop all tables)" -ForegroundColor Yellow
        php artisan migrate:fresh --force
    }
    else {
        php artisan migrate --force
    }
}

Write-Host "✅ Migrations completed" -ForegroundColor Green
Write-Host ""

###############################################################################
# 3. RUN SEEDERS
###############################################################################
Write-Host "🌱 Running database seeders..." -ForegroundColor Yellow

if ($Environment -eq "production") {
    # Production: Only run essential seeders
    php artisan db:seed --class=DefaultTenantSeeder --force
    php artisan db:seed --class=DefaultSystemAdminSeeder --force
    Write-Host "ℹ️  Demo data seeder skipped in production" -ForegroundColor Yellow
}
else {
    # Development/Staging: Run all seeders
    php artisan db:seed --force
}

Write-Host "✅ Seeders completed" -ForegroundColor Green
Write-Host ""

###############################################################################
# 4. CACHE OPTIMIZATION
###############################################################################
Write-Host "⚡ Optimizing application..." -ForegroundColor Yellow

php artisan config:cache
php artisan route:cache
php artisan view:cache

Write-Host "✅ Optimization completed" -ForegroundColor Green
Write-Host ""

###############################################################################
# 5. STORAGE LINK
###############################################################################
Write-Host "🔗 Creating storage link..." -ForegroundColor Yellow

try {
    php artisan storage:link
}
catch {
    Write-Host "Storage link already exists" -ForegroundColor Gray
}

Write-Host ""

###############################################################################
# DEPLOYMENT COMPLETE
###############################################################################
Write-Host ""
Write-Host "═══════════════════════════════════════════════════════════" -ForegroundColor Green
Write-Host "✅ Deployment completed successfully!" -ForegroundColor Green
Write-Host "═══════════════════════════════════════════════════════════" -ForegroundColor Green
Write-Host ""

if ($Environment -ne "production") {
    Write-Host "📝 Demo Accounts Created:" -ForegroundColor Cyan
    Write-Host "   System Admin: sysadmin@system.local / Admin@123!"
    Write-Host "   Tenant A Admin: admin-a@tenant-a.com / Password123!"
    Write-Host "   Tenant B Admin: admin-b@tenant-b.com / Password123!"
    Write-Host ""
    Write-Host "⚠️  IMPORTANT: Change default passwords immediately!" -ForegroundColor Yellow
    Write-Host ""
}

Write-Host "🎉 Application is ready!" -ForegroundColor Green
