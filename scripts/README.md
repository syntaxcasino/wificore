# 🛠️ WiFi Hotspot Management Scripts

This directory contains utility scripts for managing the WiFi Hotspot Management System.

**Available for:**
- 🪟 **Windows** - PowerShell scripts (`.ps1`)
- 🐧 **Linux/Mac** - Bash scripts (`.sh`)

---

## 📦 Script Categories

### 1. 🐳 Docker Image Management
Build and push Docker images to Docker Hub.

### 2. 🔐 RADIUS User Management
Manage user authentication for the hotspot system.

---

## 🐳 DOCKER IMAGE SCRIPTS

### Build and Push Images

**Purpose:** Build all Docker images and push to Docker Hub (kja2aro).

**Windows (PowerShell):**
```powershell
# Build and push all images
.\build-and-push-images.ps1

# Build with version tag
.\build-and-push-images.ps1 -Tag "v1.0.0"

# Build specific service
.\build-and-push-images.ps1 -Service backend -Tag "v1.0.0"

# Build without cache
.\build-and-push-images.ps1 -NoCache -Tag "v1.0.0"

# Build only (don't push)
.\build-and-push-images.ps1 -BuildOnly

# Push only (skip build)
.\build-and-push-images.ps1 -PushOnly
```

**Linux/Mac (Bash):**
```bash
# Make executable first
chmod +x build-and-push-images.sh

# Build and push all images
./build-and-push-images.sh

# Build with version tag
./build-and-push-images.sh --tag v1.0.0

# Build specific service
./build-and-push-images.sh --service backend --tag v1.0.0

# Build without cache
./build-and-push-images.sh --no-cache --tag v1.0.0

# Build only (don't push)
./build-and-push-images.sh --build-only

# Push only (skip build)
./build-and-push-images.sh --push-only
```

**Images Built:**
- `kja2aro/traidnet-nginx`
- `kja2aro/traidnet-frontend`
- `kja2aro/traidnet-backend`
- `kja2aro/traidnet-soketi`
- `kja2aro/traidnet-freeradius`

**See:** `DOCKER_BUILD_PUSH_GUIDE.md` for detailed documentation.

---

## 🔐 RADIUS USER MANAGEMENT SCRIPTS

These scripts help you manage users for the WiFi Hotspot Management System.

## 🔐 Authentication Flow

1. **User logs in** at `http://localhost/login` with username/password
2. **Backend validates** credentials against FreeRADIUS server
3. **FreeRADIUS checks** the PostgreSQL `radcheck` table
4. **On success**, Laravel creates/updates local user and issues **Sanctum token**
5. **Token is used** for API authentication and private broadcasting channels

---

## 📝 Available Scripts

### 1. Create New User

**Windows (PowerShell):**
```powershell
.\create-radius-user.ps1 -Username "john" -Password "secret123"
```

**Linux (Bash):**
```bash
./create-radius-user.sh -u john -p secret123
```

**Example:**
```powershell
# Windows
cd d:\traidnet\wifi-hotspot\scripts
.\create-radius-user.ps1 -Username "admin" -Password "admin123"

# Linux
cd /path/to/wifi-hotspot/scripts
chmod +x create-radius-user.sh
./create-radius-user.sh -u admin -p admin123
```

### 2. List All Users

**Windows (PowerShell):**
```powershell
.\list-radius-users.ps1
```

**Linux (Bash):**
```bash
# Simple list
./list-radius-users.sh

# Detailed view (with passwords)
./list-radius-users.sh -d

# Count only
./list-radius-users.sh -c

# Search for specific user
./list-radius-users.sh -s john
```

**Output:**
```
╔════════════════════════════════════════════════════════╗
║           RADIUS Users - WiFi Hotspot System           ║
╚════════════════════════════════════════════════════════╝

ID  | Username          | Password Status
----+-------------------+------------------
1   | admin             | ********
2   | john              | ********

Total: 2 user(s)
```

### 3. Update User Password

**Windows (PowerShell):**
```powershell
.\update-radius-password.ps1 -Username "admin" -Password "newpassword456"
```

