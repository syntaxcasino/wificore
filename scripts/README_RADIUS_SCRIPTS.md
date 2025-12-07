# WiFi Hotspot RADIUS Scripts

## 📝 Overview

The `list-radius-users.sh` script in this directory is **copied from livestock-management** and references tables that don't exist in wifi-hotspot (`farmers`, `employees`, `user_roles`, `roles`).

## ✅ Solution

A **wifi-hotspot specific version** has been created: `list-radius-users-hotspot.sh`

## 🔧 Usage

### List All Users (Count Only)
```bash
./list-radius-users-hotspot.sh -c
```

### List All Users (Simple View)
```bash
./list-radius-users-hotspot.sh
```

### List All Users (Detailed with Passwords)
```bash
./list-radius-users-hotspot.sh -d
```

### List Only Tenant Users
```bash
./list-radius-users-hotspot.sh -t
```

### List Only System Admins
```bash
./list-radius-users-hotspot.sh -a
```

### Search for Specific User
```bash
./list-radius-users-hotspot.sh -s username
```

## 📊 What It Shows

### System Admin Users
- Username
- Password (if `-d` flag used)
- Role (from `users.role` column)
- Active status
- Created date

### Tenant Users (Hotspot Users)
- Username
- Name
- Email
- Role (hotspot_user, admin, etc.)
- Password (if `-d` flag used)
- Account Balance
- Active status

## 🔍 Key Differences from Livestock-Management

| Feature | Livestock-Management | WiFi-Hotspot |
|---------|---------------------|--------------|
| **User Types** | Farmers & Employees | Hotspot Users |
| **Role Storage** | `user_roles` + `roles` tables | `users.role` column |
| **Tenant Tables** | `farmers`, `employees` | `users` (with `tenant_id`) |
| **User Attributes** | farmer_code, position | account_number, account_balance |

## 🛠️ Direct PostgreSQL Queries

If you prefer to query directly:

### Count All RADIUS Users
```sql
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT 
    COUNT(*) FILTER (WHERE m.username IS NULL) as system_admins,
    COUNT(*) FILTER (WHERE m.username IS NOT NULL) as tenant_users,
    COUNT(*) as total
FROM radcheck rc
LEFT JOIN radius_user_schema_mapping m ON rc.username = m.username
WHERE rc.attribute = 'Cleartext-Password';
"
```

### List All System Admin Users
```sql
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT 
    u.username,
    u.email,
    u.role,
    u.is_active,
    rc.value as password
FROM users u
LEFT JOIN radcheck rc ON u.username = rc.username AND rc.attribute = 'Cleartext-Password'
WHERE u.tenant_id IS NULL
ORDER BY u.username;
"
```

### List All Tenant Users
```sql
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT 
    t.name as tenant,
    u.username,
    u.email,
    u.role,
    u.account_balance,
    u.is_active
FROM users u
JOIN tenants t ON u.tenant_id = t.id
ORDER BY t.name, u.username;
"
```

### Check RADIUS Mapping
```sql
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT 
    username,
    schema_name
FROM radius_user_schema_mapping
ORDER BY schema_name, username;
"
```

## 📁 Script Files

- ✅ **`list-radius-users-hotspot.sh`** - WiFi Hotspot specific (USE THIS)
- ⚠️ **`list-radius-users.sh`** - Livestock-management version (DON'T USE - will error)
- ✅ **`create-radius-user.sh`** - Create new RADIUS user
- ✅ **`update-radius-password.sh`** - Update user password
- ✅ **`delete-radius-user.sh`** - Delete RADIUS user

## 🎯 Quick Commands

```bash
# Navigate to scripts directory
cd d:\traidnet\wifi-hotspot\scripts

# Count users
./list-radius-users-hotspot.sh -c

# List all with details
./list-radius-users-hotspot.sh -d

# Create new user
./create-radius-user.sh -u testuser -p password123

# Update password
./update-radius-password.sh -u testuser -p newpassword

# Delete user
./delete-radius-user.sh -u testuser
```

## ⚠️ Important Notes

1. **Schema-Based Multi-Tenancy**: Each tenant has their own schema with their own `users` and `radcheck` tables
2. **Public Schema**: System admins are in the public schema
3. **Mapping Table**: `radius_user_schema_mapping` maps usernames to tenant schemas
4. **RADIUS Functions**: PostgreSQL functions automatically determine the correct schema

## 🔄 Migration from Livestock-Management Script

If you need to update the old script, the key changes are:

```sql
-- OLD (Livestock-Management)
LEFT JOIN user_roles ur ON u.id = ur.user_id
LEFT JOIN roles r ON ur.role_id = r.id

-- NEW (WiFi-Hotspot)
-- Just use u.role directly (it's a column, not a relationship)
```

```sql
-- OLD (Livestock-Management)
FROM farmers f
FROM employees e

-- NEW (WiFi-Hotspot)
FROM users u WHERE u.tenant_id = '<tenant_id>'
```

## 📚 Related Documentation

- `LIVESTOCK_MANAGEMENT_IMPLEMENTATION.md` - Implementation details
- `IMPLEMENTATION_COMPLETE.md` - Completion summary
- `QUICK_REFERENCE.md` - Quick commands
- `MULTI_TENANT_RADIUS_ARCHITECTURE.md` - Architecture overview

---

**Created**: December 6, 2025  
**Purpose**: Clarify RADIUS script differences between livestock-management and wifi-hotspot
