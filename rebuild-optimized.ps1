#!/usr/bin/env pwsh
# Container Optimization Rebuild Script
# This script rebuilds all containers with optimized Dockerfiles

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Container Optimization Rebuild Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Function to check if Docker is running
function Test-DockerRunning {
    try {
        docker info | Out-Null
        return $true
    }
    catch {
        return $false
    }
}

# Check Docker
Write-Host "Checking Docker..." -ForegroundColor Yellow
if (-not (Test-DockerRunning)) {
    Write-Host "ERROR: Docker is not running. Please start Docker Desktop." -ForegroundColor Red
    exit 1
}
Write-Host "✓ Docker is running" -ForegroundColor Green
Write-Host ""

# Show current container sizes
Write-Host "Current Container Sizes:" -ForegroundColor Yellow
docker images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}" | Select-String "traidnet"
Write-Host ""

# Confirm rebuild
$confirm = Read-Host "Do you want to rebuild all containers with optimized Dockerfiles? (y/N)"
if ($confirm -ne 'y' -and $confirm -ne 'Y') {
    Write-Host "Rebuild cancelled." -ForegroundColor Yellow
    exit 0
}

Write-Host ""
Write-Host "Step 1: Stopping containers..." -ForegroundColor Yellow
docker-compose down
Write-Host "✓ Containers stopped" -ForegroundColor Green
Write-Host ""

Write-Host "Step 2: Removing old images..." -ForegroundColor Yellow
docker images --format "{{.Repository}}:{{.Tag}}" | Select-String "wifi-hotspot-traidnet" | ForEach-Object {
    Write-Host "  Removing $_" -ForegroundColor Gray
    docker rmi $_ -f 2>$null
}
Write-Host "✓ Old images removed" -ForegroundColor Green
Write-Host ""

Write-Host "Step 3: Building optimized containers..." -ForegroundColor Yellow
Write-Host "  This may take several minutes..." -ForegroundColor Gray
docker-compose build --no-cache --progress=plain

if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Build failed!" -ForegroundColor Red
    exit 1
}
Write-Host "✓ Build completed" -ForegroundColor Green
Write-Host ""

Write-Host "Step 4: Starting containers..." -ForegroundColor Yellow
docker-compose up -d

if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to start containers!" -ForegroundColor Red
    exit 1
}
Write-Host "✓ Containers started" -ForegroundColor Green
Write-Host ""

Write-Host "Step 5: Waiting for containers to be healthy..." -ForegroundColor Yellow
Start-Sleep -Seconds 10
Write-Host ""

Write-Host "New Container Sizes:" -ForegroundColor Yellow
docker images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}" | Select-String "traidnet"
Write-Host ""

Write-Host "Container Status:" -ForegroundColor Yellow
docker-compose ps
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Optimization Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next Steps:" -ForegroundColor Yellow
Write-Host "1. Check container health: docker-compose ps" -ForegroundColor White
Write-Host "2. View logs: docker-compose logs -f" -ForegroundColor White
Write-Host "3. Monitor resources: docker stats" -ForegroundColor White
Write-Host ""
Write-Host "If issues occur, rollback with:" -ForegroundColor Yellow
Write-Host "  .\rollback-dockerfiles.ps1" -ForegroundColor White
Write-Host ""
