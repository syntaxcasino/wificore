#!/usr/bin/env bash
set -Eeuo pipefail

COMPOSE_FILE="docker-compose.production.yml"
PROJECT_NAME="wificore"
DOCKERHUB_USERNAME="kja2aro"
REPO_NAME="wificore"
GIT_SHA=$(git rev-parse --short HEAD 2>/dev/null || echo "latest")

BUILD_MODE=${1:-2}

if [[ "$BUILD_MODE" != "1" && "$BUILD_MODE" != "2" ]]; then
  echo "Invalid argument. Usage:"
  echo "  $0 1    # Build with --no-cache"
  echo "  $0 2    # Build with cache (default)"
  exit 1
fi

if [[ "$BUILD_MODE" == "1" ]]; then
  BUILD_ARGS=(--no-cache --parallel)
  BUILD_TYPE="NO CACHE"
else
  BUILD_ARGS=(--parallel)
  BUILD_TYPE="WITH CACHE"
fi

COMPOSE_BUILD_SERVICES=(
  wificore-nginx
  wificore-frontend
  wificore-backend
  wificore-freeradius
  wificore-soketi
  wificore-postgres
  wificore-wireguard
  wificore-provisioning
  wificore-pgbouncer
  wificore-redis
)

COMPOSE_PUSH_SERVICES=(
  wificore-nginx
  wificore-frontend
  wificore-backend
  wificore-freeradius
  wificore-soketi
  wificore-postgres
  wificore-wireguard
  wificore-provisioning
  wificore-pgbouncer
  wificore-redis
)

echo "Building and pushing WifiCore production images"
echo "Compose file: $COMPOSE_FILE"
echo "Project: $PROJECT_NAME"
echo "Git SHA: $GIT_SHA"
echo "Build mode: $BUILD_TYPE"
echo

command -v docker >/dev/null 2>&1 || {
  echo "Docker is not installed"
  exit 1
}

docker info >/dev/null 2>&1 || {
  echo "Docker daemon is not running"
  exit 1
}

[[ -f "$COMPOSE_FILE" ]] || {
  echo "Compose file not found: $COMPOSE_FILE"
  exit 1
}

echo "Logging in to DockerHub..."
docker login

echo "Building images from production compose..."
docker compose -f "$COMPOSE_FILE" --project-name "$PROJECT_NAME" build "${BUILD_ARGS[@]}" "${COMPOSE_BUILD_SERVICES[@]}"

echo "Pushing production tags used by deploy..."
docker compose -f "$COMPOSE_FILE" --project-name "$PROJECT_NAME" push "${COMPOSE_PUSH_SERVICES[@]}"

echo "Tagging immutable backend image..."
docker tag \
  "$DOCKERHUB_USERNAME/$REPO_NAME:wificore-backend" \
  "$DOCKERHUB_USERNAME/$REPO_NAME:wificore-backend-$GIT_SHA"
docker push "$DOCKERHUB_USERNAME/$REPO_NAME:wificore-backend-$GIT_SHA"

echo "Tagging immutable nginx image..."
docker tag \
  "$DOCKERHUB_USERNAME/$REPO_NAME:wificore-nginx" \
  "$DOCKERHUB_USERNAME/$REPO_NAME:wificore-nginx-$GIT_SHA"
docker push "$DOCKERHUB_USERNAME/$REPO_NAME:wificore-nginx-$GIT_SHA"

echo "Deployment target images updated successfully."
echo "Runtime note: backend image now serves web, sse, scheduler, migrator and queue roles."
echo
echo "Starting remote deployment..."
ssh 144.91.71.208 -l kja2aro "cd /opt/wificore && ./deploy.sh --preserve"
