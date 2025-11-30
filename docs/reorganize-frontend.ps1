# Frontend Reorganization Script
# This script reorganizes the frontend file structure

$ErrorActionPreference = "Stop"
$frontendPath = "d:\traidnet\wifi-hotspot\frontend\src"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Frontend Reorganization Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Function to create directory if it doesn't exist
function Ensure-Directory {
    param($path)
    if (-not (Test-Path $path)) {
        New-Item -ItemType Directory -Path $path -Force | Out-Null
        Write-Host "[CREATED] Directory: $path" -ForegroundColor Green
    }
}

# Function to move file safely
function Move-FileSafely {
    param($source, $destination)
    
    if (Test-Path $source) {
        $destDir = Split-Path $destination -Parent
        Ensure-Directory $destDir
        
        if (Test-Path $destination) {
            Write-Host "[SKIP] File already exists: $destination" -ForegroundColor Yellow
        } else {
            Move-Item -Path $source -Destination $destination -Force
            Write-Host "[MOVED] $source -> $destination" -ForegroundColor Green
        }
    } else {
        Write-Host "[NOT FOUND] $source" -ForegroundColor Red
    }
}

# Function to delete file safely
function Remove-FileSafely {
    param($path)
    
    if (Test-Path $path) {
        Remove-Item -Path $path -Force
        Write-Host "[DELETED] $path" -ForegroundColor Magenta
    } else {
        Write-Host "[NOT FOUND] $path" -ForegroundColor Yellow
    }
}

Write-Host "Step 1: Cleaning up duplicate files..." -ForegroundColor Cyan
Write-Host "----------------------------------------" -ForegroundColor Cyan

# Remove duplicate dashboard files
Remove-FileSafely "$frontendPath\views\DashboardNew.vue"
Remove-FileSafely "$frontendPath\views\DashboardOld.vue"

Write-Host ""
Write-Host "Step 2: Creating new directory structure..." -ForegroundColor Cyan
Write-Host "----------------------------------------" -ForegroundColor Cyan

# Create new directories
$newDirs = @(
    # Components
    "$frontendPath\components\common",
    "$frontendPath\components\dashboard\cards",
    "$frontendPath\components\dashboard\charts",
    "$frontendPath\components\dashboard\widgets",
    "$frontendPath\components\routers",
    "$frontendPath\components\routers\modals",
    
    # Composables
    "$frontendPath\composables\auth",
    "$frontendPath\composables\data",
    "$frontendPath\composables\utils",
    "$frontendPath\composables\websocket",
    
    # Views
    "$frontendPath\views\public",
    "$frontendPath\views\auth",
    "$frontendPath\views\test",
    "$frontendPath\views\dashboard\routers"
)

foreach ($dir in $newDirs) {
    Ensure-Directory $dir
}

Write-Host ""
Write-Host "Step 3: Reorganizing composables..." -ForegroundColor Cyan
Write-Host "----------------------------------------" -ForegroundColor Cyan

# Move composables to subdirectories
$composableMoves = @{
    "$frontendPath\composables\useAuth.js" = "$frontendPath\composables\auth\useAuth.js"
    "$frontendPath\composables\useDashboard.js" = "$frontendPath\composables\data\useDashboard.js"
    "$frontendPath\composables\useRouters.js" = "$frontendPath\composables\data\useRouters.js"
    "$frontendPath\composables\usePackages.js" = "$frontendPath\composables\data\usePackages.js"
    "$frontendPath\composables\usePayment.js" = "$frontendPath\composables\data\usePayments.js"
    "$frontendPath\composables\useLogs.js" = "$frontendPath\composables\data\useLogs.js"
    "$frontendPath\composables\useRouterUtils.js" = "$frontendPath\composables\utils\useRouterUtils.js"
    "$frontendPath\composables\useChartData.js" = "$frontendPath\composables\utils\useChartData.js"
    "$frontendPath\composables\useTheme.js" = "$frontendPath\composables\utils\useTheme.js"
    "$frontendPath\composables\useBroadcasting.js" = "$frontendPath\composables\websocket\useBroadcasting.js"
    "$frontendPath\composables\usePaymentWebSocket.js" = "$frontendPath\composables\websocket\usePaymentWebSocket.js"
    "$frontendPath\composables\useRouterProvisioning.js" = "$frontendPath\composables\websocket\useRouterProvisioning.js"
}

foreach ($move in $composableMoves.GetEnumerator()) {
    Move-FileSafely $move.Key $move.Value
}

# Delete duplicate composable
Remove-FileSafely "$frontendPath\composables\useDashboardData.js"

Write-Host ""
Write-Host "Step 4: Reorganizing components..." -ForegroundColor Cyan
Write-Host "----------------------------------------" -ForegroundColor Cyan

# Move common components
$componentMoves = @{
    "$frontendPath\components\ui\Button.vue" = "$frontendPath\components\common\Button.vue"
    "$frontendPath\components\ui\Modal.vue" = "$frontendPath\components\common\Modal.vue"
    "$frontendPath\components\ui\LoadingSpinner.vue" = "$frontendPath\components\common\LoadingSpinner.vue"
    "$frontendPath\components\ui\ErrorMessage.vue" = "$frontendPath\components\common\ErrorMessage.vue"
}

foreach ($move in $componentMoves.GetEnumerator()) {
    Move-FileSafely $move.Key $move.Value
}

