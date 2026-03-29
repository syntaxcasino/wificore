# Production Deployment Guide - Ubuntu Server

## Overview
This guide covers deploying the WifiCore application to an Ubuntu production server with proper logging, queue processing, and monitoring.

## Prerequisites
- Ubuntu 20.04+ server
- Docker and Docker Compose installed
- Git installed
- Domain configured (e.g., wificore.traidsolutions.com)
- SSL certificates (Let's Encrypt recommended)

## Deployment Steps

### 1. Clone Repository
```bash
cd /opt
git clone https://github.com/kja2aro/wificore.git
cd wificore
```

### 2. Configure Environment Variables
```bash
# Copy example environment files
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env

# Edit backend/.env with production values
nano backend/.env
```

**Critical Backend Environment Variables:**
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://wificore.traidsolutions.com

DB_CONNECTION=pgsql
DB_HOST=wificore-postgres
DB_PORT=5432
DB_DATABASE=wificore
DB_USERNAME=wificore
DB_PASSWORD=<strong-password>

BROADCAST_DRIVER=pusher
QUEUE_CONNECTION=database

PUSHER_APP_ID=wificore
PUSHER_APP_KEY=wificore-key
PUSHER_APP_SECRET=wificore-secret
PUSHER_HOST=wificore-soketi
PUSHER_PORT=6071
PUSHER_SCHEME=http

MAIL_MAILER=smtp
MAIL_HOST=<your-smtp-host>
MAIL_PORT=587
MAIL_USERNAME=<your-smtp-username>
MAIL_PASSWORD=<your-smtp-password>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@wificore.traidsolutions.com
MAIL_FROM_NAME="WifiCore"

LOG_CHANNEL=stack
LOG_LEVEL=info
```

**Frontend Environment Variables:**
```env
VITE_APP_URL=https://wificore.traidsolutions.com
VITE_API_URL=https://wificore.traidsolutions.com/api
VITE_PUSHER_APP_KEY=wificore-key
VITE_PUSHER_HOST=wificore.traidsolutions.com
VITE_PUSHER_PORT=443
VITE_PUSHER_SCHEME=wss
```

### 3. Build and Deploy Containers
```bash
# Build all containers
docker-compose build

# Start services
docker-compose up -d

# Check container status
docker-compose ps
```

### 4. Verify Logging
```bash
# Check if log file exists and has correct permissions
docker exec wificore-backend ls -la /var/www/html/storage/logs/

# Expected output:
# -rw-rw-r-- 1 www-data www-data <size> <date> laravel.log

# View logs
docker exec wificore-backend tail -f /var/www/html/storage/logs/laravel.log
```

### 5. Verify Queue Workers
```bash
# Check supervisor status
docker exec wificore-backend supervisorctl status

# Should show all queue workers running, including:
# laravel-queue-tenant-management    RUNNING   pid 80, uptime 0:05:23
# laravel-queue-emails               RUNNING   pid 39, uptime 0:05:23

# View tenant-management queue logs
docker exec wificore-backend tail -f /var/www/html/storage/logs/tenant-management-queue.log
```

### 6. Test Registration Flow
```bash
# 1. Register a new tenant through the UI
# 2. Check registration created
docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
\$reg = DB::table('tenant_registrations')->latest()->first();
echo 'Latest registration: ' . \$reg->tenant_email . ' - Status: ' . \$reg->status . PHP_EOL;
"

# 3. Check verification email sent
# 4. Click verification link
# 5. Monitor logs in real-time
docker exec wificore-backend tail -f /var/www/html/storage/logs/laravel.log | grep -i "verif\|workspace\|credentials"
```

## Verification Flow Architecture

### Step-by-Step Process
1. **User Registers** → `TenantRegistration` created with status `pending`
2. **SendVerificationEmailJob** dispatched → Email sent, status → `email_sent`
3. **User Clicks Verify Link** → `/api/register/verify/{token}` endpoint called
4. **Email Verified** → Status → `verified`, `TenantEmailVerified` event broadcast
5. **CreateTenantWorkspaceJob** dispatched to `tenant-management` queue
6. **Queue Worker Processes Job**:
   - Creates `Tenant` record
   - Creates admin `User`
   - Creates RADIUS credentials in tenant schema
   - Creates schema mapping
   - Runs tenant migrations
   - Updates registration with `tenant_id`, `user_id`, credentials
7. **SendCredentialsEmailJob** dispatched to `emails` queue
8. **Credentials Email Sent** → Status → `completed`

### Event Broadcasting
- `TenantEmailVerified` → Frontend updates to step 3
- `TenantWorkspaceCreating` → Frontend shows "Creating workspace..."
- `TenantWorkspaceCreated` → Frontend shows "Workspace created"
- `TenantCredentialsSent` → Frontend shows success
- `TenantRegistrationCompleted` → Frontend redirects to login

## Monitoring and Troubleshooting

### Check Logs
```bash
# Laravel application logs
docker exec wificore-backend tail -n 100 /var/www/html/storage/logs/laravel.log

# Queue worker logs
docker exec wificore-backend tail -n 50 /var/www/html/storage/logs/tenant-management-queue.log
docker exec wificore-backend tail -n 50 /var/www/html/storage/logs/emails-queue.log

# Nginx access logs
docker exec wificore-nginx tail -n 50 /var/log/nginx/access.log

# Nginx error logs
docker exec wificore-nginx tail -n 50 /var/log/nginx/error.log

# Soketi WebSocket logs
docker logs wificore-soketi --tail 50
```

### Check Queue Status
```bash
# Pending jobs
docker exec wificore-backend php artisan queue:work --once --queue=tenant-management

# Failed jobs
docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
echo 'Failed jobs: ' . DB::table('failed_jobs')->count() . PHP_EOL;
"

# View failed jobs
docker exec wificore-backend php artisan queue:failed
```

### Restart Queue Workers
```bash
# Restart all queue workers
docker exec wificore-backend supervisorctl restart all

# Restart specific queue worker
docker exec wificore-backend supervisorctl restart laravel-queue-tenant-management
```

### Common Issues and Solutions

#### Issue: Log file not created
**Solution:**
```bash
# Recreate log file with correct permissions
docker exec wificore-backend touch /var/www/html/storage/logs/laravel.log
docker exec wificore-backend chown www-data:www-data /var/www/html/storage/logs/laravel.log
docker exec wificore-backend chmod 664 /var/www/html/storage/logs/laravel.log
```

#### Issue: Queue workers not processing jobs
**Solution:**
```bash
# Check supervisor status
docker exec wificore-backend supervisorctl status

# Restart supervisor
docker exec wificore-backend supervisorctl restart all

# Check queue worker logs for errors
docker exec wificore-backend tail -n 100 /var/www/html/storage/logs/tenant-management-queue-error.log
```

#### Issue: Verification succeeds but workspace not created
**Solution:**
```bash
# Check if job was dispatched
docker exec wificore-backend tail -n 50 /var/www/html/storage/logs/laravel.log | grep "Workspace creation job dispatched"

# Check if job failed
docker exec wificore-backend php artisan queue:failed

# Retry failed job
docker exec wificore-backend php artisan queue:retry <job-id>

# Check job processing
docker exec wificore-backend tail -f /var/www/html/storage/logs/tenant-management-queue.log
```

#### Issue: Credentials email not sent
**Solution:**
```bash
# Check SMTP configuration
docker exec wificore-backend php artisan tinker
>>> Mail::raw('Test', function($msg) { $msg->to('test@example.com')->subject('Test'); });

# Check email queue logs
docker exec wificore-backend tail -n 50 /var/www/html/storage/logs/emails-queue.log

# Check failed jobs
docker exec wificore-backend php artisan queue:failed | grep SendCredentialsEmailJob
```

## Production Maintenance

### Daily Checks
```bash
# Check container health
docker-compose ps

# Check disk space
df -h

# Check logs for errors
docker exec wificore-backend grep -i "error\|exception\|failed" /var/www/html/storage/logs/laravel.log | tail -n 20
```

### Weekly Tasks
```bash
# Rotate logs (automatic via supervisor)
# Clean old failed jobs
docker exec wificore-backend php artisan queue:prune-failed --hours=168

# Update containers
cd /opt/wificore
git pull origin main
docker-compose build
docker-compose up -d
```

### Backup Strategy
```bash
# Backup database
docker exec wificore-postgres pg_dump -U wificore wificore > backup-$(date +%Y%m%d).sql

# Backup tenant schemas
docker exec wificore-postgres pg_dump -U wificore wificore --schema='ts_*' > tenant-schemas-$(date +%Y%m%d).sql

# Backup environment files
tar -czf env-backup-$(date +%Y%m%d).tar.gz backend/.env frontend/.env
```

## Performance Tuning

### Queue Workers
- **tenant-management**: 1 worker, 256MB memory, 180s timeout (workspace creation)
- **emails**: 2 workers, 128MB memory, 120s timeout (email sending)
- **default**: 1 worker, 128MB memory, 90s timeout (general tasks)

### Scaling Queue Workers
Edit `backend/supervisor/laravel-queue.conf`:
```ini
[program:laravel-queue-tenant-management]
numprocs=2  # Increase from 1 to 2 for higher load
```

Restart supervisor:
```bash
docker-compose restart wificore-backend
```

## Security Checklist
- [ ] APP_DEBUG=false in production
- [ ] Strong database passwords
- [ ] HTTPS enabled with valid SSL certificate
- [ ] Firewall configured (ports 80, 443, 1812, 1813 only)
- [ ] Regular security updates
- [ ] Log monitoring enabled
- [ ] Backup strategy implemented
- [ ] Environment files not in git
- [ ] CORS properly configured
- [ ] Rate limiting enabled

## Support and Debugging

### Enable Debug Mode (Temporarily)
```bash
# Edit backend/.env
APP_DEBUG=true
LOG_LEVEL=debug

# Restart backend
docker-compose restart wificore-backend

# IMPORTANT: Disable after debugging
APP_DEBUG=false
LOG_LEVEL=info
```

### Collect Diagnostic Information
```bash
# Run diagnostic script
cd /opt/wificore
./production-diagnostics.sh > diagnostics-$(date +%Y%m%d-%H%M%S).log

# Share the log file for support
```

## Conclusion
This deployment ensures:
- ✅ Proper logging with file permissions
- ✅ Queue workers processing jobs reliably
- ✅ Failed job tracking and recovery
- ✅ Real-time event broadcasting
- ✅ Complete registration flow from verify to credentials
- ✅ Production-ready monitoring and troubleshooting
