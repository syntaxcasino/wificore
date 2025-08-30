#FROM node:20-slim
#  WORKDIR /app
#  COPY package.json .
#  RUN npm cache clean --force
#  RUN npm install
#  COPY . .
#  CMD ["npm", "run", "dev"]

# Stage 1: Build Vue app
FROM node:20-slim AS builder

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .
RUN npm run build

# Stage 2: Serve with Nginx
FROM nginx:stable-alpine

# Remove default config
RUN rm /etc/nginx/conf.d/default.conf

# Copy nginx.conf from the build context
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Copy the build output
COPY --from=builder /app/dist /usr/share/nginx/html

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]