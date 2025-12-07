# Quick Start - Optimized Containers

## 🚀 One-Command Rebuild

```powershell
.\rebuild-optimized.ps1
```

This script will:
1. Stop all containers
2. Remove old images
3. Build optimized containers
4. Start containers
5. Show new sizes

## 📊 Expected Results

| Container | Before | After | Savings |
|-----------|--------|-------|---------|
| Backend | 879 MB | ~90 MB | 89% |
| Soketi | 523 MB | ~45 MB | 91% |
| FreeRADIUS | 169 MB | ~28 MB | 83% |
| Frontend | 31 MB | ~27 MB | 13% |
| Nginx | 20 MB | ~18 MB | 11% |
| **Total** | **1.6 GB** | **~215 MB** | **87%** |

## ✅ Verify Optimization

```powershell
.\verify-optimization.ps1
```

Checks:
- Container sizes
- Container health
- API endpoints
- Resource usage

## 🔄 Rollback (if needed)

```powershell
.\rollback-dockerfiles.ps1
```

## 📋 Manual Commands

### Check Sizes
```powershell
docker images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}" | findstr traidnet
```

### Check Status
```powershell
docker-compose ps
```

### View Logs
```powershell
docker-compose logs -f
```

### Monitor Resources
```powershell
docker stats
```

## 🎯 Key Optimizations

1. **Alpine Linux** - All containers use Alpine base
2. **Multi-stage Builds** - Separate build/runtime stages
3. **.dockerignore** - Exclude unnecessary files
4. **Layer Optimization** - Combined commands
5. **Cache Cleaning** - Remove package caches

## 📁 What Changed

### Dockerfiles:
- `backend/Dockerfile` - Alpine + multi-stage
- `frontend/Dockerfile` - Alpine + multi-stage
- `soketi/Dockerfile` - Alpine + minimal deps
- `freeradius/Dockerfile` - Optimized layers

### .dockerignore:
- `backend/.dockerignore` - Enhanced (81 lines)
- `frontend/.dockerignore` - Enhanced (50 lines)
- `nginx/.dockerignore` - New
- `soketi/.dockerignore` - New

## ⚠️ Important Notes

- All functionality preserved
- No breaking changes
- Backups available (*.backup files)
- Production-ready
- Tested configurations

## 🔍 Troubleshooting

### Build Fails
```powershell
# Clean everything and retry
docker system prune -a
.\rebuild-optimized.ps1
```

### Container Won't Start
```powershell
# Check logs
docker-compose logs [container-name]

# Rollback if needed
.\rollback-dockerfiles.ps1
```

### Size Not Reduced
```powershell
# Ensure no-cache build
docker-compose build --no-cache
```

## 📚 Documentation

- `OPTIMIZATION_SUMMARY.md` - Full implementation details
- `CONTAINER_OPTIMIZATION.md` - Technical guide
- `docs/CONTAINER_SIZE_COMPARISON.md` - Detailed comparison

## ✨ Benefits

- **87% smaller** total stack size
- **Faster deployments** (less data transfer)
- **Faster startup** (smaller images)
- **Lower costs** (storage & bandwidth)
- **Better security** (smaller attack surface)

---

**Ready to optimize? Run:** `.\rebuild-optimized.ps1`
