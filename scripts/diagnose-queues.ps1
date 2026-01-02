###############################################################################
# WiFi Hotspot Queue Diagnostic Script (PowerShell)
# 
# This script performs comprehensive diagnostics on the Laravel queue system
# including worker status, job counts, failed jobs, and log analysis.
#
# Usage: .\diagnose-queues.ps1 [-Detailed] [-ShowLogs] [-FixFailed]
# Parameters:
#   -Detailed    Show detailed job information
#   -ShowLogs    Show recent log entries
#   -FixFailed   Attempt to fix common issues
###############################################################################

param(
    [switch]$Detailed,
    [switch]$ShowLogs,
    [switch]$FixFailed
)

# Configuration
$ContainerName = "traidnet-backend"
$DbContainer = "traidnet-postgres"
$DbUser = "admin"
$DbName = "wifi_hotspot"

# Color functions
function Write-Header {
    param([string]$Message)
    Write-Host ""
    Write-Host "╔════════════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
    Write-Host "║  $Message" -ForegroundColor Cyan
    Write-Host "╚════════════════════════════════════════════════════════════════╝" -ForegroundColor Cyan
    Write-Host ""
}

function Write-Section {
    param([string]$Message)
    Write-Host ""
    Write-Host "═══ $Message ═══" -ForegroundColor Blue
    Write-Host ""
}

function Write-Success {
    param([string]$Message)
    Write-Host "✓ $Message" -ForegroundColor Green
}

function Write-Error-Custom {
    param([string]$Message)
    Write-Host "✗ $Message" -ForegroundColor Red
}

function Write-Warning-Custom {
    param([string]$Message)
    Write-Host "⚠ $Message" -ForegroundColor Yellow
}

function Write-Info {
    param([string]$Message)
    Write-Host "ℹ $Message" -ForegroundColor Cyan
}

function Test-Container {
    param([string]$Name)
    $result = docker ps --format "{{.Names}}" | Select-String -Pattern "^$Name$"
    return $null -ne $result
}

###############################################################################
# Main Diagnostic Functions
###############################################################################

function Test-Prerequisites {
    Write-Section "Checking Prerequisites"
    
    # Check Docker
    try {
        docker --version | Out-Null
        Write-Success "Docker is installed"
    }
    catch {
        Write-Error-Custom "Docker is not installed or not in PATH"
        exit 1
    }
    
    # Check containers
    if (Test-Container $ContainerName) {
        Write-Success "Backend container is running"
    }
    else {
        Write-Error-Custom "Backend container is not running"
        exit 1
    }
    
    if (Test-Container $DbContainer) {
        Write-Success "Database container is running"
    }
    else {
        Write-Error-Custom "Database container is not running"
        exit 1
    }
}

function Get-SupervisorStatus {
    Write-Section "Supervisor & Queue Workers Status"
    
    Write-Host "Supervisor Status:" -ForegroundColor White
    $status = docker exec $ContainerName supervisorctl status
    
    foreach ($line in $status) {
        if ($line -match "RUNNING") {
            Write-Host "  ● $line" -ForegroundColor Green
        }
        elseif ($line -match "STOPPED") {
            Write-Host "  ● $line" -ForegroundColor Red
        }
        elseif ($line -match "FATAL") {
            Write-Host "  ✗ $line" -ForegroundColor Red
        }
        else {
            Write-Host "  ● $line" -ForegroundColor Yellow
        }
    }
    
    Write-Host ""
    $workerCount = ($status | Select-String "RUNNING").Count
    Write-Info "Total running workers: $workerCount"
}

