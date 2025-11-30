# 🐳 DOCKER BUILD & PUSH GUIDE

**Date:** October 13, 2025  
**Purpose:** Build and push Docker images to Docker Hub  
**Scripts:** Bash (Linux/Mac) & PowerShell (Windows)

---

## 📋 OVERVIEW

Automated scripts to build and push all Docker images for the TraidNet WiFi Hotspot system to Docker Hub with proper tagging.

### **Images to Build**
1. `kja2aro/traidnet-nginx`
2. `kja2aro/traidnet-frontend`
3. `kja2aro/traidnet-backend`
4. `kja2aro/traidnet-soketi`
5. `kja2aro/traidnet-freeradius`

---

## 🚀 QUICK START

### **Linux/Mac (Bash)**
```bash
# Make script executable
chmod +x scripts/build-and-push-images.sh

# Build and push all images
./scripts/build-and-push-images.sh

# Build and push with version tag
./scripts/build-and-push-images.sh --tag v1.0.0

# Build specific service
./scripts/build-and-push-images.sh --service backend --tag v1.0.0
```

### **Windows (PowerShell)**
```powershell
# Build and push all images
.\scripts\build-and-push-images.ps1

# Build and push with version tag
.\scripts\build-and-push-images.ps1 -Tag "v1.0.0"

# Build specific service
.\scripts\build-and-push-images.ps1 -Service backend -Tag "v1.0.0"
```

---

## 📖 USAGE

### **Bash Script Options**

```bash
./build-and-push-images.sh [options]

Options:
  --tag VERSION       Specify version tag (default: latest)
  --no-cache          Build without cache
  --push-only         Skip build, only push existing images
  --build-only        Only build, don't push
  --service SERVICE   Build/push only specific service
  --help              Show help message
```

### **PowerShell Script Parameters**

```powershell
.\build-and-push-images.ps1 [parameters]

Parameters:
  -Tag VERSION       Specify version tag (default: latest)
  -NoCache           Build without cache
  -PushOnly          Skip build, only push existing images
  -BuildOnly         Only build, don't push
  -Service SERVICE   Build/push only specific service
  -Help              Show help message
```

---

## 💡 EXAMPLES

### **1. Build and Push All Images (Latest)**

**Bash:**
```bash
./scripts/build-and-push-images.sh
```

**PowerShell:**
```powershell
.\scripts\build-and-push-images.ps1
```

**Result:**
- Builds all 5 images
- Tags as `latest`
- Pushes to Docker Hub

---

### **2. Build and Push with Version Tag**

**Bash:**
```bash
./scripts/build-and-push-images.sh --tag v1.0.0
```

**PowerShell:**
```powershell
.\scripts\build-and-push-images.ps1 -Tag "v1.0.0"
```

**Result:**
- Builds all images
- Tags as `v1.0.0` AND `latest`
- Pushes both tags to Docker Hub

---

### **3. Build Specific Service**

**Bash:**
```bash
./scripts/build-and-push-images.sh --service backend --tag v1.0.1
```

**PowerShell:**
```powershell
.\scripts\build-and-push-images.ps1 -Service backend -Tag "v1.0.1"
```

**Result:**
- Builds only backend image
- Tags as `v1.0.1` AND `latest`
- Pushes to Docker Hub

---

### **4. Build Without Cache**

**Bash:**
```bash
./scripts/build-and-push-images.sh --no-cache --tag v1.1.0
```

**PowerShell:**
```powershell
.\scripts\build-and-push-images.ps1 -NoCache -Tag "v1.1.0"
```

**Result:**
- Builds from scratch (no cache)
- Ensures fresh build
- Useful for troubleshooting

---

### **5. Build Only (Don't Push)**

**Bash:**
```bash
./scripts/build-and-push-images.sh --build-only --tag test
```

**PowerShell:**
```powershell
.\scripts\build-and-push-images.ps1 -BuildOnly -Tag "test"
```

**Result:**
- Builds all images locally
- Does NOT push to Docker Hub
- Useful for testing

---

### **6. Push Only (Skip Build)**

**Bash:**
```bash
./scripts/build-and-push-images.sh --push-only --tag v1.0.0
```

**PowerShell:**
```powershell
.\scripts\build-and-push-images.ps1 -PushOnly -Tag "v1.0.0"
```

**Result:**
- Skips building
- Pushes existing local images
- Useful if images already built

---

## 🔐 PREREQUISITES

### **1. Docker Installed**
```bash
# Check Docker installation
docker --version

# Should output: Docker version 24.0.0 or higher
```

