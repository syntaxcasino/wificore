#!/usr/bin/env bash
set -Eeuo pipefail

# Fast build: only build services that changed, reuse cache for the rest.
# Usage: ./build-fast.sh [service1 service2 ...]
#   e.g. ./build-fast.sh wificore-frontend
#   e.g. ./build-fast.sh wificore-frontend wificore-nginx
#   e.g. ./build-fast.sh wificore-backend  (for PHP-only changes)
#   e.g. ./build-fast.sh --all             (build all, like original script)

COMPOSE_FILE="docker-compose.production.yml"
PROJECT_NAME="wificore"
DOCKERHUB_USERNAME="kja2aro"
REPO_NAME="wificore"
GIT_SHA=$(git rev-parse --short HEAD 2>/dev/null || echo "latest")

ALL_BUILD_SERVICES=(
  wificore-nginx
  wificore-frontend
  wificore-backend
  wificore-freeradius
  wificore-soketi
  wificore-postgres
  wificore-wireguard
  wificore-provisioning
  wificore-pgbouncer
  wificore-redis-primary
  wificore-redis
)

ALL_PUSH_SERVICES=(
  wificore-nginx
  wificore-frontend
  wificore-backend
  wificore-freeradius
  wificore-soketi
  wificore-postgres
  wificore-wireguard
  wificore-provisioning
  wificore-pgbouncer
  wificore-redis-primary
  wificore-redis
)

# Determine which services to build
if [[ $# -eq 0 ]]; then
  echo "Usage: $0 [service ...]"
  echo ""
  echo "Available services:"
  printf '  %s\n' "${ALL_BUILD_SERVICES[@]}"
  echo ""
  echo "Shortcuts:"
  echo "  $0 frontend          # build only frontend + nginx"
  echo "  $0 backend           # build only backend"
  echo "  $0 --all             # build all (original slow behavior)"
  echo ""
  echo "Most common:  ./build-fast.sh frontend"
  exit 1
fi

if [[ "$1" == "--all" ]]; then
  BUILD_SERVICES=("${ALL_BUILD_SERVICES[@]}")
  PUSH_SERVICES=("${ALL_PUSH_SERVICES[@]}")
elif [[ "$1" == "frontend" ]]; then
  # Frontend needs both frontend + nginx (nginx proxies to frontend)
  BUILD_SERVICES=(wificore-frontend wificore-nginx)
  PUSH_SERVICES=(wificore-frontend wificore-nginx)
elif [[ "$1" == "backend" ]]; then
  BUILD_SERVICES=(wificore-backend)
  PUSH_SERVICES=(wificore-backend)
else
  BUILD_SERVICES=("$@")
  PUSH_SERVICES=("$@")
fi

echo "Fast build mode: only building changed services"
echo "Services to build: ${BUILD_SERVICES[*]}"
echo "Git SHA: $GIT_SHA"
echo ""

command -v docker >/dev/null 2>&1 || { echo "Docker not installed"; exit 1; }
docker info >/dev/null 2>&1 || { echo "Docker daemon not running"; exit 1; }

# Ensure BuildKit is enabled (required for cache mounts)
export DOCKER_BUILDKIT=1
export COMPOSE_DOCKER_CLI_BUILD=1

echo "Logging in to DockerHub..."
docker login

echo "Building only: ${BUILD_SERVICES[*]} ..."
docker compose -f "$COMPOSE_FILE" --project-name "$PROJECT_NAME" build --parallel "${BUILD_SERVICES[@]}"

echo "Pushing updated images..."
for svc in "${PUSH_SERVICES[@]}"; do
  docker compose -f "$COMPOSE_FILE" --project-name "$PROJECT_NAME" push "$svc"
done

# Tag immutable images for services that were built
for svc in "${PUSH_SERVICES[@]}"; do
  if [[ "$svc" == "wificore-backend" ]]; then
    docker tag "$DOCKERHUB_USERNAME/$REPO_NAME:$svc" "$DOCKERHUB_USERNAME/$REPO_NAME:${svc}-${GIT_SHA}"
    docker push "$DOCKERHUB_USERNAME/$REPO_NAME:${svc}-${GIT_SHA}"
  fi
  if [[ "$svc" == "wificore-nginx" ]]; then
    docker tag "$DOCKERHUB_USERNAME/$REPO_NAME:$svc" "$DOCKERHUB_USERNAME/$REPO_NAME:${svc}-${GIT_SHA}"
    docker push "$DOCKERHUB_USERNAME/$REPO_NAME:${svc}-${GIT_SHA}"
  fi
done

echo ""
echo "Fast build complete. Services updated: ${PUSH_SERVICES[*]}"
echo ""
echo "To deploy, run:"
echo "  ssh kja2aro@144.91.71.208 'cd /opt/wificore && ./deploy.sh --preserve'"
