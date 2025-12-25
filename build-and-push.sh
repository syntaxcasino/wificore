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

docker login

# ==========================
# BUILD
# ==========================
echo "🏗 Building images..."
docker compose build --parallel
#docker buildx bake --no-cache --progress plain
# ==========================
# TAG
# ==========================
echo "🏷 Tagging images..."

# Tag images with git SHA
docker tag wificore-wificore-backend:latest    $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-backend-$GIT_SHA
docker tag wificore-wificore-frontend:latest   $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-frontend-$GIT_SHA
docker tag wificore-wificore-nginx:latest      $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-nginx-$GIT_SHA
docker tag wificore-wificore-freeradius:latest $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-freeradius-$GIT_SHA
docker tag wificore-wificore-soketi:latest     $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-soketi-$GIT_SHA
docker tag wificore-wificore-postgres:latest   $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-postgres-$GIT_SHA

# Redis is already tagged correctly by docker-compose, just add git SHA tag
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-redis $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-redis-$GIT_SHA

# Optional "latest-style" tags (Redis already has this from docker-compose)
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-backend-$GIT_SHA    $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-backend
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-frontend-$GIT_SHA   $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-frontend
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-nginx-$GIT_SHA      $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-nginx
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-freeradius-$GIT_SHA $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-freeradius
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-soketi-$GIT_SHA     $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-soketi
docker tag $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-postgres-$GIT_SHA   $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-postgres

# ==========================
# PUSH
# ==========================
echo "📤 Pushing images..."

docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-backend-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-frontend-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-nginx-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-postgres-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-freeradius-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-soketi-$GIT_SHA
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-redis-$GIT_SHA

docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-backend
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-frontend
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-nginx
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-postgres
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-freeradius
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-soketi
docker push $DOCKERHUB_USERNAME/$REPO_NAME:${APP_PREFIX}-redis

echo "✅ All images pushed successfully"
