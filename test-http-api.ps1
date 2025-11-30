Write-Host "Testing HTTP API Endpoint..." -ForegroundColor Cyan
Write-Host ""

try {
    $response = Invoke-WebRequest -Uri "http://localhost/api/system/queue/stats" -UseBasicParsing
    $content = $response.Content
    
    Write-Host "HTTP Status: $($response.StatusCode)" -ForegroundColor Green
    Write-Host ""
    Write-Host "Response:" -ForegroundColor Yellow
    Write-Host $content
    Write-Host ""
    
    # Parse JSON
    $data = $content | ConvertFrom-Json
    Write-Host "Workers: $($data.workers)" -ForegroundColor $(if ($data.workers -gt 0) { "Green" } else { "Red" })
    Write-Host "Source: $($data.source)" -ForegroundColor Magenta
    
} catch {
    Write-Host "Error: $_" -ForegroundColor Red
    Write-Host ""
    Write-Host "Trying via docker exec instead..." -ForegroundColor Yellow
    
    $result = docker exec traidnet-backend php /var/www/html/test-api-endpoint.php
    Write-Host $result
}
