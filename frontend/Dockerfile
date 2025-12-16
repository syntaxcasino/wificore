# -------------------------------------------------------------------
# Stage 1: Build Vue app with minimal Node Alpine
# -------------------------------------------------------------------
FROM node:22-alpine AS builder

WORKDIR /app

# Build arguments for environment variables
ARG VITE_API_URL=/api
ARG VITE_API_BASE_URL=/api
ARG VITE_PUSHER_APP_KEY=app-key
ARG VITE_PUSHER_HOST=localhost
ARG VITE_PUSHER_PORT=8070
ARG VITE_PUSHER_SCHEME=ws
ARG VITE_PUSHER_APP_CLUSTER=mt1
ARG VITE_PUSHER_AUTH_ENDPOINT=/api/broadcasting/auth
ARG VITE_PUSHER_PATH=/app

# Set environment variables for Vite build
ENV VITE_API_URL=$VITE_API_URL
ENV VITE_API_BASE_URL=$VITE_API_BASE_URL
ENV VITE_PUSHER_APP_KEY=$VITE_PUSHER_APP_KEY
ENV VITE_PUSHER_HOST=$VITE_PUSHER_HOST
ENV VITE_PUSHER_PORT=$VITE_PUSHER_PORT
ENV VITE_PUSHER_SCHEME=$VITE_PUSHER_SCHEME
ENV VITE_PUSHER_APP_CLUSTER=$VITE_PUSHER_APP_CLUSTER
ENV VITE_PUSHER_AUTH_ENDPOINT=$VITE_PUSHER_AUTH_ENDPOINT
ENV VITE_PUSHER_PATH=$VITE_PUSHER_PATH

# Copy package files
COPY package*.json ./

# Install ALL dependencies (needed for build)
RUN npm ci --ignore-scripts

# Copy source files
COPY . .

# Build the application
RUN npm run build \
    && npm cache clean --force

# -------------------------------------------------------------------
# Stage 2: Serve with minimal Nginx Alpine
# -------------------------------------------------------------------
FROM nginx:1.29.1-alpine-slim

# Install only curl for healthchecks
RUN apk add --no-cache curl \
    && rm -rf /var/cache/apk/*

# Remove default config and files
RUN rm /etc/nginx/conf.d/default.conf \
    && rm -rf /usr/share/nginx/html/*

# Copy nginx configuration
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Copy built application from builder stage
COPY --from=builder /app/dist /usr/share/nginx/html

# Expose port
EXPOSE 80

# Start nginx
CMD ["nginx", "-g", "daemon off;"]