**Linux (Bash):**
```bash
./update-radius-password.sh -u admin -p newpassword456
```

### 4. Delete User

**Windows (PowerShell):**
```powershell
.\delete-radius-user.ps1 -Username "john"
```

**Linux (Bash):**
```bash
# With confirmation prompt
./delete-radius-user.sh -u john

# Skip confirmation (force delete)
./delete-radius-user.sh -u john -f
```

---

## 🐧 Linux Setup

Before using the bash scripts on Linux, make them executable:

```bash
cd /path/to/wifi-hotspot/scripts

# Make all scripts executable
chmod +x *.sh

# Or individually
chmod +x create-radius-user.sh
chmod +x delete-radius-user.sh
chmod +x list-radius-users.sh
chmod +x update-radius-password.sh
```

---

## 🚀 Quick Start - Create Your First Admin User

**Already created for you:**
- **Username:** `admin`
- **Password:** `admin123`

**Login at:** http://localhost/login

---

## 🔧 Manual Database Access (Advanced)

If you prefer to manage users directly via SQL:

### Connect to PostgreSQL:

**Windows (PowerShell):**
```powershell
docker exec -it traidnet-postgres psql -U admin -d wifi_hotspot
```

**Linux (Bash):**
```bash
docker exec -it traidnet-postgres psql -U admin -d wifi_hotspot
```

### Create User:
```sql
INSERT INTO radcheck (username, attribute, op, value) 
VALUES ('username', 'Cleartext-Password', ':=', 'password');
```

### View All Users:
```sql
SELECT * FROM radcheck;
```

### Update Password:
```sql
UPDATE radcheck 
SET value='newpassword' 
WHERE username='admin' AND attribute='Cleartext-Password';
```

### Delete User:
```sql
DELETE FROM radcheck WHERE username='username';
```

### Exit PostgreSQL:
```sql
\q
```

---

## 📊 User Attributes

The `radcheck` table stores user authentication data:

| Column    | Description                              |
|-----------|------------------------------------------|
| id        | Auto-increment primary key               |
| username  | Login username (must be unique)          |
| attribute | Authentication method (Cleartext-Password)|
| op        | Operator (:= means assignment)           |
| value     | The password in cleartext                |

---

## 🔒 Security Notes

1. **Cleartext-Password** is used for simplicity in development
2. For production, consider using **MD5-Password** or **Crypt-Password**
3. Passwords are validated by FreeRADIUS, not stored in Laravel
4. Sanctum tokens are issued after successful RADIUS authentication
5. Tokens have specific abilities: `read-routers`, `read-router-status`, `read-notifications`

---

## 🎯 What Happens After User Creation

1. **User created in RADIUS** → Can authenticate
2. **First login** → Laravel creates local user record
3. **Sanctum token issued** → Stored in browser localStorage
4. **Token used for:**
   - API requests to protected routes
   - Private broadcasting channel authentication
   - Real-time updates via Soketi

---

## 🧪 Testing Authentication

1. **Create a test user:**
   ```powershell
   .\create-radius-user.ps1 -Username "testuser" -Password "test123"
   ```

2. **Login at:** http://localhost/login

3. **Check browser console** for token:
   ```javascript
   localStorage.getItem('authToken')
   ```

4. **Verify user in Laravel database:**
   ```powershell
   docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT * FROM users;"
   ```

---

## 🆘 Troubleshooting

### "Invalid credentials" error:
- Check username/password in RADIUS database
- Verify FreeRADIUS container is running: `docker ps`
- Check FreeRADIUS logs: `docker logs traidnet-freeradius`

### "User already exists" error:
- Use update script instead: `.\update-radius-password.ps1`
- Or delete and recreate: `.\delete-radius-user.ps1` then `.\create-radius-user.ps1`

### Can't access protected routes:
- Check if token exists: `localStorage.getItem('authToken')`
- Try logging out and logging in again
- Clear browser cache and localStorage

---

## 📞 Support

For issues or questions, check:
- Backend logs: `docker logs traidnet-backend`
- FreeRADIUS logs: `docker logs traidnet-freeradius`
- Frontend console: Browser DevTools → Console tab
