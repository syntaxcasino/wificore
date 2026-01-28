#!/usr/bin/env bash
set -e

# ==========================
# CONFIG
# ==========================
DOCKERHUB_USERNAME="kja2aro"
REPO_NAME="wificore"
APP_PREFIX="wificore"

GIT_SHA=$(git rev-parse --short HEAD 2>/dev/null || echo "latest")

echo "🚀 Building & pushing WifiCore images"
echo "📦 Repo: $DOCKERHUB_USERNAME/$REPO_NAME"
echo "🏷 Tag: $GIT_SHA"
echo "----------------------------------"

# ==========================
# LOGIN CHECK
# ==========================
docker info >/dev/null 2>&1 || {
  echo "❌ Docker is not running"
  exit 1
}

echo "🔐 Logging in to DockerHub..."
docker login

# ==========================
# BUILD
# ==========================
echo "🏗 Building images..."
echo "Building all services..."
docker compose build --parallel \
  wificore-nginx \
  wificore-frontend \
  wificore-backend \
  wificore-freeradius \
  wificore-soketi \
  wificore-postgres \
  wificore-wireguard \
  wificore-provisioning \
  wificore-pgbouncer \
  wificore-pgbouncer-read \
  wificore-redis
# ==========================
# TAG
# ==========================
echo "🏷 Tagging images..."

# Tag main application images with git SHA
docker tag wificore-wificore-backend:latest    $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-backend-$GIT_SHA
docker tag wificore-wificore-frontend:latest   $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-frontend-$GIT_SHA
docker tag wificore-wificore-nginx:latest      $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-nginx-$GIT_SHA
docker tag wificore-wificore-freeradius:latest $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-freeradius-$GIT_SHA
docker tag wificore-wificore-soketi:latest     $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-soketi-$GIT_SHA
docker tag wificore-wificore-postgres:latest   $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-postgres-$GIT_SHA

# Tag new Phase 1 services
docker tag wificore-wificore-wireguard:latest     $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-wireguard-$GIT_SHA
docker tag wificore-wificore-pgbouncer:latest     $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-pgbouncer-$GIT_SHA
docker tag wificore-wificore-provisioning:latest  $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-provisioning-$GIT_SHA
docker tag kja2aro/wificore:wificore-redis        $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-redis-$GIT_SHA

# Tag with "latest" style tags
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-backend-$GIT_SHA       $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-backend
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-frontend-$GIT_SHA      $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-frontend
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-nginx-$GIT_SHA         $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-nginx
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-freeradius-$GIT_SHA    $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-freeradius
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-soketi-$GIT_SHA        $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-soketi
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-postgres-$GIT_SHA      $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-postgres
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-wireguard-$GIT_SHA     $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-wireguard
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-pgbouncer-$GIT_SHA     $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-pgbouncer
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-provisioning-$GIT_SHA  $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-provisioning
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-redis-$GIT_SHA         $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-redis

# ==========================
# PUSH
# ==========================
echo "📤 Pushing images with git SHA tags..."

docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-backend-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-frontend-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-nginx-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-postgres-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-freeradius-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-soketi-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-wireguard-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-pgbouncer-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-provisioning-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-redis-$GIT_SHA

echo "📤 Pushing images with 'latest' tags..."

docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-backend
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-frontend
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-nginx
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-postgres
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-freeradius
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-soketi
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-wireguard
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-pgbouncer
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-provisioning
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-redis

echo "✅ All images pushed successfully!"
echo ""
echo "📋 Pushed images:"
echo "  - Backend: $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-backend"
echo "  - Frontend: $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-frontend"
echo "  - Nginx: $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-nginx"
echo "  - PostgreSQL: $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-postgres"
echo "  - FreeRADIUS: $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-freeradius"
echo "  - Soketi: $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-soketi"
echo "  - WireGuard: $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-wireguard"
echo "  - PgBouncer: $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-pgbouncer"
echo "  - PgBouncer Read: uses the same image tag as PgBouncer ($DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-pgbouncer)"
echo "  - Provisioning Service: $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-provisioning"
echo "  - Redis: $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-redis"
echo ""
echo "⏰ Completed at: $(date '+%Y-%m-%d %H:%M:%S %Z')"
