# PowerShell script to create RADIUS users for WiFi Hotspot authentication
# Usage: .\create-radius-user.ps1 -Username "john" -Password "secret123"

param(
    [Parameter(Mandatory=$true)]
    [string]$Username,
    
    [Parameter(Mandatory=$true)]
    [string]$Password
)

Write-Host "Creating RADIUS user: $Username" -ForegroundColor Cyan

# Check if user already exists
$checkQuery = "SELECT COUNT(*) FROM radcheck WHERE username='$Username';"
$existingUser = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $checkQuery

if ($existingUser.Trim() -gt 0) {
    Write-Host "Error: User '$Username' already exists!" -ForegroundColor Red
    Write-Host "To update password, use: .\update-radius-password.ps1 -Username '$Username' -Password 'newpassword'" -ForegroundColor Yellow
    exit 1
}

# Insert new user
$insertQuery = "INSERT INTO radcheck (username, attribute, op, value) VALUES ('$Username', 'Cleartext-Password', ':=', '$Password');"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c $insertQuery

if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ User '$Username' created successfully!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Login credentials:" -ForegroundColor Cyan
    Write-Host "  Username: $Username" -ForegroundColor White
    Write-Host "  Password: $Password" -ForegroundColor White
    Write-Host ""
    Write-Host "You can now login at: http://localhost/login" -ForegroundColor Cyan
} else {
    Write-Host "❌ Failed to create user!" -ForegroundColor Red
    exit 1
}
