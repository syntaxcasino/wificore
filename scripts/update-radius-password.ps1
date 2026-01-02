# PowerShell script to update RADIUS user password
# Usage: .\update-radius-password.ps1 -Username "admin" -Password "newpassword123"

param(
    [Parameter(Mandatory=$true)]
    [string]$Username,
    
    [Parameter(Mandatory=$true)]
    [string]$Password
)

Write-Host "Updating password for RADIUS user: $Username" -ForegroundColor Cyan

# Check if user exists
$checkQuery = "SELECT COUNT(*) FROM radcheck WHERE username='$Username';"
$existingUser = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $checkQuery

if ($existingUser.Trim() -eq 0) {
    Write-Host "Error: User '$Username' does not exist!" -ForegroundColor Red
    Write-Host "To create a new user, use: .\create-radius-user.ps1 -Username '$Username' -Password '$Password'" -ForegroundColor Yellow
    exit 1
}

# Update password
$updateQuery = "UPDATE radcheck SET value='$Password' WHERE username='$Username' AND attribute='Cleartext-Password';"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c $updateQuery

if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Password updated successfully for user '$Username'!" -ForegroundColor Green
    Write-Host ""
    Write-Host "New credentials:" -ForegroundColor Cyan
    Write-Host "  Username: $Username" -ForegroundColor White
    Write-Host "  Password: $Password" -ForegroundColor White
} else {
    Write-Host "❌ Failed to update password!" -ForegroundColor Red
    exit 1
}
