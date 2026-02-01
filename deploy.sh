#!/usr/bin/env bash
set -Eeuo pipefail

#######################################
# CONFIG
#######################################
COMPOSE_FILE="docker-compose.production.yml"
ENV_FILE=".env.production"
PROJECT_NAME="wificore"
STARTUP_WAIT=60

#######################################
# HELPERS
#######################################
log() {
  echo -e "\n▶ $1"
}

fail() {
  echo -e "\n❌ ERROR: $1"
  exit 1
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
  echo "Examples:"
  echo "  $0 --preserve     # Recommended for updates"
  echo "  $0 --full-reset   # Use only when you need a fresh start"
  echo ""
}

#######################################
# PARSE ARGUMENTS
#######################################
DEPLOY_MODE=""

if [ $# -eq 0 ]; then
  show_usage
  echo ""
  read -p "Choose deployment mode [preserve/full-reset]: " choice
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

#######################################
# PRE-FLIGHT CHECKS
#######################################
log "Running pre-flight checks..."

command -v docker >/dev/null 2>&1 || fail "Docker is not installed"
docker info >/dev/null 2>&1 || fail "Docker daemon is not running"

[ -f "$COMPOSE_FILE" ] || fail "Compose file not found: $COMPOSE_FILE"
[ -f "$ENV_FILE" ] || fail "Env file not found: $ENV_FILE"

#######################################
# DEPLOY
#######################################
log "Deploying to production (mode: $DEPLOY_MODE)..."

if [ "$DEPLOY_MODE" = "full-reset" ]; then
  log "WARNING: Full reset will delete all data including database volumes!"
  read -p "Are you sure you want to continue? (yes/no): " confirm
  if [ "$confirm" != "yes" ]; then
    log "Deployment cancelled."
    exit 0
  fi
  
  log "Stopping containers and removing volumes..."
  docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" down -v
else
  log "Stopping containers (preserving volumes)..."
  docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" down
fi

log "Pulling latest images..."
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" pull

log "Starting containers..."
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" up -d  



#######################################
# HEALTH CHECK
#######################################
log "Waiting for services to start (${STARTUP_WAIT}s)..."
sleep "$STARTUP_WAIT"

log "Service status:"
docker compose --project-name "$PROJECT_NAME" ps

log "Recent logs:"
docker compose --project-name "$PROJECT_NAME" logs --tail=50

#######################################
# OPTIONAL: BASIC HEALTH VERIFICATION
#######################################
UNHEALTHY=$(docker compose --project-name "$PROJECT_NAME" ps --status exited)

if [[ -n "$UNHEALTHY" ]]; then
  echo "$UNHEALTHY"
  fail "One or more containers exited unexpectedly"
fi

log "Deployment completed successfully ✅"