### **2. Docker Hub Account**
- Username: `kja2aro`
- Account must exist on hub.docker.com

### **3. Docker Login**
```bash
# Login to Docker Hub
docker login

# Enter username: kja2aro
# Enter password: [your password]
```

**Note:** Scripts will attempt to login if not authenticated.

### **4. Build Contexts**
Ensure these directories exist:
- `./nginx/`
- `./frontend/`
- `./backend/`
- `./soketi/`
- `./freeradius/`

Each must contain a `Dockerfile`.

---

## 📂 DIRECTORY STRUCTURE

```
wifi-hotspot/
├── scripts/
│   ├── build-and-push-images.sh   # Bash script
│   └── build-and-push-images.ps1  # PowerShell script
├── nginx/
│   └── Dockerfile
├── frontend/
│   └── Dockerfile
├── backend/
│   └── Dockerfile
├── soketi/
│   └── Dockerfile
├── freeradius/
│   └── Dockerfile
└── docker-compose -deployment.yml
```

---

## 🔄 WORKFLOW

### **Script Execution Flow**

```
1. Check Prerequisites
   ├── Docker installed?
   ├── Docker Hub login?
   └── Build contexts exist?

2. Build Phase (if not --push-only)
   ├── For each service:
   │   ├── Build image
   │   ├── Tag with version
   │   └── Tag with latest (if version != latest)
   └── Track results

3. Push Phase (if not --build-only)
   ├── For each service:
   │   ├── Push version tag
   │   └── Push latest tag (if version != latest)
   └── Track results

4. Summary
   ├── Build statistics
   ├── Push statistics
   └── Docker Hub URLs
```

---

## 📊 OUTPUT EXAMPLE

```
================================
TraidNet Docker Image Builder
================================

ℹ Docker Username: kja2aro
ℹ Tag: v1.0.0
ℹ No Cache: false
ℹ Push Only: false
ℹ Build Only: false

✓ Docker is available
✓ Authenticated with Docker Hub as kja2aro

================================
Processing 5 service(s)
================================

================================
Building: nginx
================================
ℹ Image: kja2aro/traidnet-nginx:v1.0.0
ℹ Context: ./nginx
ℹ Running: docker build -t kja2aro/traidnet-nginx:v1.0.0 -t kja2aro/traidnet-nginx:latest ./nginx
✓ Built: kja2aro/traidnet-nginx:v1.0.0

================================
Pushing: nginx
================================
ℹ Image: kja2aro/traidnet-nginx:v1.0.0
✓ Pushed: kja2aro/traidnet-nginx:v1.0.0
ℹ Also pushing: kja2aro/traidnet-nginx:latest
✓ Pushed: kja2aro/traidnet-nginx:latest

[... continues for all services ...]

================================
Summary
================================

ℹ Build Results:
✓   nginx: Built successfully
✓   frontend: Built successfully
✓   backend: Built successfully
✓   soketi: Built successfully
✓   freeradius: Built successfully

ℹ Build Statistics:
  Total: 5
  Successful: 5
  Failed: 0

ℹ Push Results:
✓   nginx: Pushed successfully
✓   frontend: Pushed successfully
✓   backend: Pushed successfully
✓   soketi: Pushed successfully
✓   freeradius: Pushed successfully

ℹ Push Statistics:
  Total: 5
  Successful: 5
  Failed: 0

================================
Docker Hub Images
================================

  https://hub.docker.com/r/kja2aro/traidnet-nginx
  https://hub.docker.com/r/kja2aro/traidnet-frontend
  https://hub.docker.com/r/kja2aro/traidnet-backend
  https://hub.docker.com/r/kja2aro/traidnet-soketi
  https://hub.docker.com/r/kja2aro/traidnet-freeradius

✓ All operations completed successfully!
```

---

## 🐛 TROUBLESHOOTING

### **Error: Docker not found**
```
✗ Docker is not installed or not in PATH
```

**Solution:**
- Install Docker Desktop
- Ensure Docker is in PATH
- Restart terminal

---

### **Error: Docker login failed**
```
✗ Docker login failed
```

**Solution:**
```bash
# Manual login
docker login

# Or use access token
docker login -u kja2aro -p YOUR_ACCESS_TOKEN
```

---

### **Error: Build context not found**
```
✗ Build context directory not found: ./backend
```

**Solution:**
- Ensure you're in project root
- Check directory exists
- Verify Dockerfile exists in directory

---

### **Error: Build failed**
```
✗ Failed to build: kja2aro/traidnet-backend:v1.0.0
```

**Solution:**
- Check Dockerfile syntax
- Review build logs
- Try with `--no-cache`
- Check dependencies

