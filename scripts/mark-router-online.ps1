# Mark Router as Online (Testing/Development)
# Usage: .\mark-router-online.ps1 <router_id>

param(
    [Parameter(Mandatory=$true)]
    [int]$RouterId
)

$DB_CONTAINER = "traidnet-postgres"
$DB_NAME = "wifi_hotspot"
$DB_USER = "admin"

Write-Host "==========================================" -ForegroundColor Blue
Write-Host "Mark Router as Online (Testing)" -ForegroundColor Blue
Write-Host "==========================================" -ForegroundColor Blue
Write-Host ""

# Check Docker
try {
    docker --version | Out-Null
} catch {
    Write-Host "Error: Docker not installed" -ForegroundColor Red
    exit 1
}

# Check container
$containerRunning = docker ps --format "{{.Names}}" | Select-String -Pattern "^$DB_CONTAINER$"
if (-not $containerRunning) {
    Write-Host "Error: Container not running" -ForegroundColor Red
    exit 1
}

function Invoke-SQL {
    param([string]$Query)
    $result = docker exec $DB_CONTAINER psql -U $DB_USER -d $DB_NAME -t -c "$Query" 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host "Error: $result" -ForegroundColor Red
        exit 1
    }
    return $result
}

Write-Host "Checking router ID: $RouterId" -ForegroundColor Blue

# Check if router exists
$routerExists = Invoke-SQL "SELECT COUNT(*) FROM routers WHERE id = $RouterId;"
if ($routerExists.Trim() -eq "0") {
    Write-Host "Router ID $RouterId not found" -ForegroundColor Red
    exit 1
}

# Get router info
$routerInfo = Invoke-SQL "SELECT name, status FROM routers WHERE id = $RouterId;"
Write-Host "Router: $routerInfo" -ForegroundColor Yellow

# Update router status to online
Invoke-SQL "UPDATE routers SET status = 'online', last_seen = NOW(), updated_at = NOW() WHERE id = $RouterId;" | Out-Null

Write-Host ""
Write-Host "Router marked as ONLINE!" -ForegroundColor Green
Write-Host ""
Write-Host "The provisioning modal should now proceed to the next step." -ForegroundColor Cyan
Write-Host ""
Write-Host "==========================================" -ForegroundColor Green
Write-Host "Complete!" -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor Green
