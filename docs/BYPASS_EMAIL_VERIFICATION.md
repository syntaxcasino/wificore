# Email Verification Bypass Scripts

## 🎯 Purpose

Development and testing scripts to bypass email verification without needing to check emails.

## 📝 Available Scripts

### 1. PowerShell Script (Windows)
**File:** `scripts/bypass-email-verification.ps1`

### 2. Bash Script (Linux/Mac)
**File:** `scripts/bypass-email-verification.sh`

### 3. Environment Variable (Development)
**File:** `.env`
```env
BYPASS_EMAIL_VERIFICATION=true
```

## 🚀 Usage

### PowerShell (Windows)

```powershell
# Verify specific user by email
.\scripts\bypass-email-verification.ps1 john@example.com

# Verify specific user by username
.\scripts\bypass-email-verification.ps1 username:johndoe

# Verify all unverified users
.\scripts\bypass-email-verification.ps1 all
```

### Bash (Linux/Mac)

```bash
# Make script executable
chmod +x scripts/bypass-email-verification.sh

# Verify specific user by email
./scripts/bypass-email-verification.sh john@example.com

# Verify specific user by username
./scripts/bypass-email-verification.sh username:johndoe

# Verify all unverified users
./scripts/bypass-email-verification.sh all
```

### Environment Variable Method

Add to `.env` for development:

```env
# Bypass email verification (DEVELOPMENT ONLY!)
BYPASS_EMAIL_VERIFICATION=true
```

**⚠️ WARNING:** Remove this in production!

## 📊 Script Features

### PowerShell Script
- ✅ Connects to PostgreSQL via Docker
- ✅ Color-coded output
- ✅ Validates user exists
- ✅ Checks if already verified
- ✅ Updates `email_verified_at` timestamp
- ✅ Shows user details
- ✅ Confirmation prompt for "all"

### What It Does

```sql
-- Updates the email_verified_at column
UPDATE users 
SET email_verified_at = NOW(), 
    updated_at = NOW() 
WHERE email = 'user@example.com';
```

## 🎯 Examples

### Example 1: Verify Single User by Email

```powershell
PS> .\scripts\bypass-email-verification.ps1 admin@traidnet.com

==========================================
TraidNet - Email Verification Bypass
==========================================

Checking user with email: admin@traidnet.com
✓ Email verified successfully!
User: Admin User | admin | admin@traidnet.com

==========================================
Verification Complete!
==========================================
```

### Example 2: Verify by Username

```powershell
PS> .\scripts\bypass-email-verification.ps1 username:testadmin

==========================================
TraidNet - Email Verification Bypass
==========================================

Checking user with username: testadmin
✓ Email verified successfully!
User: Test Admin | testadmin | test@admin.com

==========================================
Verification Complete!
==========================================
```

### Example 3: Verify All Users

```powershell
PS> .\scripts\bypass-email-verification.ps1 all

==========================================
TraidNet - Email Verification Bypass
==========================================

Finding all unverified users...
Found 3 unverified user(s).

Unverified users:
 1 | John Doe    | johndoe  | john@example.com    | 2025-01-08 04:30:00
 2 | Jane Smith  | janesmith| jane@example.com    | 2025-01-08 04:31:00
 3 | Bob Wilson  | bobwilson| bob@example.com     | 2025-01-08 04:32:00

Do you want to verify all these users? (yes/no): yes
✓ All 3 user(s) verified successfully!

==========================================
Verification Complete!
==========================================
```

## 🔧 Configuration

### Database Connection

The scripts use these environment variables (with defaults):

```env
DB_HOST=localhost
DB_PORT=5432
DB_NAME=wifi_hotspot
DB_USER=postgres
DB_PASSWORD=postgres
```

### Custom Configuration

```powershell
# Set custom database credentials
$env:DB_HOST="192.168.1.100"
$env:DB_PASSWORD="mypassword"

# Run script
.\scripts\bypass-email-verification.ps1 user@example.com
```

## ⚠️ Important Notes

### Development Only
- ✅ Use for testing and development
- ❌ **DO NOT** use in production
- ❌ **DO NOT** commit bypass enabled to production

### Security
- Scripts directly modify database
- No audit trail (unlike API)
- Use with caution
- Only for trusted developers

### Production Alternative
If you need to verify users in production, use the Laravel command:

```bash
php artisan tinker

# Verify specific user
$user = User::where('email', 'user@example.com')->first();
$user->markEmailAsVerified();

# Or verify all
User::whereNull('email_verified_at')->each->markEmailAsVerified();
```

## 🧪 Testing Workflow

### Quick Test Flow

```powershell
# 1. Register new user
curl -X POST http://localhost:8000/api/register -H "Content-Type: application/json" -d '{"name":"Test","username":"test","email":"test@test.com","phone_number":"+254712345678","password":"password123","password_confirmation":"password123"}'

# 2. Bypass verification
.\scripts\bypass-email-verification.ps1 test@test.com

# 3. Login (should work now)
curl -X POST http://localhost:8000/api/login -H "Content-Type: application/json" -d '{"username":"test","password":"password123"}'
```

## 📊 Summary

**Scripts Created:** 2 (PowerShell + Bash)  
**Methods:** 3 (email, username, all)  
**Database:** Direct SQL updates  
**Safety:** Confirmation for bulk operations  

**Features:**
- ✅ Color-coded output
- ✅ User validation
- ✅ Already-verified check
- ✅ Bulk operations
- ✅ Confirmation prompts
- ✅ Error handling

**Usage:**
```powershell
# Windows
.\scripts\bypass-email-verification.ps1 user@example.com

# Linux/Mac
./scripts/bypass-email-verification.sh user@example.com

# Environment bypass
BYPASS_EMAIL_VERIFICATION=true
```

**Status:** ✅ Ready for development testing!

---

**⚠️ Remember:** Remove bypass in production!
