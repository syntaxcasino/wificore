# PowerShell script to delete a RADIUS user
# Usage: .\delete-radius-user.ps1 -Username "john"

param(
    [Parameter(Mandatory=$true)]
    [string]$Username
)

Write-Host "Deleting RADIUS user: $Username" -ForegroundColor Yellow

# Check if user exists
$checkQuery = "SELECT COUNT(*) FROM radcheck WHERE username='$Username';"
$existingUser = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $checkQuery

if ($existingUser.Trim() -eq 0) {
    Write-Host "Error: User '$Username' does not exist!" -ForegroundColor Red
    exit 1
}

# Confirm deletion
$confirmation = Read-Host "Are you sure you want to delete user '$Username'? (yes/no)"
if ($confirmation -ne "yes") {
    Write-Host "Deletion cancelled." -ForegroundColor Yellow
    exit 0
}

# Delete user
$deleteQuery = "DELETE FROM radcheck WHERE username='$Username';"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c $deleteQuery

if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ User '$Username' deleted successfully!" -ForegroundColor Green
} else {
    Write-Host "❌ Failed to delete user!" -ForegroundColor Red
    exit 1
}
