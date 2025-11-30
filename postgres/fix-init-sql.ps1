# PowerShell script to fix init.sql - Add tenant_id to all required tables

$initSqlPath = "init.sql"

# Backup original file
Copy-Item $initSqlPath "$initSqlPath.backup_$(Get-Date -Format 'yyyyMMdd_HHmmss')"

$content = Get-Content $initSqlPath -Raw

# Add tenant_id index to payments
$content = $content -replace '(CREATE INDEX idx_payments_status ON payments\(status\);)', "CREATE INDEX idx_payments_tenant_id ON payments(tenant_id);`r`n`$1"

# Fix vouchers table
$content = $content -replace '(CREATE TABLE vouchers \(\s+id UUID PRIMARY KEY DEFAULT gen_random_uuid\(\),)', "`$1`r`n    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,"

# Add vouchers tenant_id index
$content = $content -replace '(CREATE INDEX idx_vouchers_code ON vouchers\(code\);)', "CREATE INDEX idx_vouchers_tenant_id ON vouchers(tenant_id);`r`n`$1"

# Fix hotspot_users table
$content = $content -replace '(CREATE TABLE hotspot_users \(\s+id UUID PRIMARY KEY DEFAULT gen_random_uuid\(\),)', "`$1`r`n    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,"

# Add hotspot_users tenant_id index
$content = $content -replace '(CREATE INDEX idx_hotspot_users_username ON hotspot_users\(username\);)', "CREATE INDEX idx_hotspot_users_tenant_id ON hotspot_users(tenant_id);`r`n`$1"

# Fix user_sessions table
$content = $content -replace '(CREATE TABLE user_sessions \(\s+id UUID PRIMARY KEY DEFAULT gen_random_uuid\(\),)', "`$1`r`n    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,"

# Add user_sessions tenant_id index
$content = $content -replace '(CREATE INDEX idx_user_sessions_user_id ON user_sessions\(user_id\);)', "CREATE INDEX idx_user_sessions_tenant_id ON user_sessions(tenant_id);`r`n`$1"

# Fix hotspot_sessions table
$content = $content -replace '(CREATE TABLE hotspot_sessions \(\s+id UUID PRIMARY KEY DEFAULT gen_random_uuid\(\),)', "`$1`r`n    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,"

# Fix router_services table
$content = $content -replace '(CREATE TABLE router_services \(\s+id UUID PRIMARY KEY DEFAULT gen_random_uuid\(\),\s+router_id)', "CREATE TABLE router_services (`r`n    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),`r`n    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,`r`n    router_id"

# Add router_services tenant_id index
$content = $content -replace '(CREATE INDEX idx_router_services_router_id ON router_services\(router_id\);)', "CREATE INDEX idx_router_services_tenant_id ON router_services(tenant_id);`r`n`$1"

# Fix access_points table
$content = $content -replace '(CREATE TABLE access_points \(\s+id UUID PRIMARY KEY DEFAULT gen_random_uuid\(\),\s+router_id)', "CREATE TABLE access_points (`r`n    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),`r`n    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,`r`n    router_id"

# Add access_points tenant_id index
$content = $content -replace '(CREATE INDEX idx_access_points_router_id ON access_points\(router_id\);)', "CREATE INDEX idx_access_points_tenant_id ON access_points(tenant_id);`r`n`$1"

# Fix ap_active_sessions table
$content = $content -replace '(CREATE TABLE ap_active_sessions \(\s+id UUID PRIMARY KEY DEFAULT gen_random_uuid\(\),\s+access_point_id)', "CREATE TABLE ap_active_sessions (`r`n    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),`r`n    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,`r`n    access_point_id"

# Fix system_logs table
$content = $content -replace '(CREATE TABLE system_logs \(\s+id UUID PRIMARY KEY DEFAULT gen_random_uuid\(\),)', "`$1`r`n    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,"

# Fix router_vpn_configs table
$content = $content -replace '(CREATE TABLE router_vpn_configs \(\s+id UUID PRIMARY KEY DEFAULT gen_random_uuid\(\),\s+router_id)', "CREATE TABLE router_vpn_configs (`r`n    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),`r`n    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,`r`n    router_id"

# Save updated content
Set-Content -Path $initSqlPath -Value $content -NoNewline

Write-Host "✅ init.sql has been updated with tenant_id columns" -ForegroundColor Green
Write-Host "📁 Backup saved to: $initSqlPath.backup_$(Get-Date -Format 'yyyyMMdd_HHmmss')" -ForegroundColor Cyan
Write-Host ""
Write-Host "⚠️  Please review the changes and test with a fresh database!" -ForegroundColor Yellow
