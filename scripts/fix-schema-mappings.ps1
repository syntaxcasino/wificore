# Fix Schema Mappings Script (PowerShell)
# This script fixes missing schema mappings for tenant users

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "Schema Mapping Fix Script" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# Check if Docker is running
try {
    docker ps | Out-Null
} catch {
    Write-Host "Error: Docker is not running or you don't have permission to access it." -ForegroundColor Red
    exit 1
}

# Check if backend container is running
$containerRunning = docker ps --format "{{.Names}}" | Select-String "wificore-backend"
if (-not $containerRunning) {
    Write-Host "Error: wificore-backend container is not running." -ForegroundColor Red
    Write-Host "Please start the containers first:" -ForegroundColor Yellow
    Write-Host "  cd backend && docker-compose up -d" -ForegroundColor Yellow
    exit 1
}

Write-Host "Checking current schema mappings..." -ForegroundColor Yellow
Write-Host ""

# Show current count
$currentCount = docker exec wificore-backend php artisan tinker --execute="echo DB::table('radius_user_schema_mapping')->count();"
Write-Host "Current schema mappings: $currentCount" -ForegroundColor Green
Write-Host ""

# Show users without mappings
Write-Host "Checking for users without schema mappings..." -ForegroundColor Yellow
docker exec wificore-backend php artisan tinker --execute="`$users = DB::select('SELECT u.id, u.username, u.tenant_id, t.schema_name, CASE WHEN m.id IS NULL THEN ''MISSING'' ELSE ''EXISTS'' END as mapping_status FROM users u LEFT JOIN tenants t ON u.tenant_id = t.id LEFT JOIN radius_user_schema_mapping m ON u.username = m.username WHERE u.tenant_id IS NOT NULL ORDER BY mapping_status DESC'); foreach (`$users as `$user) { if (`$user->mapping_status === 'MISSING') { echo \"❌ MISSING: {`$user->username} (Tenant ID: {`$user->tenant_id})\n\"; } }"
Write-Host ""

# Ask for confirmation
$confirmation = Read-Host "Do you want to fix missing schema mappings? (y/n)"
if ($confirmation -ne 'y' -and $confirmation -ne 'Y') {
    Write-Host "Cancelled." -ForegroundColor Yellow
    exit 0
}

Write-Host ""
Write-Host "Running fix command..." -ForegroundColor Yellow
Write-Host ""

# Run the fix command
docker exec wificore-backend php artisan tenants:fix-schema-mappings

Write-Host ""
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "Fix completed!" -ForegroundColor Green
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# Show new count
$newCount = docker exec wificore-backend php artisan tinker --execute="echo DB::table('radius_user_schema_mapping')->count();"
$created = [int]$newCount - [int]$currentCount
Write-Host "Schema mappings after fix: $newCount" -ForegroundColor Green
Write-Host "Mappings created: $created" -ForegroundColor Green
Write-Host ""

Write-Host "You can now test login at: https://wificore.traidsolutions.com/login" -ForegroundColor Cyan
Write-Host ""
