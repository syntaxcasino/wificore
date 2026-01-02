$body = @{
    username = "sysadmin"
    password = "Admin@123!"
    remember = $false
} | ConvertTo-Json

try {
    $response = Invoke-RestMethod -Uri "http://localhost/api/login" `
        -Method POST `
        -ContentType "application/json" `
        -Body $body
    
    Write-Host "Success!"
    $response | ConvertTo-Json -Depth 10
} catch {
    Write-Host "Error: $($_.Exception.Message)"
    if ($_.ErrorDetails.Message) {
        Write-Host "Details:"
        $_.ErrorDetails.Message | ConvertFrom-Json | ConvertTo-Json -Depth 10
    }
}