function Get-QueueSizes {
    Write-Section "Queue Sizes & Status"
    
    $query = @"
SELECT 
    queue,
    COUNT(*) as pending,
    COUNT(CASE WHEN reserved_at IS NOT NULL THEN 1 END) as reserved,
    COUNT(CASE WHEN reserved_at IS NULL THEN 1 END) as available
FROM jobs 
GROUP BY queue
ORDER BY pending DESC;
"@
    
    $result = docker exec $DbContainer psql -U $DbUser -d $DbName -t -c $query
    
    if ([string]::IsNullOrWhiteSpace($result)) {
        Write-Success "All queues are empty (no pending jobs)"
    }
    else {
        Write-Host "Queue Name              Pending  Reserved  Available" -ForegroundColor White
        Write-Host "────────────────────────────────────────────────────"
        
        foreach ($line in $result) {
            if (![string]::IsNullOrWhiteSpace($line)) {
                $parts = $line -split [regex]::Escape('|')
                $queue = $parts[0].Trim()
                $pending = [int]$parts[1].Trim()
                $reserved = $parts[2].Trim()
                $available = $parts[3].Trim()
                
                $queueFormatted = $queue.PadRight(25)
                $pendingFormatted = $pending.ToString().PadLeft(8)
                $reservedFormatted = $reserved.PadLeft(10)
                $availableFormatted = $available.PadLeft(11)
                
                if ($pending -gt 100) {
                    Write-Host "$queueFormatted$pendingFormatted$reservedFormatted$availableFormatted" -ForegroundColor Red
                }
                elseif ($pending -gt 10) {
                    Write-Host "$queueFormatted$pendingFormatted$reservedFormatted$availableFormatted" -ForegroundColor Yellow
                }
                else {
                    Write-Host "$queueFormatted$pendingFormatted$reservedFormatted$availableFormatted"
                }
            }
        }
    }
}

function Get-FailedJobs {
    Write-Section "Failed Jobs Analysis"
    
    $countQuery = "SELECT COUNT(*) FROM failed_jobs;"
    $totalFailed = docker exec $DbContainer psql -U $DbUser -d $DbName -t -c $countQuery
    $totalFailed = [int]$totalFailed.Trim()
    
    if ($totalFailed -eq 0) {
        Write-Success "No failed jobs found"
    }
    else {
        Write-Warning-Custom "Total failed jobs: $totalFailed"
        Write-Host ""
        
        $statsQuery = @"
SELECT 
    queue,
    COUNT(*) as count,
    MAX(failed_at) as last_failure
FROM failed_jobs 
GROUP BY queue
ORDER BY count DESC;
"@
        
        $stats = docker exec $DbContainer psql -U $DbUser -d $DbName -t -c $statsQuery
        
        Write-Host "Queue Name              Failed Count  Last Failure" -ForegroundColor White
        Write-Host "────────────────────────────────────────────────────────────────"
        
        foreach ($line in $stats) {
            if (![string]::IsNullOrWhiteSpace($line)) {
                $parts = $line -split [regex]::Escape('|')
                $queue = $parts[0].Trim()
                $count = [int]$parts[1].Trim()
                $lastFailure = $parts[2].Trim()
                
                $queueFormatted = $queue.PadRight(25)
                $countFormatted = $count.ToString().PadLeft(13)
                
                if ($count -gt 100) {
                    Write-Host "$queueFormatted$countFormatted  $lastFailure" -ForegroundColor Red
                }
                elseif ($count -gt 10) {
                    Write-Host "$queueFormatted$countFormatted  $lastFailure" -ForegroundColor Yellow
                }
                else {
                    Write-Host "$queueFormatted$countFormatted  $lastFailure"
                }
            }
        }
    }
    
    if ($Detailed -and $totalFailed -gt 0) {
        Write-Host ""
        Write-Info "Recent failed jobs (last 5):"
        $recentQuery = @"
SELECT 
    LEFT(uuid, 8) as id,
    queue,
    LEFT(connection, 15) as conn,
    failed_at
FROM failed_jobs 
ORDER BY failed_at DESC 
LIMIT 5;
"@
        docker exec $DbContainer psql -U $DbUser -d $DbName -c $recentQuery
    }
}

