#!/usr/bin/env pwsh
# Fix Soketi WebSocket Issues - Rebuild and Deploy
# This script rebuilds the affected containers and pushes to production

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Fixing Soketi WebSocket Configuration" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Rebuild Frontend (contains Echo configuration changes)
Write-Host "Step 1: Rebuilding Frontend container..." -ForegroundColor Yellow
docker build -t kja2aro/wificore:wificore-frontend -f frontend/Dockerfile.optimized frontend/
if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Frontend build failed!" -ForegroundColor Red
    exit 1
}
Write-Host "✅ Frontend rebuilt successfully" -ForegroundColor Green
Write-Host ""

# Step 2: Rebuild Nginx (contains routing fixes)
Write-Host "Step 2: Rebuilding Nginx container..." -ForegroundColor Yellow
docker build -t kja2aro/wificore:wificore-nginx -f nginx/Dockerfile nginx/
if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Nginx build failed!" -ForegroundColor Red
    exit 1
}
Write-Host "✅ Nginx rebuilt successfully" -ForegroundColor Green
Write-Host ""

# Step 3: Rebuild Soketi (contains configuration fixes)
Write-Host "Step 3: Rebuilding Soketi container..." -ForegroundColor Yellow
docker build -t kja2aro/wificore:wificore-soketi -f soketi/Dockerfile.optimized soketi/
if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Soketi build failed!" -ForegroundColor Red
    exit 1
}
Write-Host "✅ Soketi rebuilt successfully" -ForegroundColor Green
Write-Host ""

# Step 4: Push to Docker Hub
Write-Host "Step 4: Pushing images to Docker Hub..." -ForegroundColor Yellow
Write-Host "Pushing Frontend..." -ForegroundColor Cyan
docker push kja2aro/wificore:wificore-frontend
if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Frontend push failed!" -ForegroundColor Red
    exit 1
}

Write-Host "Pushing Nginx..." -ForegroundColor Cyan
docker push kja2aro/wificore:wificore-nginx
if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Nginx push failed!" -ForegroundColor Red
    exit 1
}

Write-Host "Pushing Soketi..." -ForegroundColor Cyan
docker push kja2aro/wificore:wificore-soketi
if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Soketi push failed!" -ForegroundColor Red
    exit 1
}
Write-Host "✅ All images pushed successfully" -ForegroundColor Green
Write-Host ""

# Step 5: Commit changes to Git
Write-Host "Step 5: Committing changes to Git..." -ForegroundColor Yellow
git add soketi/soketi.json
git add nginx/nginx.conf
git add frontend/src/plugins/echo.js
git add frontend/src/services/websocket.js
git commit -m "Fix Soketi WebSocket issues: correct path configuration and broadcasting auth endpoint"
if ($LASTEXITCODE -ne 0) {
    Write-Host "⚠️ Git commit failed or no changes to commit" -ForegroundColor Yellow
} else {
    Write-Host "✅ Changes committed to Git" -ForegroundColor Green
}
Write-Host ""

# Step 6: Push to remote repository
Write-Host "Step 6: Pushing to remote repository..." -ForegroundColor Yellow
git push origin main
if ($LASTEXITCODE -ne 0) {
    Write-Host "⚠️ Git push failed" -ForegroundColor Yellow
} else {
    Write-Host "✅ Changes pushed to remote repository" -ForegroundColor Green
}
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Build and Push Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next Steps (on production server):" -ForegroundColor Yellow
Write-Host "1. Pull the latest images:" -ForegroundColor White
Write-Host "   docker compose -f docker-compose.production.yml pull wificore-frontend wificore-nginx wificore-soketi" -ForegroundColor Gray
Write-Host ""
Write-Host "2. Restart the affected services:" -ForegroundColor White
Write-Host "   docker compose -f docker-compose.production.yml up -d wificore-frontend wificore-nginx wificore-soketi" -ForegroundColor Gray
Write-Host ""
Write-Host "3. Verify WebSocket connection:" -ForegroundColor White
Write-Host "   - Check browser console for successful WebSocket connection" -ForegroundColor Gray
Write-Host "   - URL should be: wss://wificore.traidsolutions.com/app" -ForegroundColor Gray
Write-Host "   - Broadcasting auth should return 200 OK" -ForegroundColor Gray
Write-Host ""
