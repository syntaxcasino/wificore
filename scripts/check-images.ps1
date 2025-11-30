################################################################################
# Check Docker Images Status
# Quick script to see what images exist locally and on Docker Hub
################################################################################

param(
    [switch]$Local,
    [switch]$Hub,
    [switch]$All
)

$DockerUsername = "kja2aro"
$Images = @(
    "traidnet-nginx",
    "traidnet-frontend",
    "traidnet-backend",
    "traidnet-soketi",
    "traidnet-freeradius"
)

Write-Host "`n================================" -ForegroundColor Blue
Write-Host "Docker Images Status Check" -ForegroundColor Blue
Write-Host "================================`n" -ForegroundColor Blue

if ($Local -or $All -or (-not $Hub)) {
    Write-Host "Local Images:" -ForegroundColor Cyan
    Write-Host "-------------`n" -ForegroundColor Cyan
    
    foreach ($image in $Images) {
        $fullImage = "$DockerUsername/$image"
        $localImages = docker images $fullImage --format "{{.Repository}}:{{.Tag}} ({{.Size}})" 2>$null
        
        if ($localImages) {
            Write-Host "✓ $image" -ForegroundColor Green
            $localImages | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
        }
        else {
            Write-Host "✗ $image (not found locally)" -ForegroundColor Red
        }
    }
    Write-Host ""
}

if ($Hub -or $All) {
    Write-Host "`nDocker Hub URLs:" -ForegroundColor Cyan
    Write-Host "----------------`n" -ForegroundColor Cyan
    
    foreach ($image in $Images) {
        Write-Host "https://hub.docker.com/r/$DockerUsername/$image" -ForegroundColor Blue
    }
    Write-Host ""
}

Write-Host "`nCurrent Docker Build Processes:" -ForegroundColor Cyan
Write-Host "-------------------------------`n" -ForegroundColor Cyan

$buildProcesses = Get-Process -Name "docker" -ErrorAction SilentlyContinue
if ($buildProcesses) {
    Write-Host "✓ Docker processes running: $($buildProcesses.Count)" -ForegroundColor Green
}
else {
    Write-Host "○ No active Docker build processes" -ForegroundColor Yellow
}

Write-Host ""