---

### **Error: Push failed**
```
✗ Failed to push: kja2aro/traidnet-backend:v1.0.0
```

**Solution:**
- Verify Docker Hub login
- Check repository exists
- Verify permissions
- Check network connection

---

## 🔧 ADVANCED USAGE

### **Build Multiple Specific Services**

**Bash:**
```bash
# Build backend and frontend only
for service in backend frontend; do
    ./scripts/build-and-push-images.sh --service $service --tag v1.0.0
done
```

**PowerShell:**
```powershell
# Build backend and frontend only
@("backend", "frontend") | ForEach-Object {
    .\scripts\build-and-push-images.ps1 -Service $_ -Tag "v1.0.0"
}
```

---

### **Build with Custom Docker Username**

**Edit script and change:**
```bash
# Bash
DOCKER_USERNAME="your-username"

# PowerShell
$DockerUsername = "your-username"
```

---

### **Automated CI/CD Integration**

**GitHub Actions Example:**
```yaml
name: Build and Push Docker Images

on:
  push:
    tags:
      - 'v*'

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      
      - name: Login to Docker Hub
        uses: docker/login-action@v1
        with:
          username: ${{ secrets.DOCKER_USERNAME }}
          password: ${{ secrets.DOCKER_PASSWORD }}
      
      - name: Build and Push
        run: |
          chmod +x scripts/build-and-push-images.sh
          ./scripts/build-and-push-images.sh --tag ${GITHUB_REF#refs/tags/}
```

---

## 📝 VERSIONING STRATEGY

### **Recommended Tags**

**Development:**
- `latest` - Latest development build
- `dev` - Development branch
- `test` - Testing builds

**Staging:**
- `staging` - Staging environment
- `rc-1.0.0` - Release candidate

**Production:**
- `v1.0.0` - Semantic versioning
- `v1.0.1` - Patch releases
- `v1.1.0` - Minor releases
- `v2.0.0` - Major releases

### **Example Workflow**

```bash
# Development
./scripts/build-and-push-images.sh --tag dev

# Release Candidate
./scripts/build-and-push-images.sh --tag rc-1.0.0

# Production Release
./scripts/build-and-push-images.sh --tag v1.0.0

# Hotfix
./scripts/build-and-push-images.sh --tag v1.0.1
```

---

## 🔒 SECURITY BEST PRACTICES

### **1. Use Access Tokens**
Instead of password, use Docker Hub access token:
```bash
docker login -u kja2aro -p YOUR_ACCESS_TOKEN
```

### **2. Store Credentials Securely**
- Use environment variables
- Use secrets management (GitHub Secrets, AWS Secrets Manager)
- Never commit credentials

### **3. Scan Images**
```bash
# Scan for vulnerabilities
docker scan kja2aro/traidnet-backend:v1.0.0
```

### **4. Sign Images**
```bash
# Enable Docker Content Trust
export DOCKER_CONTENT_TRUST=1
docker push kja2aro/traidnet-backend:v1.0.0
```

---

## ✅ CHECKLIST

### **Before Building**
- [ ] Docker installed and running
- [ ] Logged in to Docker Hub
- [ ] All Dockerfiles exist
- [ ] Code changes committed
- [ ] Version tag decided

### **After Building**
- [ ] All images built successfully
- [ ] All images pushed successfully
- [ ] Images visible on Docker Hub
- [ ] Tags correct (version + latest)
- [ ] Test pull images

### **Verification**
```bash
# Pull and test images
docker pull kja2aro/traidnet-backend:v1.0.0
docker run --rm kja2aro/traidnet-backend:v1.0.0 php --version

# Check image size
docker images | grep traidnet

# View image history
docker history kja2aro/traidnet-backend:v1.0.0
```

---

## 📚 RESOURCES

**Docker Hub:**
- https://hub.docker.com/u/kja2aro

**Documentation:**
- Docker Build: https://docs.docker.com/engine/reference/commandline/build/
- Docker Push: https://docs.docker.com/engine/reference/commandline/push/
- Docker Hub: https://docs.docker.com/docker-hub/

**Scripts Location:**
- Bash: `scripts/build-and-push-images.sh`
- PowerShell: `scripts/build-and-push-images.ps1`

---

## ✅ STATUS

**Scripts:** READY ✅  
**Bash:** Tested ✅  
**PowerShell:** Tested ✅  
**Documentation:** Complete ✅  
**Ready to Use:** YES ✅

---

**Happy Building! 🐳🚀**

---

*Last Updated: October 13, 2025*
