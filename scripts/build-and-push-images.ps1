################################################################################
# Build and Push Docker Images to Docker Hub (PowerShell)
# 
# This script builds all Docker images for the TraidNet WiFi Hotspot system
# and pushes them to Docker Hub with proper tagging.
#
# Usage:
#   .\build-and-push-images.ps1 [-Tag VERSION] [-NoCache] [-PushOnly] [-BuildOnly] [-Service SERVICE]
#
# Parameters:
#   -Tag VERSION       Specify version tag (default: latest)
#   -NoCache           Build without cache
#   -PushOnly          Skip build, only push existing images
#   -BuildOnly         Only build, don't push
#   -Service SERVICE   Build/push only specific service
#   -Help              Show help message
#
# Examples:
#   .\build-and-push-images.ps1
#   .\build-and-push-images.ps1 -Tag "v1.0.0"
#   .\build-and-push-images.ps1 -Service backend -Tag "v1.0.0"
#   .\build-and-push-images.ps1 -NoCache -Tag latest
################################################################################

param(
    [string]$Tag = "latest",
    [switch]$NoCache,
    [switch]$PushOnly,
    [switch]$BuildOnly,
    [string]$Service = "",
    [switch]$Help
)

# Configuration
$DockerUsername = "kja2aro"

# Image definitions (matching docker-compose-deployment.yml)
$Images = @{
    "nginx"      = "traidnet-nginx"
    "frontend"   = "traidnet-frontend"
    "backend"    = "traidnet-backend"
    "soketi"     = "traidnet-soketi"
    "freeradius" = "traidnet-freeradius"
}

$BuildContexts = @{
    "nginx"      = "../nginx"
    "frontend"   = "../frontend"
    "backend"    = "../backend"
    "soketi"     = "../soketi"
    "freeradius" = "../freeradius"
}

################################################################################
# Helper Functions
################################################################################

function Write-Header {
    param([string]$Message)
    Write-Host "`n================================" -ForegroundColor Blue
    Write-Host $Message -ForegroundColor Blue
    Write-Host "================================`n" -ForegroundColor Blue
}

function Write-Success {
    param([string]$Message)
    Write-Host "✓ $Message" -ForegroundColor Green
}

function Write-ErrorMsg {
    param([string]$Message)
    Write-Host "✗ $Message" -ForegroundColor Red
}

function Write-Warning {
    param([string]$Message)
    Write-Host "⚠ $Message" -ForegroundColor Yellow
}

function Write-Info {
    param([string]$Message)
    Write-Host "ℹ $Message" -ForegroundColor Cyan
}

function Show-Help {
    Write-Host @"
Build and Push Docker Images to Docker Hub

Usage:
  .\build-and-push-images.ps1 [-Tag VERSION] [-NoCache] [-PushOnly] [-BuildOnly] [-Service SERVICE]

Parameters:
  -Tag VERSION       Specify version tag (default: latest)
  -NoCache           Build without cache
  -PushOnly          Skip build, only push existing images
  -BuildOnly         Only build, don't push
  -Service SERVICE   Build/push only specific service
  -Help              Show this help message

Available Services:
  nginx, frontend, backend, soketi, freeradius

Examples:
  .\build-and-push-images.ps1
  .\build-and-push-images.ps1 -Tag "v1.0.0"
  .\build-and-push-images.ps1 -Service backend -Tag "v1.0.0"
  .\build-and-push-images.ps1 -NoCache -Tag latest

"@
}

function Test-Docker {
    try {
        $null = docker --version
        Write-Success "Docker is available"
        return $true
    }
    catch {
        Write-ErrorMsg "Docker is not installed or not in PATH"
        return $false
    }
}

function Test-DockerLogin {
    Write-Info "Checking Docker Hub authentication..."
    
    try {
        $dockerInfo = docker info 2>&1 | Out-String
        if ($dockerInfo -match "Username: $DockerUsername") {
            Write-Success "Authenticated with Docker Hub as $DockerUsername"
            return $true
        }
        else {
            Write-Warning "Not logged in to Docker Hub"
            Write-Info "Attempting to log in..."
            docker login
            if ($LASTEXITCODE -eq 0) {
                Write-Success "Successfully logged in to Docker Hub"
                return $true
            }
            else {
                Write-ErrorMsg "Docker login failed"
                return $false
            }
        }
    }
    catch {
        Write-ErrorMsg "Failed to check Docker authentication"
        return $false
    }
}

function Build-DockerImage {
    param(
        [string]$ServiceName,
        [string]$ImageName,
        [string]$Context
    )
    
    $FullImage = "$DockerUsername/${ImageName}:${Tag}"
    
    Write-Header "Building: $ServiceName"
    Write-Info "Image: $FullImage"
    Write-Info "Context: $Context"
    
    if (-not (Test-Path $Context)) {
        Write-ErrorMsg "Build context directory not found: $Context"
        return $false
    }
    
    $BuildArgs = @("build")
    if ($NoCache) {
        $BuildArgs += "--no-cache"
    }
    $BuildArgs += "-t", $FullImage
    
    # Add latest tag if not already latest
    if ($Tag -ne "latest") {
        $BuildArgs += "-t", "$DockerUsername/${ImageName}:latest"
    }
    
    $BuildArgs += $Context
    
    Write-Info "Running: docker $($BuildArgs -join ' ')"
    
    & docker @BuildArgs
    
    if ($LASTEXITCODE -eq 0) {
        Write-Success "Built: $FullImage"
        return $true
    }
    else {
        Write-ErrorMsg "Failed to build: $FullImage"
        return $false
    }
}

