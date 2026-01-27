#!/bin/bash
# build.sh - Simplified Docker Compose management script with easy workflows

set -e  # Exit on error

# Default values
MODE="build"
SERVICES=()  # Array for specific services
NO_CACHE=false
PARALLEL=true
PUSH=false
VERBOSE=false
REMOVE_VOLUMES=false

# Simple flags (shortened for ease)
while [[ $# -gt 0 ]]; do
    case $1 in
        # Workflows (new simple modes)
        rebuild)
            MODE="rebuild"
            shift
            ;;
        start)
            MODE="start"
            shift
            ;;
        stop)
            MODE="stop"
            shift
            ;;
        status)
            MODE="status"
            shift
            ;;
        deploy)
            MODE="deploy"
            shift
            ;;
        # Original modes (for advanced use)
        build|up|down|ps|push)
            MODE="$1"
            shift
            ;;
        # Short flags
        -n|--no-cache)
            NO_CACHE=true
            shift
            ;;
        -p|--push)
            PUSH=true
            shift
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        --volumes)
            REMOVE_VOLUMES=true
            shift
            ;;
        # Services (non-flags)
        -*)
            echo "Unknown option: $1"
            help
            shift
            ;;
        *)
            SERVICES+=("$1")
            shift
            ;;
    esac
done

# Load environment variables if .env exists
if [ -f .env ]; then
    export $(grep -v '^#' .env | grep '=' | sed 's/export //g' | xargs)
fi

# Verbose logging
log() {
    if [ "$VERBOSE" = true ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
    fi
}

# Command builders (simplified)
build_cmd() {
    local cmd="docker compose build"
    if [ "$NO_CACHE" = true ]; then
        cmd="$cmd --no-cache"
    fi
    if [ "$PARALLEL" = true ]; then
        cmd="$cmd --parallel"
    fi
    if [ ${#SERVICES[@]} -gt 0 ]; then
        cmd="$cmd ${SERVICES[*]}"
    fi
    echo "$cmd"
}

up_cmd() {
    local cmd="docker compose up -d"  # Default to detached for simplicity
    if [ ${#SERVICES[@]} -gt 0 ]; then
        cmd="$cmd ${SERVICES[*]}"
    fi
    echo "$cmd"
}

down_cmd() {
    local cmd="docker compose down"
    if [ "$REMOVE_VOLUMES" = true ]; then
        cmd="$cmd -v"
    fi
    if [ ${#SERVICES[@]} -gt 0 ]; then
        cmd="$cmd ${SERVICES[*]}"
    fi
    echo "$cmd"
}

push_cmd() {
    local services=("${SERVICES[@]}")
    if [ ${#services[@]} -eq 0 ]; then
        services=("backend" "nginx" "frontend" "freeradius" "soketi")
    fi
    for service in "${services[@]}"; do
        UPPER_TAG=$(echo "$service" | tr '[:lower:]' '[:upper:]')_TAG
        local tag="${!UPPER_TAG:-latest}"
        local img_name="wifi-hotspot-traidnet-$service:$tag"
        docker push "$img_name" 2>/dev/null || true
    done
}

# Main execution
case "$MODE" in
    # Simple workflows
    rebuild)
        log "Rebuilding: Stop, no-cache build, start detached"
        eval "$(down_cmd)"
        eval "$(build_cmd)"
        eval "$(up_cmd)"
        ;;
    start)
        log "Starting services (detached)"
        eval "$(up_cmd)"
        ;;
    stop)
        log "Stopping services (with volumes)"
        eval "$(down_cmd)"
        ;;
    status)
        docker compose ps -a
        ;;
    deploy)
        log "Deploying: Build, push, start"
        eval "$(build_cmd)"
        if [ "$PUSH" = true ]; then
            push_cmd
        else
            push_cmd  # Default push in deploy
        fi
        eval "$(up_cmd)"
        ;;
    # Advanced modes (unchanged)
    build)
        log "Building images"
        eval "$(build_cmd)"
        if [ "$PUSH" = true ]; then
            push_cmd
        fi
        ;;
    up)
        log "Starting services"
        eval "$(up_cmd)"
        ;;
    down)
        log "Stopping services"
        eval "$(down_cmd)"
        ;;
    ps)
        docker compose ps -a
        ;;
    push)
        log "Pushing images"
        push_cmd
        ;;
    *)
        echo "Unknown mode: $MODE"
        help
        ;;
esac

log "Done."