#!/usr/bin/env bash

set -euo pipefail

COMPOSE_FILE="docker-compose.production.yml"
BACKEND_SERVICE="wificore-backend"
STRICT=1
PROBE=1
MAX_SKEW=5

usage() {
  cat <<USAGE
Usage: $0 [options]

Options:
  --compose-file <path>      Docker compose file (default: docker-compose.production.yml)
  --backend-service <name>   Backend service name (default: wificore-backend)
  --no-strict                Run preflight without --strict
  --no-probe                 Skip provisioning Date-header probe
  --max-skew <seconds>       Max allowed skew for Date-header probe (default: 5)
  -h, --help                 Show help
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --compose-file)
      COMPOSE_FILE="$2"
      shift 2
      ;;
    --backend-service)
      BACKEND_SERVICE="$2"
      shift 2
      ;;
    --no-strict)
      STRICT=0
      shift
      ;;
    --no-probe)
      PROBE=0
      shift
      ;;
    --max-skew)
      MAX_SKEW="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1"
      usage
      exit 1
      ;;
  esac
done

if ! [[ "$MAX_SKEW" =~ ^[0-9]+$ ]]; then
  echo "--max-skew must be a positive integer"
  exit 1
fi

if [[ ! -f "$COMPOSE_FILE" ]]; then
  echo "Compose file not found: $COMPOSE_FILE"
  exit 1
fi

echo "==> Provisioning callback guard preflight"
echo "Compose file: $COMPOSE_FILE"
echo "Backend service: $BACKEND_SERVICE"

echo "==> Checking backend container availability"
if ! docker compose -f "$COMPOSE_FILE" ps "$BACKEND_SERVICE" >/dev/null 2>&1; then
  echo "Backend service not found in compose stack: $BACKEND_SERVICE"
  exit 1
fi

CMD=("php" "artisan" "provisioning:callback-guard-preflight")
if [[ "$STRICT" -eq 1 ]]; then
  CMD+=("--strict")
fi
if [[ "$PROBE" -eq 1 ]]; then
  CMD+=("--probe-provisioning-date" "--max-probe-skew-seconds=$MAX_SKEW")
fi

echo "==> Running preflight: ${CMD[*]}"
docker compose -f "$COMPOSE_FILE" exec -T "$BACKEND_SERVICE" "${CMD[@]}"

echo "==> Preflight completed successfully"
echo "You can now safely proceed to staged flag rollout:"
echo "  1) PROVISIONING_REQUIRE_CALLBACK_IDENTITY=true"
echo "  2) PROVISIONING_REJECT_STALE_CALLBACKS=true (after time-sync validation)"