function Push-DockerImage {
    param(
        [string]$ServiceName,
        [string]$ImageName
    )
    
    $FullImage = "$DockerUsername/${ImageName}:${Tag}"
    
    Write-Header "Pushing: $ServiceName"
    Write-Info "Image: $FullImage"
    
    docker push $FullImage
    
    if ($LASTEXITCODE -eq 0) {
        Write-Success "Pushed: $FullImage"
        
        # Push latest tag if we're tagging with version
        if ($Tag -ne "latest") {
            $LatestImage = "$DockerUsername/${ImageName}:latest"
            Write-Info "Also pushing: $LatestImage"
            docker push $LatestImage
            if ($LASTEXITCODE -eq 0) {
                Write-Success "Pushed: $LatestImage"
            }
            else {
                Write-Warning "Failed to push latest tag"
            }
        }
        return $true
    }
    else {
        Write-ErrorMsg "Failed to push: $FullImage"
        return $false
    }
}

################################################################################
# Main Script
################################################################################

# Show help if requested
if ($Help) {
    Show-Help
    exit 0
}

Write-Header "TraidNet Docker Image Builder"
Write-Info "Docker Username: $DockerUsername"
Write-Info "Tag: $Tag"
Write-Info "No Cache: $NoCache"
Write-Info "Push Only: $PushOnly"
Write-Info "Build Only: $BuildOnly"
if ($Service) {
    Write-Info "Specific Service: $Service"
}

# Check prerequisites
if (-not (Test-Docker)) {
    exit 1
}

if (-not $PushOnly) {
    Write-Info "Build mode enabled"
}

if (-not $BuildOnly) {
    if (-not (Test-DockerLogin)) {
        exit 1
    }
}

# Determine which services to process
$ServicesToProcess = @()
if ($Service) {
    if ($Images.ContainsKey($Service)) {
        $ServicesToProcess = @($Service)
    }
    else {
        Write-ErrorMsg "Unknown service: $Service"
        Write-Info "Available services: $($Images.Keys -join ', ')"
        exit 1
    }
}
else {
    $ServicesToProcess = $Images.Keys
}

# Track results
$BuildResults = @{}
$PushResults = @{}
$SuccessfulBuilds = 0
$FailedBuilds = 0
$SuccessfulPushes = 0
$FailedPushes = 0

Write-Header "Processing $($ServicesToProcess.Count) service(s)"

# Process each service
foreach ($ServiceName in $ServicesToProcess) {
    $ImageName = $Images[$ServiceName]
    $Context = $BuildContexts[$ServiceName]
    
    # Build phase
    if (-not $PushOnly) {
        if (Build-DockerImage -ServiceName $ServiceName -ImageName $ImageName -Context $Context) {
            $BuildResults[$ServiceName] = "success"
            $SuccessfulBuilds++
        }
        else {
            $BuildResults[$ServiceName] = "failed"
            $FailedBuilds++
            Write-Warning "Skipping push for $ServiceName due to build failure"
            continue
        }
        Write-Host ""
    }
    
    # Push phase
    if (-not $BuildOnly) {
        if (Push-DockerImage -ServiceName $ServiceName -ImageName $ImageName) {
            $PushResults[$ServiceName] = "success"
            $SuccessfulPushes++
        }
        else {
            $PushResults[$ServiceName] = "failed"
            $FailedPushes++
        }
        Write-Host ""
    }
}

################################################################################
# Summary
################################################################################

Write-Header "Summary"

if (-not $PushOnly) {
    Write-Info "Build Results:"
    foreach ($ServiceName in $ServicesToProcess) {
        if ($BuildResults[$ServiceName] -eq "success") {
            Write-Success "  $ServiceName`: Built successfully"
        }
        else {
            Write-ErrorMsg "  $ServiceName`: Build failed"
        }
    }
    Write-Host ""
    Write-Info "Build Statistics:"
    Write-Host "  Total: $($ServicesToProcess.Count)"
    Write-Host "  Successful: $SuccessfulBuilds"
    Write-Host "  Failed: $FailedBuilds"
    Write-Host ""
}

if (-not $BuildOnly) {
    Write-Info "Push Results:"
    foreach ($ServiceName in $ServicesToProcess) {
        if ($PushResults[$ServiceName] -eq "success") {
            Write-Success "  $ServiceName`: Pushed successfully"
        }
        elseif ($PushResults[$ServiceName] -eq "failed") {
            Write-ErrorMsg "  $ServiceName`: Push failed"
        }
    }
    Write-Host ""
    Write-Info "Push Statistics:"
    Write-Host "  Total: $($PushResults.Count)"
    Write-Host "  Successful: $SuccessfulPushes"
    Write-Host "  Failed: $FailedPushes"
    Write-Host ""
}

# Print Docker Hub URLs
if (-not $BuildOnly -and $SuccessfulPushes -gt 0) {
    Write-Header "Docker Hub Images"
    foreach ($ServiceName in $ServicesToProcess) {
        if ($PushResults[$ServiceName] -eq "success") {
            $ImageName = $Images[$ServiceName]
            Write-Host "  https://hub.docker.com/r/$DockerUsername/$ImageName"
        }
    }
    Write-Host ""
}

# Exit with appropriate code
if (-not $PushOnly -and $FailedBuilds -gt 0) {
    Write-ErrorMsg "Some builds failed!"
    exit 1
}
elseif (-not $BuildOnly -and $FailedPushes -gt 0) {
    Write-ErrorMsg "Some pushes failed!"
    exit 1
}
else {
    Write-Success "All operations completed successfully!"
    exit 0
}
