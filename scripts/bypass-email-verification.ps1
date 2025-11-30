# Bypass Email Verification Script (PowerShell)
# Usage: .\bypass-email-verification.ps1 [email|username:user|all]

param(
    [Parameter(Mandatory=$true)]
    [string]$Identifier
)

$DB_CONTAINER = if ($env:DB_CONTAINER) { $env:DB_CONTAINER } else { "traidnet-postgres" }
$DB_NAME = if ($env:DB_NAME) { $env:DB_NAME } else { "wifi_hotspot" }
$DB_USER = if ($env:DB_USER) { $env:DB_USER } else { "admin" }

Write-Host "==========================================" -ForegroundColor Blue
Write-Host "TraidNet - Email Verification Bypass" -ForegroundColor Blue
Write-Host "==========================================" -ForegroundColor Blue
Write-Host ""

try {
    docker --version | Out-Null
} catch {
    Write-Host "Error: Docker is not installed" -ForegroundColor Red
    exit 1
}

$containerRunning = docker ps --format "{{.Names}}" | Select-String -Pattern "^$DB_CONTAINER$"
if (-not $containerRunning) {
    Write-Host "Error: Container '$DB_CONTAINER' is not running" -ForegroundColor Red
    exit 1
}

function Invoke-SQL {
    param([string]$Query)
    $result = docker exec $DB_CONTAINER psql -U $DB_USER -d $DB_NAME -t -c "$Query" 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host "Error: $result" -ForegroundColor Red
        exit 1
    }
    return $result
}

function Set-EmailVerifiedByEmail {
    param([string]$Email)
    Write-Host "Checking user: $Email" -ForegroundColor Blue
    
    $userExists = Invoke-SQL "SELECT COUNT(*) FROM users WHERE email = '$Email';"
    if ($userExists.Trim() -eq "0") {
        Write-Host "User not found" -ForegroundColor Red
        exit 1
    }
    
    $isVerified = Invoke-SQL "SELECT email_verified_at IS NOT NULL FROM users WHERE email = '$Email';"
    if ($isVerified.Trim() -eq "t") {
        Write-Host "Already verified" -ForegroundColor Yellow
        exit 0
    }
    
    Invoke-SQL "UPDATE users SET email_verified_at = NOW() WHERE email = '$Email';" | Out-Null
    $userInfo = Invoke-SQL "SELECT name, username, email FROM users WHERE email = '$Email';"
    Write-Host "Verified: $userInfo" -ForegroundColor Green
}

function Set-EmailVerifiedByUsername {
    param([string]$Username)
    Write-Host "Checking user: $Username" -ForegroundColor Blue
    
    $userExists = Invoke-SQL "SELECT COUNT(*) FROM users WHERE username = '$Username';"
    if ($userExists.Trim() -eq "0") {
        Write-Host "User not found" -ForegroundColor Red
        exit 1
    }
    
    $isVerified = Invoke-SQL "SELECT email_verified_at IS NOT NULL FROM users WHERE username = '$Username';"
    if ($isVerified.Trim() -eq "t") {
        Write-Host "Already verified" -ForegroundColor Yellow
        exit 0
    }
    
    Invoke-SQL "UPDATE users SET email_verified_at = NOW() WHERE username = '$Username';" | Out-Null
    $userInfo = Invoke-SQL "SELECT name, username, email FROM users WHERE username = '$Username';"
    Write-Host "Verified: $userInfo" -ForegroundColor Green
}

function Set-AllEmailsVerified {
    Write-Host "Finding unverified users..." -ForegroundColor Blue
    
    $count = Invoke-SQL "SELECT COUNT(*) FROM users WHERE email_verified_at IS NULL;"
    $count = $count.Trim()
    
    if ($count -eq "0") {
        Write-Host "No unverified users" -ForegroundColor Yellow
        exit 0
    }
    
    Write-Host "Found $count unverified users" -ForegroundColor Yellow
    Invoke-SQL "SELECT id, name, username, email FROM users WHERE email_verified_at IS NULL;"
    
    $confirm = Read-Host "Verify all? (yes/no)"
    if ($confirm -ne "yes") {
        Write-Host "Cancelled" -ForegroundColor Yellow
        exit 0
    }
    
    Invoke-SQL "UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL;" | Out-Null
    Write-Host "All $count users verified!" -ForegroundColor Green
}

if ($Identifier -eq "all") {
    Set-AllEmailsVerified
}
elseif ($Identifier -like "username:*") {
    $username = $Identifier.Substring(9)
    Set-EmailVerifiedByUsername $username
}
else {
    Set-EmailVerifiedByEmail $Identifier
}

Write-Host ""
Write-Host "==========================================" -ForegroundColor Green
Write-Host "Complete!" -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor Green
