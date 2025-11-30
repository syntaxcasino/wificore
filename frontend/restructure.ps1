# Frontend Restructuring Script
# This script reorganizes the frontend into common, system-admin, and tenant modules

Write-Host "Starting frontend restructuring..." -ForegroundColor Green

$srcPath = "src"

# Create new directory structure
Write-Host "Creating new directory structure..." -ForegroundColor Yellow

# Common module (shared across all user types)
New-Item -ItemType Directory -Force -Path "$srcPath/modules/common/components" | Out-Null
New-Item -ItemType Directory -Force -Path "$srcPath/modules/common/composables" | Out-Null
New-Item -ItemType Directory -Force -Path "$srcPath/modules/common/views" | Out-Null
New-Item -ItemType Directory -Force -Path "$srcPath/modules/common/stores" | Out-Null

# System Admin module
New-Item -ItemType Directory -Force -Path "$srcPath/modules/system-admin/components" | Out-Null
New-Item -ItemType Directory -Force -Path "$srcPath/modules/system-admin/composables" | Out-Null
New-Item -ItemType Directory -Force -Path "$srcPath/modules/system-admin/views" | Out-Null
New-Item -ItemType Directory -Force -Path "$srcPath/modules/system-admin/stores" | Out-Null

# Tenant module
New-Item -ItemType Directory -Force -Path "$srcPath/modules/tenant/components" | Out-Null
New-Item -ItemType Directory -Force -Path "$srcPath/modules/tenant/composables" | Out-Null
New-Item -ItemType Directory -Force -Path "$srcPath/modules/tenant/views" | Out-Null
New-Item -ItemType Directory -Force -Path "$srcPath/modules/tenant/stores" | Out-Null

Write-Host "Directory structure created successfully!" -ForegroundColor Green

# Move common components (layout, base UI, auth)
Write-Host "Moving common components..." -ForegroundColor Yellow
Move-Item -Path "$srcPath/components/layout" -Destination "$srcPath/modules/common/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/base" -Destination "$srcPath/modules/common/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/ui" -Destination "$srcPath/modules/common/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/auth" -Destination "$srcPath/modules/common/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/common" -Destination "$srcPath/modules/common/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/icons" -Destination "$srcPath/modules/common/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/debug" -Destination "$srcPath/modules/common/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/AppHeader.vue" -Destination "$srcPath/modules/common/components/" -Force -ErrorAction SilentlyContinue

# Move tenant components
Write-Host "Moving tenant components..." -ForegroundColor Yellow
Move-Item -Path "$srcPath/components/dashboard" -Destination "$srcPath/modules/tenant/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/routers" -Destination "$srcPath/modules/tenant/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/packages" -Destination "$srcPath/modules/tenant/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/payment" -Destination "$srcPath/modules/tenant/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/sessions" -Destination "$srcPath/modules/tenant/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/users" -Destination "$srcPath/modules/tenant/components/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/components/PackageSelector.vue" -Destination "$srcPath/modules/tenant/components/" -Force -ErrorAction SilentlyContinue

# Move common views
Write-Host "Moving common views..." -ForegroundColor Yellow
Move-Item -Path "$srcPath/views/auth" -Destination "$srcPath/modules/common/views/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/views/public" -Destination "$srcPath/modules/common/views/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/views/test" -Destination "$srcPath/modules/common/views/" -Force -ErrorAction SilentlyContinue

# Move system admin views
Write-Host "Moving system admin views..." -ForegroundColor Yellow
Move-Item -Path "$srcPath/views/system" -Destination "$srcPath/modules/system-admin/views/" -Force -ErrorAction SilentlyContinue

# Move tenant views
Write-Host "Moving tenant views..." -ForegroundColor Yellow
Move-Item -Path "$srcPath/views/dashboard" -Destination "$srcPath/modules/tenant/views/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/views/protected" -Destination "$srcPath/modules/tenant/views/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/views/Dashboard.vue" -Destination "$srcPath/modules/tenant/views/" -Force -ErrorAction SilentlyContinue

# Move common composables
Write-Host "Moving common composables..." -ForegroundColor Yellow
Move-Item -Path "$srcPath/composables/auth" -Destination "$srcPath/modules/common/composables/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/composables/utils" -Destination "$srcPath/modules/common/composables/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/composables/websocket" -Destination "$srcPath/modules/common/composables/" -Force -ErrorAction SilentlyContinue

# Move tenant composables
Write-Host "Moving tenant composables..." -ForegroundColor Yellow
Move-Item -Path "$srcPath/composables/data" -Destination "$srcPath/modules/tenant/composables/" -Force -ErrorAction SilentlyContinue
Move-Item -Path "$srcPath/composables/useRouterProvisioning.js" -Destination "$srcPath/modules/tenant/composables/" -Force -ErrorAction SilentlyContinue

Write-Host "Frontend restructuring completed!" -ForegroundColor Green
Write-Host "Please update import paths in your components." -ForegroundColor Yellow
