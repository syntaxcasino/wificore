#!/usr/bin/env pwsh
# Verification Script for Container Optimization
# This script verifies that optimizations are working correctly

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Container Optimization Verification" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Expected sizes (in MB)
$expectedSizes = @{
    "wifi-hotspot-traidnet-backend" = 150
    "wifi-hotspot-traidnet-soketi" = 60
    "wifi-hotspot-traidnet-freeradius" = 40
    "wifi-hotspot-traidnet-frontend" = 35
    "wifi-hotspot-traidnet-nginx" = 25
}

Write-Host "Checking Container Sizes..." -ForegroundColor Yellow
Write-Host ""

$allPassed = $true

# Get all traidnet images
$images = docker images --format "{{.Repository}}:{{.Size}}" | Select-String "wifi-hotspot-traidnet"

foreach ($image in $images) {
    $parts = $image -split ":"
    $name = $parts[0]
    $sizeStr = $parts[1]
    
    # Convert size to MB
    if ($sizeStr -match "(\d+\.?\d*)GB") {
        $sizeMB = [double]$matches[1] * 1024
    }
    elseif ($sizeStr -match "(\d+\.?\d*)MB") {
        $sizeMB = [double]$matches[1]
    }
    else {
        $sizeMB = 0
    }
    
    # Check against expected size
    $containerName = $name -replace "wifi-hotspot-", ""
    if ($expectedSizes.ContainsKey($name)) {
        $expected = $expectedSizes[$name]
        if ($sizeMB -le $expected) {
            Write-Host "✓ $containerName : $sizeStr (Expected: <$expected MB)" -ForegroundColor Green
        }
        else {
            Write-Host "✗ $containerName : $sizeStr (Expected: <$expected MB)" -ForegroundColor Red
            $allPassed = $false
        }
    }
    else {
        Write-Host "  $containerName : $sizeStr" -ForegroundColor Gray
    }
}

Write-Host ""
Write-Host "Checking Container Health..." -ForegroundColor Yellow
Write-Host ""

$containers = docker-compose ps --format json | ConvertFrom-Json

foreach ($container in $containers) {
    $status = $container.State
    $name = $container.Service
    
    if ($status -eq "running") {
        Write-Host "✓ $name is running" -ForegroundColor Green
    }
    else {
        Write-Host "✗ $name is $status" -ForegroundColor Red
        $allPassed = $false
    }
}

Write-Host ""
Write-Host "Checking Application Endpoints..." -ForegroundColor Yellow
Write-Host ""

# Test backend health
try {
    $response = Invoke-WebRequest -Uri "http://localhost/api/health" -UseBasicParsing -TimeoutSec 5
    if ($response.StatusCode -eq 200) {
        Write-Host "✓ Backend API is responding" -ForegroundColor Green
    }
}
catch {
    Write-Host "✗ Backend API is not responding" -ForegroundColor Red
    $allPassed = $false
}

# Test frontend
try {
    $response = Invoke-WebRequest -Uri "http://localhost" -UseBasicParsing -TimeoutSec 5
    if ($response.StatusCode -eq 200) {
        Write-Host "✓ Frontend is responding" -ForegroundColor Green
    }
}
catch {
    Write-Host "✗ Frontend is not responding" -ForegroundColor Red
    $allPassed = $false
}

# Test Soketi
try {
    $response = Invoke-WebRequest -Uri "http://localhost:9601" -UseBasicParsing -TimeoutSec 5
    if ($response.StatusCode -eq 200) {
        Write-Host "✓ Soketi metrics endpoint is responding" -ForegroundColor Green
    }
}
catch {
    Write-Host "✗ Soketi is not responding" -ForegroundColor Red
    $allPassed = $false
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan

if ($allPassed) {
    Write-Host "All Checks Passed! ✓" -ForegroundColor Green
    Write-Host "Optimization is successful!" -ForegroundColor Green
}
else {
    Write-Host "Some Checks Failed! ✗" -ForegroundColor Red
    Write-Host "Please review the errors above." -ForegroundColor Yellow
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Show resource usage
Write-Host "Current Resource Usage:" -ForegroundColor Yellow
docker stats --no-stream --format "table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}"
Write-Host ""
