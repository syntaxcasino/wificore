#!/bin/bash

################################################################################
# Build and Push Docker Images to Docker Hub
# 
# This script builds all Docker images for the TraidNet WiFi Hotspot system
# and pushes them to Docker Hub with proper tagging.
#
# Usage:
#   ./build-and-push-images.sh [options]
#
# Options:
#   --tag VERSION       Specify version tag (default: latest)
#   --no-cache          Build without cache
#   --push-only         Skip build, only push existing images
#   --build-only        Only build, don't push
#   --service SERVICE   Build/push only specific service
#   --help              Show this help message
#
# Examples:
#   ./build-and-push-images.sh
#   ./build-and-push-images.sh --tag v1.0.0
#   ./build-and-push-images.sh --service backend --tag v1.0.0
#   ./build-and-push-images.sh --no-cache --tag latest
################################################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Default configuration
DOCKER_USERNAME="kja2aro"
TAG="latest"
NO_CACHE=""
PUSH_ONLY=false
BUILD_ONLY=false
SPECIFIC_SERVICE=""

# Image definitions (matching docker-compose-deployment.yml)
declare -A IMAGES=(
    ["nginx"]="traidnet-nginx"
    ["frontend"]="traidnet-frontend"
    ["backend"]="traidnet-backend"
    ["soketi"]="traidnet-soketi"
    ["freeradius"]="traidnet-freeradius"
)

declare -A BUILD_CONTEXTS=(
    ["nginx"]="../nginx"
    ["frontend"]="../frontend"
    ["backend"]="../backend"
    ["soketi"]="../soketi"
    ["freeradius"]="../freeradius"
)

################################################################################
# Helper Functions
################################################################################

print_header() {
    echo -e "${BLUE}================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}================================${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

show_help() {
    cat << EOF
Build and Push Docker Images to Docker Hub

Usage:
  ./build-and-push-images.sh [options]

Options:
  --tag VERSION       Specify version tag (default: latest)
  --no-cache          Build without cache
  --push-only         Skip build, only push existing images
  --build-only        Only build, don't push
  --service SERVICE   Build/push only specific service
  --help              Show this help message

Available Services:
  nginx, frontend, backend, soketi, freeradius

Examples:
  ./build-and-push-images.sh
  ./build-and-push-images.sh --tag v1.0.0
  ./build-and-push-images.sh --service backend --tag v1.0.0
  ./build-and-push-images.sh --no-cache --tag latest

EOF
}

check_docker() {
    if ! command -v docker &> /dev/null; then
        print_error "Docker is not installed or not in PATH"
        exit 1
    fi
    print_success "Docker is available"
}

check_docker_login() {
    print_info "Checking Docker Hub authentication..."
    if ! docker info | grep -q "Username: $DOCKER_USERNAME"; then
        print_warning "Not logged in to Docker Hub"
        print_info "Attempting to log in..."
        if ! docker login; then
            print_error "Docker login failed"
            exit 1
        fi
    fi
    print_success "Authenticated with Docker Hub as $DOCKER_USERNAME"
}

build_image() {
    local service=$1
    local image_name=$2
    local context=$3
    local full_image="${DOCKER_USERNAME}/${image_name}:${TAG}"
    
    print_header "Building: $service"
    print_info "Image: $full_image"
    print_info "Context: $context"
    
    if [ ! -d "$context" ]; then
        print_error "Build context directory not found: $context"
        return 1
    fi
    
    local build_cmd="docker build ${NO_CACHE} -t ${full_image}"
    
    # Add latest tag if not already latest
    if [ "$TAG" != "latest" ]; then
        build_cmd="${build_cmd} -t ${DOCKER_USERNAME}/${image_name}:latest"
    fi
    
    build_cmd="${build_cmd} ${context}"
    
    print_info "Running: $build_cmd"
    
    if eval $build_cmd; then
        print_success "Built: $full_image"
        return 0
    else
        print_error "Failed to build: $full_image"
        return 1
    fi
}

push_image() {
    local service=$1
    local image_name=$2
    local full_image="${DOCKER_USERNAME}/${image_name}:${TAG}"
    
    print_header "Pushing: $service"
    print_info "Image: $full_image"
    
    if docker push "$full_image"; then
        print_success "Pushed: $full_image"
        
        # Push latest tag if we're tagging with version
        if [ "$TAG" != "latest" ]; then
            local latest_image="${DOCKER_USERNAME}/${image_name}:latest"
            print_info "Also pushing: $latest_image"
            if docker push "$latest_image"; then
                print_success "Pushed: $latest_image"
            else
                print_warning "Failed to push latest tag"
            fi
        fi
        return 0
    else
        print_error "Failed to push: $full_image"
        return 1
    fi
}

################################################################################
# Parse Arguments
################################################################################

while [[ $# -gt 0 ]]; do
    case $1 in
        --tag)
            TAG="$2"
            shift 2
            ;;
        --no-cache)
            NO_CACHE="--no-cache"
            shift
            ;;
        --push-only)
            PUSH_ONLY=true
            shift
            ;;
        --build-only)
            BUILD_ONLY=true
            shift
            ;;
        --service)
            SPECIFIC_SERVICE="$2"
            shift 2
            ;;
        --help)
            show_help
            exit 0
            ;;
        *)
            print_error "Unknown option: $1"
            show_help
            exit 1
            ;;
    esac
