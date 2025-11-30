# Fix all import paths to use new module structure

Write-Host "Fixing import paths..." -ForegroundColor Green

$srcPath = "src"

# Find all .vue and .js files
$files = Get-ChildItem -Path $srcPath -Include *.vue,*.js -Recurse -File

foreach ($file in $files) {
    $content = Get-Content $file.FullName -Raw
    $originalContent = $content
    
    # Fix component imports
    $content = $content -replace "from '@/components/layout/", "from '@/modules/common/components/layout/"
    $content = $content -replace "from '@/components/base/", "from '@/modules/common/components/base/"
    $content = $content -replace "from '@/components/ui/", "from '@/modules/common/components/ui/"
    $content = $content -replace "from '@/components/auth/", "from '@/modules/common/components/auth/"
    $content = $content -replace "from '@/components/common/", "from '@/modules/common/components/common/"
    $content = $content -replace "from '@/components/icons/", "from '@/modules/common/components/icons/"
    $content = $content -replace "from '@/components/debug/", "from '@/modules/common/components/debug/"
    $content = $content -replace "from '@/components/AppHeader", "from '@/modules/common/components/AppHeader"
    
    # Fix tenant component imports
    $content = $content -replace "from '@/components/dashboard/", "from '@/modules/tenant/components/dashboard/"
    $content = $content -replace "from '@/components/routers/", "from '@/modules/tenant/components/routers/"
    $content = $content -replace "from '@/components/packages/", "from '@/modules/tenant/components/packages/"
    $content = $content -replace "from '@/components/payment/", "from '@/modules/tenant/components/payment/"
    $content = $content -replace "from '@/components/sessions/", "from '@/modules/tenant/components/sessions/"
    $content = $content -replace "from '@/components/users/", "from '@/modules/tenant/components/users/"
    $content = $content -replace "from '@/components/PackageSelector", "from '@/modules/tenant/components/PackageSelector"
    
    # Fix view imports
    $content = $content -replace "from '@/views/auth/", "from '@/modules/common/views/auth/"
    $content = $content -replace "from '@/views/public/", "from '@/modules/common/views/public/"
    $content = $content -replace "from '@/views/test/", "from '@/modules/common/views/test/"
    $content = $content -replace "from '@/views/system/", "from '@/modules/system-admin/views/system/"
    $content = $content -replace "from '@/views/dashboard/", "from '@/modules/tenant/views/dashboard/"
    $content = $content -replace "from '@/views/protected/", "from '@/modules/tenant/views/protected/"
    $content = $content -replace "from '@/views/Dashboard", "from '@/modules/tenant/views/Dashboard"
    $content = $content -replace "from '@/views/DashboardView", "from '@/modules/tenant/views/Dashboard"
    
    # Fix composable imports
    $content = $content -replace "from '@/composables/auth/", "from '@/modules/common/composables/auth/"
    $content = $content -replace "from '@/composables/utils/", "from '@/modules/common/composables/utils/"
    $content = $content -replace "from '@/composables/websocket/", "from '@/modules/common/composables/websocket/"
    $content = $content -replace "from '@/composables/data/", "from '@/modules/tenant/composables/data/"
    $content = $content -replace "from '@/composables/useRouterProvisioning", "from '@/modules/tenant/composables/useRouterProvisioning"
    
    # Fix dynamic imports
    $content = $content -replace "import\('@/views/auth/", "import('@/modules/common/views/auth/"
    $content = $content -replace "import\('@/views/public/", "import('@/modules/common/views/public/"
    $content = $content -replace "import\('@/views/test/", "import('@/modules/common/views/test/"
    $content = $content -replace "import\('@/views/system/", "import('@/modules/system-admin/views/system/"
    $content = $content -replace "import\('@/views/dashboard/", "import('@/modules/tenant/views/dashboard/"
    $content = $content -replace "import\('@/views/protected/", "import('@/modules/tenant/views/protected/"
    
    # Only write if content changed
    if ($content -ne $originalContent) {
        Set-Content -Path $file.FullName -Value $content -NoNewline
        Write-Host "Fixed: $($file.FullName)" -ForegroundColor Yellow
    }
}

Write-Host "Import paths fixed!" -ForegroundColor Green
