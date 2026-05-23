#!/usr/bin/env bash
set -Eeuo pipefail

COMPOSE_FILE="docker-compose.production.yml"
ENV_FILE=".env.production"
PROJECT_NAME="wificore"
STARTUP_WAIT=60

COMPOSE=(docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" --project-name "$PROJECT_NAME")

CORE_SERVICES=(
  wificore-postgres
  wificore-postgres-replica
  wificore-pgbouncer
  wificore-pgbouncer-read
  wificore-redis
  wificore-victoriametrics
  wificore-soketi
  wificore-wireguard
)

RUNTIME_SERVICES=(
  wificore-provisioning
  wificore-backend
  wificore-backend-sse
  wificore-scheduler
  wificore-queue-core
  wificore-queue-router
  wificore-queue-metrics
  wificore-queue-realtime
  wificore-freeradius
  wificore-telegraf-config
  wificore-telegraf
  wificore-frontend
  wificore-nginx
  wificore-mongo
  wificore-genieacs-cwmp
  wificore-genieacs-nbi
  wificore-genieacs-fs
  wificore-genieacs-ui
)

QUEUE_SERVICES=(
  wificore-queue-core
  wificore-queue-router
  wificore-queue-metrics
  wificore-queue-realtime
)

BUILD_SERVICES=(
  wificore-backend
  wificore-nginx
  wificore-provisioning
  wificore-wireguard
  wificore-redis
  wificore-pgbouncer
)

BUILD_CONTEXTS=(
  ./backend
  ./nginx
  ./provisioning-service
  ./wireguard-controller
  ./docker/redis
  ./pgbouncer
)

PULL_SERVICES=(
  wificore-postgres
  wificore-postgres-replica
  wificore-victoriametrics
  wificore-soketi
  wificore-freeradius
  wificore-telegraf-config
  wificore-telegraf
  wificore-frontend
  wificore-mongo
  wificore-genieacs-cwmp
  wificore-genieacs-nbi
  wificore-genieacs-fs
  wificore-genieacs-ui
)

log() {
  echo -e "\n▶ $1"
}

fail() {
  echo -e "\n❌ ERROR: $1"
  exit 1
}

append_unique() {
  local item="$1"
  shift
  local existing

  for existing in "$@"; do
    if [[ "$existing" == "$item" ]]; then
      return 0
    fi
  done

  return 1
}

pull_with_retries() {
  local service="$1"
  local attempts=3
  local try

  for try in $(seq 1 "$attempts"); do
    if "${COMPOSE[@]}" pull "$service"; then
      return 0
    fi

    if [[ "$try" -lt "$attempts" ]]; then
      echo "⚠️  Pull failed for ${service} (attempt ${try}/${attempts}); retrying in 5s..."
      sleep 5
    fi
  done

  return 1
}

resolve_build_and_pull_services() {
  EFFECTIVE_BUILD_SERVICES=()
  EFFECTIVE_PULL_SERVICES=("${PULL_SERVICES[@]}")

  local i service context
  for i in "${!BUILD_SERVICES[@]}"; do
    service="${BUILD_SERVICES[$i]}"
    context="${BUILD_CONTEXTS[$i]}"

    if [[ -d "$context" ]]; then
      EFFECTIVE_BUILD_SERVICES+=("$service")
    else
      echo "⚠️  Build context missing for ${service} (${context}); falling back to pulling the prebuilt image."
      if ! append_unique "$service" "${EFFECTIVE_PULL_SERVICES[@]}"; then
        EFFECTIVE_PULL_SERVICES+=("$service")
      fi
    fi
  done
}

check_queue_workers() {
  local service

  for service in "${QUEUE_SERVICES[@]}"; do
    log "Checking queue workers in ${service}..."

    if ! "${COMPOSE[@]}" exec -T "$service" sh -lc "ps aux | grep '[q]ueue:work' >/dev/null"; then
      "${COMPOSE[@]}" logs --tail=100 "$service" || true
      fail "${service} is running supervisord but has no queue:work child processes"
    fi
  done
}

show_usage() {
  echo ""
  echo "WiFiCore Deployment Script"
  echo "=========================="
  echo ""
  echo "Usage: $0 [OPTION]"
  echo ""
  echo "Options:"
  echo "  --full-reset    Stop containers, remove volumes, and redeploy (DESTRUCTIVE)"
  echo "  --preserve      Stop containers but preserve volumes, then redeploy (SAFE)"
  echo "  --help          Show this help message"
  echo ""
}

DEPLOY_MODE=""

if [[ $# -eq 0 ]]; then
  show_usage
  echo ""
  read -r -p "Choose deployment mode [preserve/full-reset]: " choice
  case "$choice" in
    preserve|p|1)
      DEPLOY_MODE="preserve"
      ;;
    full-reset|full|reset|2)
      DEPLOY_MODE="full-reset"
      ;;
    *)
      fail "Invalid choice. Please run with --preserve or --full-reset"
      ;;
  esac
