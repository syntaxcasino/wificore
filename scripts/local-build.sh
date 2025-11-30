#!/bin/bash
# build.sh

#set -e  # Exit on error

# Load environment variables
#if [ -f .env ]; then
#    export $(cat .env | grep -v '#' | awk '/=/ {print $1}')
#fi

# Build images with cache optimization
echo "Building images..."
docker-compose build --no-cache --parallel backend frontend nginx

docker-compose up 
# Or for specific services with cache optimization:
# docker-compose build --parallel nginx frontend backend

# Push images
#echo "Pushing images to Docker Hub..."
#docker push kja2aro/traidnet-backend:${BACKEND_TAG:-latest}
#docker push kja2aro/traidnet-nginx:${NGINX_TAG:-latest}
#docker push kja2aro/traidnet-frontend:${FRONTEND_TAG:-latest}