function Get-JobThroughput {
    Write-Section "Job Processing Metrics"
    
    Write-Host "Recent Job Activity (last hour):" -ForegroundColor White
    
    $recentQuery = "SELECT COUNT(*) FROM failed_jobs WHERE failed_at > NOW() - INTERVAL '1 hour';"
    $recentJobs = docker exec $DbContainer psql -U $DbUser -d $DbName -t -c $recentQuery
    $recentJobs = [int]$recentJobs.Trim()
    
    Write-Host "  Failed in last hour: $recentJobs"
    
    $stuckQuery = @"
SELECT COUNT(*) 
FROM jobs 
WHERE reserved_at IS NOT NULL 
AND reserved_at < EXTRACT(EPOCH FROM NOW() - INTERVAL '10 minutes');
"@
    $stuckJobs = docker exec $DbContainer psql -U $DbUser -d $DbName -t -c $stuckQuery
    $stuckJobs = [int]$stuckJobs.Trim()
    
    if ($stuckJobs -gt 0) {
        Write-Warning-Custom "Potentially stuck jobs: $stuckJobs"
    }
    else {
        Write-Success "No stuck jobs detected"
    }
}

function Get-QueueLogs {
    Write-Section "Queue Log Analysis"
    
    $logPath = "/var/www/html/storage/logs"
    $queueLogs = @(
        "default-queue.log",
        "payments-queue.log",
        "provisioning-queue.log",
        "router-checks-queue.log",
        "router-data-queue.log",
        "hotspot-sms-queue.log",
        "hotspot-sessions-queue.log",
        "hotspot-accounting-queue.log",
        "dashboard-queue.log",
        "log-rotation-queue.log"
    )
    
    Write-Host "Log File Status:" -ForegroundColor White
    foreach ($log in $queueLogs) {
        try {
            $size = docker exec $ContainerName stat -c%s "$logPath/$log" 2>$null
            if ($null -eq $size) { $size = 0 }
            $sizeMB = [math]::Round($size / 1MB, 2)
            
            if ($size -eq 0) {
                Write-Host "  ○ $log - Empty" -ForegroundColor Yellow
            }
            elseif ($sizeMB -gt 5) {
                Write-Host "  ● $log - $sizeMB MB" -ForegroundColor Red
            }
            else {
                Write-Host "  ● $log - $sizeMB MB" -ForegroundColor Green
            }
        }
        catch {
            Write-Host "  ○ $log - Not found" -ForegroundColor Yellow
        }
    }
    
    if ($ShowLogs) {
        Write-Host ""
        Write-Info "Recent errors from laravel.log:"
        $errors = docker exec $ContainerName tail -n 20 "$logPath/laravel.log" | Select-String "error"
        if ($errors) {
            $errors | ForEach-Object { Write-Host "  $_" }
        }
        else {
            Write-Host "  No recent errors"
        }
    }
}

function Test-DatabaseHealth {
    Write-Section "Database Health Check"
    
    $isReady = docker exec $DbContainer pg_isready -U $DbUser -d $DbName 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Success "Database connection is healthy"
    }
    else {
        Write-Error-Custom "Database connection failed"
        return
    }
    
    Write-Host ""
    Write-Host "Queue Table Statistics:" -ForegroundColor White
    $statsQuery = @"
SELECT 
    'jobs' as table_name,
    pg_size_pretty(pg_total_relation_size('jobs')) as size,
    (SELECT COUNT(*) FROM jobs) as row_count
UNION ALL
SELECT 
    'failed_jobs' as table_name,
    pg_size_pretty(pg_total_relation_size('failed_jobs')) as size,
    (SELECT COUNT(*) FROM failed_jobs) as row_count;
"@
    docker exec $DbContainer psql -U $DbUser -d $DbName -c $statsQuery
}

