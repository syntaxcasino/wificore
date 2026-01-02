# PowerShell script to list all RADIUS users
# Usage: .\list-radius-users.ps1

Write-Host "📋 RADIUS Users List" -ForegroundColor Cyan
Write-Host "===================" -ForegroundColor Cyan
Write-Host ""

$query = "SELECT id, username, attribute, value FROM radcheck ORDER BY id;"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c $query

Write-Host ""
Write-Host "Total users: " -NoNewline -ForegroundColor Cyan
$countQuery = "SELECT COUNT(*) FROM radcheck;"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $countQuery
