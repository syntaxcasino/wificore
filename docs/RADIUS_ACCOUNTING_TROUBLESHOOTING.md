# RADIUS Accounting - End-to-End Testing & Troubleshooting Guide

## Table of Contents
1. [System Overview](#system-overview)
2. [End-to-End Test Results](#end-to-end-test-results)
3. [Common Issues & Solutions](#common-issues--solutions)
4. [Troubleshooting Steps](#troubleshooting-steps)
5. [Verification Commands](#verification-commands)
6. [Database Schema](#database-schema)
7. [Configuration Files](#configuration-files)

---

## System Overview

### Architecture
```
NAS/Router → FreeRADIUS (Port 1813) → PostgreSQL (radacct table)
```

### Components
- **FreeRADIUS**: Accounting server listening on port 1813 (UDP)
- **PostgreSQL**: Database storing accounting records in `radacct` table
- **Docker Containers**: 
  - `traidnet-freeradius`: FreeRADIUS server
  - `traidnet-postgres`: PostgreSQL database

### Accounting Packet Types
1. **Start**: Session begins (Acct-Status-Type = Start)
2. **Interim-Update**: Periodic updates during session
3. **Stop**: Session ends (Acct-Status-Type = Stop)
4. **Accounting-On/Off**: NAS reboot notifications

---

## End-to-End Test Results

### ✅ Test Summary (Completed: 2025-10-05 10:58)

| Test Case | Status | Details |
|-----------|--------|---------|
| FreeRADIUS Listening | ✅ PASS | Port 1813 (IPv4 & IPv6) active |
| Database Connection | ✅ PASS | PostgreSQL accessible |
| SQL Module Loaded | ✅ PASS | rlm_sql_postgresql loaded |
| Accounting Start | ✅ PASS | Session created in database |
| Interim Update | ✅ PASS | Session data updated |
| Accounting Stop | ✅ PASS | Session closed with statistics |
| Data Integrity | ✅ PASS | All fields populated correctly |

### Test Data
```
Username: testuser
Session ID: test-session-001
NAS IP: 192.168.1.1
Duration: 600 seconds (10 minutes)
Input: 5,242,880 bytes (5 MB)
Output: 10,485,760 bytes (10 MB)
Terminate Cause: User-Request
```

### Database Verification
```sql
SELECT username, acctsessionid, acctstarttime, acctstoptime, 
       acctsessiontime, acctinputoctets, acctoutputoctets, acctterminatecause 
FROM radacct 
WHERE username = 'testuser';
```

**Result:**
```
 username |  acctsessionid   |     acctstarttime      |      acctstoptime      | acctsessiontime | acctinputoctets | acctoutputoctets | acctterminatecause 
----------+------------------+------------------------+------------------------+-----------------+-----------------+------------------+--------------------
 testuser | test-session-001 | 2025-10-05 10:57:52+03 | 2025-10-05 10:58:19+03 |             600 |         5242880 |         10485760 | User-Request
```

---

## Common Issues & Solutions

### Issue 1: No Accounting Response from FreeRADIUS

**Symptoms:**
- `radclient` shows "No reply from server"
- Accounting packets sent but no response received
- Sessions not appearing in database

**Root Causes:**
1. **Missing Accounting Configuration in SQL Module**
2. **Column Mismatch Between Queries and Database Schema**
3. **Database Connection Failure**

**Solution:**

#### Step 1: Add Accounting Configuration to SQL Module
Edit `/opt/etc/raddb/mods-available/sql` and add:

```conf
accounting {
    reference = "%{tolower:type.%{Acct-Status-Type}.query}"
    
    type {
        accounting-on {
            query = "${....accounting_onoff_query}"
        }
        accounting-off {
            query = "${....accounting_onoff_query}"
        }
        start {
            query = "${....accounting_start_query}"
        }
        interim-update {
            query = "${....accounting_update_query}"
        }
        stop {
            query = "${....accounting_stop_query}"
        }
    }
}
```

#### Step 2: Fix Column Mismatch
The default FreeRADIUS queries include `nasporttype` column which doesn't exist in our schema.

**Remove from queries:**
```bash
docker exec traidnet-freeradius sh -c "sed -i '/nasporttype,/d' /opt/etc/raddb/mods-config/sql/main/postgresql/queries.conf"
docker exec traidnet-freeradius sh -c "sed -i '/NAS-Port-Type/d' /opt/etc/raddb/mods-config/sql/main/postgresql/queries.conf"
```

**Or use custom queries** (recommended):
Copy `freeradius/queries.conf` to `/opt/etc/raddb/mods-config/sql/main/postgresql/queries.conf`

```bash
docker cp freeradius/queries.conf traidnet-freeradius:/opt/etc/raddb/mods-config/sql/main/postgresql/queries.conf
docker restart traidnet-freeradius
```

---

### Issue 2: SQL Query Errors

**Symptoms:**
```
rlm_sql_postgresql: Status: PGRES_FATAL_ERROR
ERROR: column "nasporttype" of relation "radacct" does not exist
```

**Solution:**
The database schema doesn't include `nasporttype`. Update queries to match schema.

**Custom Query (accounting_start_query):**
```sql
INSERT INTO radacct 
    (acctsessionid, acctuniqueid, username, 
    realm, nasipaddress, nasportid, 
    acctstarttime, acctupdatetime, 
    acctstoptime, acctsessiontime, acctauthentic, 
    connectinfo_start, connectinfo_stop, acctinputoctets, 
    acctoutputoctets, calledstationid, callingstationid, 
    acctterminatecause, servicetype, framedprotocol, 
    framedipaddress) 
VALUES 
    ('%{Acct-Session-Id}', 
    '%{Acct-Unique-Session-Id}', 
    '%{SQL-User-Name}', 
    '%{Realm}', 
    '%{%{NAS-IPv6-Address}:-%{NAS-IP-Address}}', 
    '%{%{NAS-Port-ID}:-%{NAS-Port}}', 
    TO_TIMESTAMP(%{integer:Event-Timestamp}), 
    TO_TIMESTAMP(%{integer:Event-Timestamp}), 
    NULL, 
    0, 
    '%{Acct-Authentic}', 
    '%{Connect-Info}', 
    '', 
    0, 
    0, 
    '%{Called-Station-Id}', 
    '%{Calling-Station-Id}', 
    '', 
    '%{Service-Type}', 
    '%{Framed-Protocol}', 
    NULLIF('%{Framed-IP-Address}', '')::inet)
```

---

### Issue 3: Database Connection Failure

**Symptoms:**
```
rlm_sql (sql): Attempting to connect to database "wifi_hotspot"
[No connection established]
```

**Diagnosis:**
```bash
# Check PostgreSQL is running
docker exec traidnet-postgres pg_isready -U admin -d wifi_hotspot

# Test connection from FreeRADIUS container
docker exec traidnet-freeradius sh -c "PGPASSWORD=secret psql -h traidnet-postgres -U admin -d wifi_hotspot -c 'SELECT 1'"
```

**Solution:**
1. Verify database credentials in `/opt/etc/raddb/mods-available/sql`:
   ```conf
   server = "traidnet-postgres"
   port = 5432
   login = "admin"
   password = "secret"
   radius_db = "wifi_hotspot"
   ```

2. Ensure PostgreSQL is accessible:
   ```bash
   docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\dt"
   ```

3. Restart FreeRADIUS:
   ```bash
   docker restart traidnet-freeradius
   ```

---

### Issue 4: Sessions Not Updating

**Symptoms:**
- Start packets work, but interim/stop don't update
- `acctstoptime` remains NULL
- Session data not updated

**Diagnosis:**
Check if `acctuniqueid` is being generated:
```bash
docker logs traidnet-freeradius --tail 50 | grep "Acct-Unique-Session-Id"
```

**Solution:**
Ensure `acct_unique` policy is enabled in `/opt/etc/raddb/sites-enabled/default`:

```conf
preacct {
    preprocess
    acct_unique  # ← Must be present
    suffix
    files
}
```

---

## Troubleshooting Steps

### Step 1: Verify FreeRADIUS is Listening

```bash
# Check if port 1813 is open
docker exec traidnet-freeradius netstat -tuln | grep 1813

# Expected output:
# udp        0      0 0.0.0.0:1813            0.0.0.0:*
# udp        0      0 :::1813                 :::*
```

### Step 2: Check FreeRADIUS Logs

```bash
# Real-time logs
docker logs -f traidnet-freeradius

# Filter for accounting
docker logs traidnet-freeradius --tail 100 | grep -i "acct\|sql"

# Check for errors
docker logs traidnet-freeradius --tail 100 | grep -i "error\|fail"
```

### Step 3: Verify Database Schema

```bash
# Check radacct table structure
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\d radacct"

# Check for existing sessions
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT COUNT(*) FROM radacct;"
```

### Step 4: Test Accounting Manually

Create test file (`test-acct-start.txt`):
```
User-Name = "testuser"
Acct-Status-Type = Start
Acct-Session-Id = "test-session-001"
NAS-IP-Address = 192.168.1.1
NAS-Port = 1
Framed-IP-Address = 10.0.0.100
Acct-Delay-Time = 0
```

Send test packet:
```bash
docker exec traidnet-freeradius sh -c "cat /tmp/test-acct-start.txt | /opt/bin/radclient -x localhost:1813 acct testing123"
```

Expected response:
```
Received Accounting-Response Id XXX from 127.0.0.1:1813 to 127.0.0.1:XXXXX length 20
```

### Step 5: Verify Data in Database

```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT * FROM radacct ORDER BY radacctid DESC LIMIT 1;"
```

---

## Verification Commands

### Quick Health Check
```bash
# All-in-one health check
echo "=== FreeRADIUS Status ===" && \
docker ps --filter "name=freeradius" --format "{{.Names}}: {{.Status}}" && \
echo -e "\n=== Port 1813 Listening ===" && \
docker exec traidnet-freeradius netstat -tuln | grep 1813 && \
echo -e "\n=== Database Connection ===" && \
docker exec traidnet-postgres pg_isready -U admin -d wifi_hotspot && \
echo -e "\n=== Accounting Sessions ===" && \
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT COUNT(*) as total_sessions, COUNT(CASE WHEN acctstoptime IS NULL THEN 1 END) as active_sessions FROM radacct;"
```

### Check Active Sessions
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT username, acctsessionid, nasipaddress, 
       acctstarttime, 
       EXTRACT(EPOCH FROM (NOW() - acctstarttime)) as duration_seconds,
       acctinputoctets, acctoutputoctets
FROM radacct 
WHERE acctstoptime IS NULL 
ORDER BY acctstarttime DESC;"
```

### Check Recent Sessions
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT username, acctsessionid, 
       acctstarttime, acctstoptime, 
       acctsessiontime, 
       acctinputoctets, acctoutputoctets, 
       acctterminatecause
FROM radacct 
ORDER BY acctstarttime DESC 
LIMIT 10;"
```

### Check Data Usage by User
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT username, 
       COUNT(*) as session_count,
       SUM(acctsessiontime) as total_time_seconds,
       SUM(acctinputoctets) as total_input_bytes,
       SUM(acctoutputoctets) as total_output_bytes,
       SUM(acctinputoctets + acctoutputoctets) as total_bytes
FROM radacct 
GROUP BY username 
ORDER BY total_bytes DESC;"
```

---

## Database Schema

### radacct Table Structure
```sql
CREATE TABLE radacct (
    radacctid BIGSERIAL PRIMARY KEY,
    acctsessionid VARCHAR(64) NOT NULL,
    acctuniqueid VARCHAR(32) NOT NULL UNIQUE,
    username VARCHAR(64),
    realm VARCHAR(64),
    nasipaddress INET NOT NULL,
    nasportid VARCHAR(15),
    acctstarttime TIMESTAMP WITH TIME ZONE,
    acctupdatetime TIMESTAMP WITH TIME ZONE,
    acctstoptime TIMESTAMP WITH TIME ZONE,
    acctinterval BIGINT,
    acctsessiontime BIGINT,
    acctauthentic VARCHAR(32),
    connectinfo_start VARCHAR(50),
    connectinfo_stop VARCHAR(50),
    acctinputoctets BIGINT DEFAULT 0,
    acctoutputoctets BIGINT DEFAULT 0,
    calledstationid VARCHAR(50),
    callingstationid VARCHAR(50),
    acctterminatecause VARCHAR(32),
    servicetype VARCHAR(32),
    framedprotocol VARCHAR(32),
    framedipaddress INET,
    acctstartdelay BIGINT DEFAULT 0,
    acctstopdelay BIGINT DEFAULT 0
);

-- Indexes for performance
CREATE INDEX radacct_acctsessionid ON radacct(acctsessionid);
CREATE INDEX radacct_acctstarttime ON radacct(acctstarttime);
CREATE INDEX radacct_acctuniqueid ON radacct(acctuniqueid);
CREATE INDEX radacct_active ON radacct(acctuniqueid) WHERE acctstoptime IS NULL;
CREATE INDEX radacct_nasipaddress ON radacct(nasipaddress);
CREATE INDEX radacct_start ON radacct(acctstarttime, username);
CREATE INDEX radacct_username ON radacct(username);
```

### Key Fields
- **radacctid**: Auto-increment primary key
- **acctsessionid**: Session ID from NAS
- **acctuniqueid**: Unique session identifier (MD5 hash)
- **username**: User identifier
- **nasipaddress**: NAS/Router IP address
- **acctstarttime**: Session start timestamp
- **acctstoptime**: Session end timestamp (NULL for active sessions)
- **acctsessiontime**: Session duration in seconds
- **acctinputoctets**: Bytes received by user
- **acctoutputoctets**: Bytes sent by user
- **acctterminatecause**: Reason for session termination

---

## Configuration Files

### 1. SQL Module Configuration
**Location:** `/opt/etc/raddb/mods-available/sql`

```conf
sql {
    driver = "rlm_sql_postgresql"
    dialect = "postgresql"

    server = "traidnet-postgres"
    port = 5432
    login = "admin"
    password = "secret"
    radius_db = "wifi_hotspot"

    # Table configuration
    acct_table1 = "radacct"
    acct_table2 = "radacct"
    postauth_table = "radpostauth"
    authcheck_table = "radcheck"
    groupcheck_table = "radgroupcheck"
    authreply_table = "radreply"
    groupreply_table = "radgroupreply"
    usergroup_table = "radusergroup"

    read_clients = yes
    read_groups = yes
    read_profiles = yes
    delete_stale_sessions = yes

    client_table = "nas"

    pool {
        start = 5
        min = 5
        max = 10
        spare = 3
        uses = 0
        lifetime = 0
        idle_timeout = 60
        retry_delay = 1
    }

    sql_user_name = "%{User-Name}"
    group_attribute = "SQL-Group"
    
    $INCLUDE ${modconfdir}/${.:name}/main/${dialect}/queries.conf
    
    # Accounting configuration
    accounting {
        reference = "%{tolower:type.%{Acct-Status-Type}.query}"
        
        type {
            accounting-on {
                query = "${....accounting_onoff_query}"
            }
            accounting-off {
                query = "${....accounting_onoff_query}"
            }
            start {
                query = "${....accounting_start_query}"
            }
            interim-update {
                query = "${....accounting_update_query}"
            }
            stop {
                query = "${....accounting_stop_query}"
            }
        }
    }
}
```

### 2. Accounting Queries
**Location:** `/opt/etc/raddb/mods-config/sql/main/postgresql/queries.conf`

See `freeradius/queries.conf` in the repository for complete queries.

### 3. Sites Configuration
**Location:** `/opt/etc/raddb/sites-enabled/default`

```conf
# Accounting section
accounting {
    detail
    sql      # ← Must be enabled
    exec
    attr_filter.accounting_response
}
```

---

## Performance Optimization

### Database Indexes
Ensure these indexes exist for optimal performance:

```sql
-- Check existing indexes
SELECT indexname, indexdef 
FROM pg_indexes 
WHERE tablename = 'radacct';

-- Add missing indexes if needed
CREATE INDEX IF NOT EXISTS radacct_username ON radacct(username);
CREATE INDEX IF NOT EXISTS radacct_nasipaddress ON radacct(nasipaddress);
CREATE INDEX IF NOT EXISTS radacct_acctstarttime ON radacct(acctstarttime);
CREATE INDEX IF NOT EXISTS radacct_active ON radacct(acctuniqueid) WHERE acctstoptime IS NULL;
```

### Connection Pooling
Adjust pool settings in SQL module based on load:

```conf
pool {
    start = 5      # Initial connections
    min = 5        # Minimum connections
    max = 20       # Maximum connections (increase for high load)
    spare = 3      # Spare connections
    uses = 0       # Connection reuse (0 = unlimited)
    lifetime = 0   # Connection lifetime (0 = unlimited)
    idle_timeout = 60  # Close idle connections after 60s
    retry_delay = 1    # Retry delay on connection failure
}
```

### Log Rotation
Accounting detail files can grow large. Configure rotation:

```bash
# Add to /etc/logrotate.d/freeradius
/opt/var/log/radius/radacct/*/detail-* {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
}
```

---

## Monitoring & Alerts

### Key Metrics to Monitor

1. **Active Sessions**
   ```sql
   SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL;
   ```

2. **Sessions Per Hour**
   ```sql
   SELECT DATE_TRUNC('hour', acctstarttime) as hour, 
          COUNT(*) as session_count
   FROM radacct 
   WHERE acctstarttime > NOW() - INTERVAL '24 hours'
   GROUP BY hour 
   ORDER BY hour DESC;
   ```

3. **Data Usage Per Hour**
   ```sql
   SELECT DATE_TRUNC('hour', acctstarttime) as hour,
          SUM(acctinputoctets + acctoutputoctets) / 1024 / 1024 as mb_used
   FROM radacct 
   WHERE acctstarttime > NOW() - INTERVAL '24 hours'
   GROUP BY hour 
   ORDER BY hour DESC;
   ```

4. **Failed Accounting Packets**
   ```bash
   docker logs traidnet-freeradius | grep -i "accounting.*fail" | wc -l
   ```

### Alert Conditions

- **No accounting responses** for > 5 minutes
- **Database connection failures**
- **Active sessions** > expected capacity
- **Disk space** for logs < 10%

---

## Backup & Recovery

### Backup Accounting Data
```bash
# Backup radacct table
docker exec traidnet-postgres pg_dump -U admin -d wifi_hotspot -t radacct > radacct_backup_$(date +%Y%m%d).sql

# Backup with compression
docker exec traidnet-postgres pg_dump -U admin -d wifi_hotspot -t radacct | gzip > radacct_backup_$(date +%Y%m%d).sql.gz
```

### Restore Accounting Data
```bash
# Restore from backup
docker exec -i traidnet-postgres psql -U admin -d wifi_hotspot < radacct_backup_20251005.sql

# Restore from compressed backup
gunzip -c radacct_backup_20251005.sql.gz | docker exec -i traidnet-postgres psql -U admin -d wifi_hotspot
```

### Archive Old Sessions
```sql
-- Archive sessions older than 90 days
CREATE TABLE radacct_archive AS 
SELECT * FROM radacct 
WHERE acctstarttime < NOW() - INTERVAL '90 days';

-- Delete archived sessions
DELETE FROM radacct 
WHERE acctstarttime < NOW() - INTERVAL '90 days';

-- Vacuum to reclaim space
VACUUM FULL radacct;
```

---

## Security Considerations

### 1. Secure Database Credentials
- Use strong passwords
- Restrict database access to FreeRADIUS container only
- Consider using PostgreSQL SSL connections

### 2. Network Security
- Restrict port 1813 to trusted NAS devices only
- Use firewall rules to block unauthorized access
- Consider IPsec for NAS-to-RADIUS communication

### 3. Data Privacy
- Implement data retention policies
- Anonymize or delete old accounting records
- Comply with GDPR/privacy regulations

### 4. Audit Logging
- Enable PostgreSQL audit logging
- Monitor for suspicious accounting patterns
- Alert on unusual data usage

---

## Support & Resources

### Log Locations
- **FreeRADIUS logs:** `/opt/var/log/radius/radius.log`
- **Accounting detail:** `/opt/var/log/radius/radacct/*/detail-*`
- **PostgreSQL logs:** Check Docker logs

### Useful Commands
```bash
# Restart FreeRADIUS
docker restart traidnet-freeradius

# View FreeRADIUS config
docker exec traidnet-freeradius radiusd -X

# Test database connection
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT version();"

# Clear test data
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "DELETE FROM radacct WHERE username = 'testuser';"
```

### References
- [FreeRADIUS Documentation](https://freeradius.org/documentation/)
- [PostgreSQL Documentation](https://www.postgresql.org/docs/)
- [RADIUS Accounting RFC 2866](https://tools.ietf.org/html/rfc2866)

---

## Changelog

| Date | Version | Changes |
|------|---------|---------|
| 2025-10-05 | 1.0 | Initial documentation after successful E2E testing |
| 2025-10-05 | 1.1 | Added troubleshooting for column mismatch issue |
| 2025-10-05 | 1.2 | Added accounting configuration section for SQL module |

---

**Document Status:** ✅ Verified and Tested  
**Last Updated:** 2025-10-05 10:58 EAT  
**Tested By:** Automated E2E Testing Suite
