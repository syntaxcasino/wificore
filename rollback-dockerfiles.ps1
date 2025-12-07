#!/usr/bin/env pwsh
# Rollback Script for Container Optimization
# This script restores original Dockerfiles from backups

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Dockerfile Rollback Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$confirm = Read-Host "Do you want to rollback to original Dockerfiles? (y/N)"
if ($confirm -ne 'y' -and $confirm -ne 'Y') {
    Write-Host "Rollback cancelled." -ForegroundColor Yellow
    exit 0
}

Write-Host ""
Write-Host "Restoring original Dockerfiles..." -ForegroundColor Yellow

# Restore backend
if (Test-Path ".\backend\Dockerfile.backup") {
    Copy-Item -Path ".\backend\Dockerfile.backup" -Destination ".\backend\Dockerfile" -Force
    Write-Host "✓ Backend Dockerfile restored" -ForegroundColor Green
}

# Restore frontend
if (Test-Path ".\frontend\Dockerfile.backup") {
    Copy-Item -Path ".\frontend\Dockerfile.backup" -Destination ".\frontend\Dockerfile" -Force
    Write-Host "✓ Frontend Dockerfile restored" -ForegroundColor Green
}

# Restore soketi
if (Test-Path ".\soketi\Dockerfile.backup") {
    Copy-Item -Path ".\soketi\Dockerfile.backup" -Destination ".\soketi\Dockerfile" -Force
    Write-Host "✓ Soketi Dockerfile restored" -ForegroundColor Green
}

# Restore freeradius
if (Test-Path ".\freeradius\Dockerfile.backup") {
    Copy-Item -Path ".\freeradius\Dockerfile.backup" -Destination ".\freeradius\Dockerfile" -Force
    Write-Host "✓ FreeRADIUS Dockerfile restored" -ForegroundColor Green
}

Write-Host ""
Write-Host "Rebuilding containers with original Dockerfiles..." -ForegroundColor Yellow
docker-compose down
docker-compose build --no-cache
docker-compose up -d

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Rollback Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