# Move dashboard components
$dashboardComponentMoves = @{
    "$frontendPath\components\dashboard\StatsCard.vue" = "$frontendPath\components\dashboard\cards\StatsCard.vue"
    "$frontendPath\components\dashboard\ActiveUsersChart.vue" = "$frontendPath\components\dashboard\charts\ActiveUsersChart.vue"
    "$frontendPath\components\dashboard\PaymentsChart.vue" = "$frontendPath\components\dashboard\charts\PaymentsChart.vue"
    "$frontendPath\components\dashboard\RetentionRate.vue" = "$frontendPath\components\dashboard\charts\RetentionRate.vue"
    "$frontendPath\components\dashboard\DataUsage.vue" = "$frontendPath\components\dashboard\widgets\DataUsage.vue"
    "$frontendPath\components\dashboard\SessionLogs.vue" = "$frontendPath\components\dashboard\widgets\SessionLogs.vue"
    "$frontendPath\components\dashboard\SystemLogs.vue" = "$frontendPath\components\dashboard\widgets\SystemLogs.vue"
}

foreach ($move in $dashboardComponentMoves.GetEnumerator()) {
    Move-FileSafely $move.Key $move.Value
}

# Move router components
$routerComponentMoves = @{
    "$frontendPath\components\dashboard\routers\RouterList.vue" = "$frontendPath\components\routers\RouterList.vue"
    "$frontendPath\components\dashboard\routers\createOverlay.vue" = "$frontendPath\components\routers\modals\CreateRouterModal.vue"
    "$frontendPath\components\dashboard\routers\UpdateOverlay.vue" = "$frontendPath\components\routers\modals\UpdateRouterModal.vue"
    "$frontendPath\components\dashboard\routers\detailsOverlay.vue" = "$frontendPath\components\routers\modals\RouterDetailsModal.vue"
    "$frontendPath\components\dashboard\routers\RouterProvisioningOverlay.vue" = "$frontendPath\components\routers\modals\ProvisioningModal.vue"
}

foreach ($move in $routerComponentMoves.GetEnumerator()) {
    Move-FileSafely $move.Key $move.Value
}

Write-Host ""
Write-Host "Step 5: Reorganizing views..." -ForegroundColor Cyan
Write-Host "----------------------------------------" -ForegroundColor Cyan

# Move views
$viewMoves = @{
    "$frontendPath\views\LoginPage.vue" = "$frontendPath\views\auth\LoginView.vue"
    "$frontendPath\views\HomeView.vue" = "$frontendPath\views\public\HomeView.vue"
    "$frontendPath\views\AboutView.vue" = "$frontendPath\views\public\AboutView.vue"
    "$frontendPath\views\NotFound.vue" = "$frontendPath\views\public\NotFoundView.vue"
    "$frontendPath\views\WebSocketTest.vue" = "$frontendPath\views\test\WebSocketTestView.vue"
    "$frontendPath\views\PublicView.vue" = "$frontendPath\views\public\PublicView.vue"
}

foreach ($move in $viewMoves.GetEnumerator()) {
    Move-FileSafely $move.Key $move.Value
}

# Move router management to views
Move-FileSafely "$frontendPath\components\dashboard\RouterManagement.vue" "$frontendPath\views\dashboard\routers\RoutersView.vue"

Write-Host ""
Write-Host "Step 6: Creating index files for cleaner imports..." -ForegroundColor Cyan
Write-Host "----------------------------------------" -ForegroundColor Cyan

# Create index.js for composables/data
$dataIndexContent = @"
// Data composables barrel export
export { useDashboard } from './useDashboard'
export { useRouters } from './useRouters'
export { usePackages } from './usePackages'
export { usePayments } from './usePayments'
export { useLogs } from './useLogs'
"@

Set-Content -Path "$frontendPath\composables\data\index.js" -Value $dataIndexContent
Write-Host "[CREATED] composables/data/index.js" -ForegroundColor Green

# Create index.js for composables/utils
$utilsIndexContent = @"
// Utility composables barrel export
export { useRouterUtils } from './useRouterUtils'
export { useChartData } from './useChartData'
export { useTheme } from './useTheme'
"@

Set-Content -Path "$frontendPath\composables\utils\index.js" -Value $utilsIndexContent
Write-Host "[CREATED] composables/utils/index.js" -ForegroundColor Green

# Create index.js for composables/websocket
$websocketIndexContent = @"
// WebSocket composables barrel export
export { useBroadcasting } from './useBroadcasting'
export { usePaymentWebSocket } from './usePaymentWebSocket'
export { useRouterProvisioning } from './useRouterProvisioning'
"@

Set-Content -Path "$frontendPath\composables\websocket\index.js" -Value $websocketIndexContent
Write-Host "[CREATED] composables/websocket/index.js" -ForegroundColor Green

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Reorganization Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "NEXT STEPS:" -ForegroundColor Yellow
Write-Host "1. Update import paths in your components" -ForegroundColor White
Write-Host "2. Test the application: npm run dev" -ForegroundColor White
Write-Host "3. Fix any broken imports" -ForegroundColor White
Write-Host "4. Run build to verify: npm run build" -ForegroundColor White
Write-Host ""
Write-Host "IMPORT PATH CHANGES:" -ForegroundColor Yellow
Write-Host "OLD: import { useAuth } from '@/composables/useAuth'" -ForegroundColor Red
Write-Host "NEW: import { useAuth } from '@/composables/auth/useAuth'" -ForegroundColor Green
Write-Host ""
Write-Host "OLD: import { useDashboard } from '@/composables/useDashboard'" -ForegroundColor Red
Write-Host "NEW: import { useDashboard } from '@/composables/data/useDashboard'" -ForegroundColor Green
Write-Host ""
Write-Host "OR use barrel exports:" -ForegroundColor Yellow
Write-Host "NEW: import { useDashboard, useRouters } from '@/composables/data'" -ForegroundColor Green
Write-Host ""