function Find-CommonIssues {
    Write-Section "Common Issues Detection"
    
    $issuesFound = 0
    
    # Check for RotateLogs failures
    $rotateQuery = "SELECT COUNT(*) FROM failed_jobs WHERE queue = 'log-rotation';"
    $rotateFailures = docker exec $DbContainer psql -U $DbUser -d $DbName -t -c $rotateQuery
    $rotateFailures = [int]$rotateFailures.Trim()
    
    if ($rotateFailures -gt 0) {
        Write-Warning-Custom "RotateLogs job failures detected: $rotateFailures"
        Write-Info "  Issue: Permission errors with chown/chgrp operations"
        Write-Info "  Impact: Low - Log rotation not critical for queue operation"
        $issuesFound++
    }
    
    # Check for payment processing issues
    $paymentQuery = "SELECT COUNT(*) FROM failed_jobs WHERE queue = 'payments' AND failed_at > NOW() - INTERVAL '1 hour';"
    $paymentFailures = docker exec $DbContainer psql -U $DbUser -d $DbName -t -c $paymentQuery
    $paymentFailures = [int]$paymentFailures.Trim()
    
    if ($paymentFailures -gt 0) {
        Write-Error-Custom "Recent payment processing failures: $paymentFailures"
        Write-Info "  Impact: HIGH - Affects customer payments"
        $issuesFound++
    }
    
    # Check for provisioning issues
    $provisionQuery = "SELECT COUNT(*) FROM failed_jobs WHERE queue = 'provisioning' AND failed_at > NOW() - INTERVAL '1 hour';"
    $provisionFailures = docker exec $DbContainer psql -U $DbUser -d $DbName -t -c $provisionQuery
    $provisionFailures = [int]$provisionFailures.Trim()
    
    if ($provisionFailures -gt 0) {
        Write-Error-Custom "Recent provisioning failures: $provisionFailures"
        Write-Info "  Impact: HIGH - Affects user activation"
        $issuesFound++
    }
    
    if ($issuesFound -eq 0) {
        Write-Success "No critical issues detected"
    }
}

function Show-Recommendations {
    Write-Section "Recommendations"
    
    $countQuery = "SELECT COUNT(*) FROM failed_jobs;"
    $totalFailed = docker exec $DbContainer psql -U $DbUser -d $DbName -t -c $countQuery
    $totalFailed = [int]$totalFailed.Trim()
    
    if ($totalFailed -gt 100) {
        Write-Host "1. Clear old failed jobs:"
        Write-Host "   docker exec $ContainerName php artisan queue:flush"
        Write-Host ""
    }
    
    Write-Host "2. Restart queue workers:"
    Write-Host "   docker exec $ContainerName supervisorctl restart laravel-queues:*"
    Write-Host ""
    
    Write-Host "3. Monitor queue in real-time:"
    Write-Host "   docker exec $ContainerName php artisan queue:monitor database:default,database:payments,database:provisioning --max=100"
    Write-Host ""
    
    Write-Host "4. View specific queue logs:"
    Write-Host "   docker exec $ContainerName tail -f /var/www/html/storage/logs/payments-queue.log"
    Write-Host ""
    
    if ($totalFailed -gt 0) {
        Write-Host "5. Retry failed jobs:"
        Write-Host "   docker exec $ContainerName php artisan queue:retry all"
        Write-Host ""
    }
}

function Repair-CommonIssues {
    Write-Section "Fixing Common Issues"
    
    Write-Info "Clearing log-rotation failed jobs..."
    docker exec $DbContainer psql -U $DbUser -d $DbName -c "DELETE FROM failed_jobs WHERE queue = 'log-rotation';"
    Write-Success "Cleared log-rotation failed jobs"
    
    Write-Info "Restarting queue workers..."
    docker exec $ContainerName supervisorctl restart laravel-queues:*
    Start-Sleep -Seconds 2
    Write-Success "Queue workers restarted"
    
    Write-Info "Checking worker status..."
    $status = docker exec $ContainerName supervisorctl status
    $runningCount = ($status | Select-String "RUNNING").Count
    Write-Host "  Running workers: $runningCount"
}

###############################################################################
# Main Execution
###############################################################################

function Main {
    Clear-Host
    Write-Header "WiFi Hotspot Queue Diagnostic Tool"
    
    Test-Prerequisites
    Get-SupervisorStatus
    Get-QueueSizes
    Get-FailedJobs
    Get-JobThroughput
    Test-DatabaseHealth
    Get-QueueLogs
    Find-CommonIssues
    
    if ($FixFailed) {
        Repair-CommonIssues
    }
    
    Show-Recommendations
    
    Write-Header "Diagnostic Complete"
    Write-Success "All checks completed successfully"
    Write-Host ""
}

# Run main function
Main
