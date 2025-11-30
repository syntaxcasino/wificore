# Queue Fix Scripts

Automated scripts to diagnose and fix queue issues in the WiFi Hotspot Management System.

## Available Scripts

### 🐧 Linux/Unix/Mac: `fix-queues.sh`

**Make it executable first:**
```bash
chmod +x fix-queues.sh
```

**Run the script:**
```bash
./fix-queues.sh
```

### 🪟 Windows: `fix-queues.ps1`

**Run the script:**
```powershell
.\fix-queues.ps1
```

**If you get execution policy error:**
```powershell
# Allow script execution (run as Administrator)
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser

# Then run the script
.\fix-queues.ps1
```

## What These Scripts Do

Both scripts perform the same operations:

1. **Check Current Status**
   - Diagnose failed jobs
   - Show counts by queue

2. **Ensure Migrations**
   - Run all pending migrations
   - Create queue tables if missing

3. **Test Dashboard Job**
   - Run UpdateDashboardStatsJob synchronously
   - Verify it completes without errors
   - Clear cache before testing

4. **Fix Failed Jobs**
   - Interactive prompt to retry or clear
   - Options for specific queues
   - Safe with confirmations

5. **Test Queued Job**
   - Dispatch job to queue
   - Verify it's queued correctly

6. **Queue Worker Setup**
   - Instructions for starting worker
   - Option to start automatically
   - Background process management

## Script Features

### ✅ Safety Features
- Checks prerequisites before running
- Interactive confirmations for destructive actions
- Detailed error messages with suggestions
- Exit codes for automation

### 📊 Comprehensive Output
- Color-coded messages (success, warning, error)
- Progress indicators
- Summary at the end
- Next steps guidance

### 🔧 Error Handling
- Validates PHP installation
- Checks artisan file exists
- Verifies migrations succeed
- Tests job execution

## Manual Commands

If you prefer to run commands manually:

```bash
# 1. Diagnose issues
php artisan queue:diagnose-failed

# 2. Run migrations
php artisan migrate

# 3. Test the job
php artisan test:dashboard-job --sync --clear-cache

# 4. Fix failed jobs
php artisan queue:fix

# 5. Start queue worker
php artisan queue:work --tries=3 --timeout=120
```

## Queue Worker Management

### Development

**Start worker (foreground):**
```bash
php artisan queue:work --tries=3 --timeout=120
```

**Start worker (background - Linux):**
```bash
nohup php artisan queue:work --tries=3 --timeout=120 > storage/logs/worker.log 2>&1 &
```

**Check if running:**
```bash
ps aux | grep "queue:work"
```

**Stop worker:**
```bash
# Find PID
ps aux | grep "queue:work"

# Kill process
kill <PID>
```

### Production (Supervisor)

Create `/etc/supervisor/conf.d/laravel-worker.conf`:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/backend/storage/logs/worker.log
stopwaitsecs=3600
```

**Manage with supervisor:**
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
sudo supervisorctl status
```

## Monitoring

### Check Queue Status
```bash
# View failed jobs
php artisan queue:failed

# Diagnose issues
php artisan queue:diagnose-failed

# Monitor queue sizes
php artisan queue:monitor database:dashboard,database:default --max=100
```

### Watch Logs
```bash
# Laravel logs
tail -f storage/logs/laravel.log

# Worker logs (if running in background)
tail -f storage/logs/worker.log

# Both at once
tail -f storage/logs/*.log
```

### Check System Health
- Open dashboard in browser
- Navigate to System Health widget
- Check for:
  - ✅ No failed jobs
  - ✅ All metrics loading
  - ✅ Redis stats showing
  - ✅ Log stats showing

## Troubleshooting

### Script Won't Run

**Linux/Mac:**
```bash
# Make executable
chmod +x fix-queues.sh

# Check file format (should be Unix)
file fix-queues.sh

# Convert if needed
dos2unix fix-queues.sh
```

**Windows:**
```powershell
# Check execution policy
Get-ExecutionPolicy

# Allow scripts
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
```

### Common Errors

**"PHP is not installed"**
```bash
# Install PHP
sudo apt-get install php php-cli php-mbstring php-xml
```

**"artisan file not found"**
```bash
# Make sure you're in the project root
cd /path/to/wifi-hotspot
./fix-queues.sh
```

**"Cannot connect to database"**
- Check `.env` file in backend directory
- Verify database credentials
- Ensure database server is running

**"Table 'jobs' doesn't exist"**
```bash
# Create queue tables
php artisan queue:table
php artisan queue:failed-table
php artisan migrate
```

### Job Still Failing

1. **Check detailed error:**
   ```bash
   php artisan queue:diagnose-failed --limit=1
   ```

2. **View full exception:**
   ```bash
   php artisan queue:failed
   ```

3. **Test synchronously:**
   ```bash
   php artisan test:dashboard-job --sync
   ```

4. **Check logs:**
   ```bash
   tail -100 storage/logs/laravel.log
   ```

## Environment Variables

Ensure these are set in `.env`:

```env
# Queue Configuration
QUEUE_CONNECTION=database
DB_QUEUE_CONNECTION=pgsql
DB_QUEUE_TABLE=jobs
DB_QUEUE=default
DB_QUEUE_RETRY_AFTER=90

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Redis (optional)
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

## Success Indicators

After running the script, you should see:

✅ All migrations completed
✅ Dashboard job test passed
✅ No failed jobs in queue
✅ Queue worker running
✅ Dashboard loads successfully
✅ System Health widget shows all metrics
✅ No errors in logs

## Additional Resources

- **Detailed Guide:** `QUEUE_TROUBLESHOOTING.md`
- **Manual Steps:** `QUEUE_FIX_STEPS.md`
- **Laravel Queues:** https://laravel.com/docs/queues

## Support

If issues persist after running the script:

1. Check `storage/logs/laravel.log` for detailed errors
2. Run `php artisan queue:diagnose-failed` for analysis
3. Review `QUEUE_TROUBLESHOOTING.md` for solutions
4. Ensure all prerequisites are met (PHP, database, Redis)

## Quick Reference

```bash
# Fix everything automatically
./fix-queues.sh                          # Linux/Mac
.\fix-queues.ps1                         # Windows

# Manual commands
php artisan queue:diagnose-failed        # Check status
php artisan test:dashboard-job --sync    # Test job
php artisan queue:fix                    # Fix failed jobs
php artisan queue:work                   # Start worker

# Monitoring
tail -f storage/logs/laravel.log         # Watch logs
php artisan queue:monitor                # Monitor queues
ps aux | grep "queue:work"               # Check worker
```

---

**Need help?** See `QUEUE_TROUBLESHOOTING.md` for comprehensive troubleshooting guide.