done

################################################################################
# Main Script
################################################################################

print_header "TraidNet Docker Image Builder"
echo ""
print_info "Docker Username: $DOCKER_USERNAME"
print_info "Tag: $TAG"
print_info "No Cache: ${NO_CACHE:-false}"
print_info "Push Only: $PUSH_ONLY"
print_info "Build Only: $BUILD_ONLY"
if [ -n "$SPECIFIC_SERVICE" ]; then
    print_info "Specific Service: $SPECIFIC_SERVICE"
fi
echo ""

# Check prerequisites
check_docker

if [ "$PUSH_ONLY" = false ]; then
    print_info "Build mode enabled"
fi

if [ "$BUILD_ONLY" = false ]; then
    check_docker_login
fi

# Determine which services to process
services_to_process=()
if [ -n "$SPECIFIC_SERVICE" ]; then
    if [[ -v IMAGES[$SPECIFIC_SERVICE] ]]; then
        services_to_process=("$SPECIFIC_SERVICE")
    else
        print_error "Unknown service: $SPECIFIC_SERVICE"
        print_info "Available services: ${!IMAGES[@]}"
        exit 1
    fi
else
    services_to_process=("${!IMAGES[@]}")
fi

# Track results
declare -A BUILD_RESULTS
declare -A PUSH_RESULTS
TOTAL_SERVICES=${#services_to_process[@]}
SUCCESSFUL_BUILDS=0
FAILED_BUILDS=0
SUCCESSFUL_PUSHES=0
FAILED_PUSHES=0

echo ""
print_header "Processing ${TOTAL_SERVICES} service(s)"
echo ""

# Process each service
for service in "${services_to_process[@]}"; do
    image_name="${IMAGES[$service]}"
    context="${BUILD_CONTEXTS[$service]}"
    
    # Build phase
    if [ "$PUSH_ONLY" = false ]; then
        if build_image "$service" "$image_name" "$context"; then
            BUILD_RESULTS[$service]="success"
            ((SUCCESSFUL_BUILDS++))
        else
            BUILD_RESULTS[$service]="failed"
            ((FAILED_BUILDS++))
            print_warning "Skipping push for $service due to build failure"
            continue
        fi
        echo ""
    fi
    
    # Push phase
    if [ "$BUILD_ONLY" = false ]; then
        if push_image "$service" "$image_name"; then
            PUSH_RESULTS[$service]="success"
            ((SUCCESSFUL_PUSHES++))
        else
            PUSH_RESULTS[$service]="failed"
            ((FAILED_PUSHES++))
        fi
        echo ""
    fi
done

################################################################################
# Summary
################################################################################

print_header "Summary"
echo ""

if [ "$PUSH_ONLY" = false ]; then
    print_info "Build Results:"
    for service in "${services_to_process[@]}"; do
        if [ "${BUILD_RESULTS[$service]}" = "success" ]; then
            print_success "  $service: Built successfully"
        else
            print_error "  $service: Build failed"
        fi
    done
    echo ""
    print_info "Build Statistics:"
    echo "  Total: $TOTAL_SERVICES"
    echo "  Successful: $SUCCESSFUL_BUILDS"
    echo "  Failed: $FAILED_BUILDS"
    echo ""
fi

if [ "$BUILD_ONLY" = false ]; then
    print_info "Push Results:"
    for service in "${services_to_process[@]}"; do
        if [ "${PUSH_RESULTS[$service]}" = "success" ]; then
            print_success "  $service: Pushed successfully"
        elif [ "${PUSH_RESULTS[$service]}" = "failed" ]; then
            print_error "  $service: Push failed"
        fi
    done
    echo ""
    print_info "Push Statistics:"
    echo "  Total: ${#PUSH_RESULTS[@]}"
    echo "  Successful: $SUCCESSFUL_PUSHES"
    echo "  Failed: $FAILED_PUSHES"
    echo ""
fi

# Print Docker Hub URLs
if [ "$BUILD_ONLY" = false ] && [ $SUCCESSFUL_PUSHES -gt 0 ]; then
    print_header "Docker Hub Images"
    echo ""
    for service in "${services_to_process[@]}"; do
        if [ "${PUSH_RESULTS[$service]}" = "success" ]; then
            image_name="${IMAGES[$service]}"
            echo "  https://hub.docker.com/r/${DOCKER_USERNAME}/${image_name}"
        fi
    done
    echo ""
fi

# Exit with appropriate code
if [ "$PUSH_ONLY" = false ] && [ $FAILED_BUILDS -gt 0 ]; then
    print_error "Some builds failed!"
    exit 1
elif [ "$BUILD_ONLY" = false ] && [ $FAILED_PUSHES -gt 0 ]; then
    print_error "Some pushes failed!"
    exit 1
else
    print_success "All operations completed successfully!"
    exit 0
fi