else
  case "$1" in
    --full-reset)
      DEPLOY_MODE="full-reset"
      ;;
    --preserve)
      DEPLOY_MODE="preserve"
      ;;
    --help|-h)
      show_usage
      exit 0
      ;;
    *)
      fail "Unknown option: $1. Use --help for usage information."
      ;;
  esac
fi

log "Running pre-flight checks..."

command -v docker >/dev/null 2>&1 || fail "Docker is not installed"
docker info >/dev/null 2>&1 || fail "Docker daemon is not running"

[[ -f "$COMPOSE_FILE" ]] || fail "Compose file not found: $COMPOSE_FILE"
[[ -f "$ENV_FILE" ]] || fail "Env file not found: $ENV_FILE"

log "Validating compose config..."
"${COMPOSE[@]}" config >/dev/null

log "Deploying to production (mode: $DEPLOY_MODE)..."

resolve_build_and_pull_services

if [[ "$DEPLOY_MODE" == "full-reset" ]]; then
  log "WARNING: Full reset will delete all data including database volumes."
  read -r -p "Are you sure you want to continue? (yes/no): " confirm
  if [[ "$confirm" != "yes" ]]; then
    log "Deployment cancelled."
    exit 0
  fi

  log "Stopping containers, removing volumes and clearing orphans..."
  "${COMPOSE[@]}" down -v --remove-orphans
else
  log "Stopping containers and clearing orphans (preserving volumes)..."
  "${COMPOSE[@]}" down --remove-orphans
fi

if [[ ${#EFFECTIVE_BUILD_SERVICES[@]} -gt 0 ]]; then
  log "Building local-source images..."
  "${COMPOSE[@]}" build "${EFFECTIVE_BUILD_SERVICES[@]}"
else
  log "No local build contexts found on this host; skipping local builds."
fi

log "Pulling remote images..."
for service in "${EFFECTIVE_PULL_SERVICES[@]}"; do
  pull_with_retries "$service" || fail "Failed to pull ${service} after multiple attempts"
done

log "Starting core dependency services..."
"${COMPOSE[@]}" up -d --remove-orphans "${CORE_SERVICES[@]}"

log "Running one-shot migrator..."
"${COMPOSE[@]}" --profile ops run --rm wificore-migrator

log "Starting application runtime services..."
"${COMPOSE[@]}" up -d --force-recreate --remove-orphans "${RUNTIME_SERVICES[@]}"

log "Waiting for services to start (${STARTUP_WAIT}s)..."
sleep "$STARTUP_WAIT"

log "Service status:"
"${COMPOSE[@]}" ps

log "Recent logs:"
"${COMPOSE[@]}" logs --tail=50

check_queue_workers

UNHEALTHY=$("${COMPOSE[@]}" ps --status exited)
if [[ -n "$UNHEALTHY" ]]; then
  echo "$UNHEALTHY"
  fail "One or more containers exited unexpectedly"
fi

log "Deployment completed successfully"
