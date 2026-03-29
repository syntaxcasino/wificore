# Managing Archived Partitions - Preventing Unlimited Growth

## The Challenge

With indefinite data retention, archived partitions will accumulate over time:
- **Daily partitions** = 365 partitions/year per table
- **4 tables** (radacct, radpostauth, water_transactions, jobs)
- **Multiple tenants** = Thousands of archived partitions

**Example Growth:**
- Year 1: ~1,460 partitions (4 tables × 365 days)
- Year 5: ~7,300 partitions
- Year 10: ~14,600 partitions

---

## Solution: Multi-Tier Archive Management

We've implemented **4 strategies** to manage archived partition growth while preserving data:

```
┌─────────────────────────────────────────────────────────────┐
│  Active Data (0-90 days)                                    │
│  - Fast queries, full indexes                               │
│  - No optimization needed                                   │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│  Recent Archives (90 days - 6 months)                       │
│  - Detached but uncompressed                                │
│  - Ready for occasional queries                             │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│  TIER 1: Compressed Archives (6 months - 1 year)           │
│  - LZ4 compression (40-60% size reduction)                  │
│  - Still queryable, slightly slower                         │
│  - Automated monthly compression                            │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│  TIER 2: Cold Storage (1-2 years)                          │
│  - Moved to cheaper tablespace                              │
│  - Slower disk, lower cost                                  │
│  - Rarely accessed                                          │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│  TIER 3: External Archive (2+ years)                        │
│  - Exported to S3/Glacier                                   │
│  - Dropped from database                                    │
│  - Restore on demand if needed                              │
└─────────────────────────────────────────────────────────────┘
```

---

## Strategy 1: Compression (Recommended) 🗜️

**Reduces storage by 40-60% with minimal impact**

### Automatic Compression
```sql
-- Runs automatically on 1st of each month at 3 AM
-- Compresses partitions older than 6 months
```

### Manual Compression
```sql
-- Compress partitions older than 180 days (6 months)
SELECT * FROM compress_archived_partitions(180);
```

**Output:**
```
 schema_name | partition_name          | size_before | size_after | compression_ratio
-------------+-------------------------+-------------+------------+-------------------
 ts_123      | radacct_p2024_01_15     | 28 MB       | 12 MB      | 57.14
 ts_123      | radacct_p2024_01_16     | 29 MB       | 13 MB      | 55.17
```

**Benefits:**
- ✅ 40-60% storage reduction
- ✅ Data still queryable
- ✅ Minimal performance impact
- ✅ Automated monthly
- ✅ Reversible (can decompress if needed)

**When to use:** Always - this should be your primary strategy

---

## Strategy 2: Tiered Storage (Cost Optimization) 💰

**Move old archives to cheaper/slower disks**

### Setup Archive Tablespace
```bash
# Create directory on cheaper storage (e.g., HDD instead of SSD)
mkdir -p /mnt/archive-storage
chown postgres:postgres /mnt/archive-storage

# Create tablespace
SELECT create_archive_tablespace('archive_storage', '/mnt/archive-storage');
```

### Move Old Partitions
```sql
-- Move partitions older than 1 year to archive tablespace
SELECT * FROM move_to_archive_storage(365, 'archive_storage');
```

**Output:**
```
 schema_name | partition_name          | status
-------------+-------------------------+-------------------------
 ts_123      | radacct_p2023_01_15     | Moved to archive_storage
 ts_123      | radacct_p2023_01_16     | Moved to archive_storage
```

**Benefits:**
- ✅ Reduce cost (cheaper storage)
- ✅ Data still accessible
- ✅ Transparent to queries
- ✅ Can combine with compression

**When to use:** When archive size exceeds SSD capacity or cost becomes significant

---

## Strategy 3: External Archival (Long-term) ☁️

**Export very old data to S3/Glacier, free up database space**

### Generate Export Commands
```sql
-- Get export commands for partitions older than 2 years
SELECT * FROM generate_export_commands(730, '/var/lib/postgresql/exports');
```

**Output:**
```
 schema_name | partition_name      | export_command                                    | drop_command
-------------+---------------------+---------------------------------------------------+---------------------------
 ts_123      | radacct_p2022_01_15 | COPY ts_123.radacct_p2022_01_15 TO '/var/lib/... | DROP TABLE ts_123.radacct...
```

### Execute Export
```bash
# Export to CSV
psql -U admin -d wms_770_ts -c "COPY ts_123.radacct_p2022_01_15 TO STDOUT CSV HEADER" | gzip > radacct_2022_01_15.csv.gz

# Upload to S3
aws s3 cp radacct_2022_01_15.csv.gz s3://wificore-archive/ts_123/radacct/2022/

# Verify upload, then drop from database
psql -U admin -d wms_770_ts -c "DROP TABLE ts_123.radacct_p2022_01_15"
```

### Restore from External Archive (if needed)
```bash
# Download from S3
aws s3 cp s3://wificore-archive/ts_123/radacct/2022/radacct_2022_01_15.csv.gz .

# Restore to database
gunzip radacct_2022_01_15.csv.gz
psql -U admin -d wms_770_ts -c "CREATE TABLE ts_123.radacct_p2022_01_15 (LIKE ts_123.radacct INCLUDING ALL)"
psql -U admin -d wms_770_ts -c "\COPY ts_123.radacct_p2022_01_15 FROM 'radacct_2022_01_15.csv' CSV HEADER"
```

**Benefits:**
- ✅ Massive space savings (remove from DB)
- ✅ Very low cost (S3 Glacier)
- ✅ Data preserved indefinitely
- ✅ Restore on demand

**When to use:** For data older than 2 years that's rarely accessed

---

## Strategy 4: Optional Deletion (Last Resort) 🗑️

**If you must delete old data (compliance, cost)**

### Dry Run (Preview)
```sql
-- See what would be deleted (partitions older than 3 years)
SELECT * FROM delete_old_archives(1095, TRUE);
```

**Output:**
```
 schema_name | partition_name      | partition_date | size   | action
-------------+---------------------+----------------+--------+----------------------
 ts_123      | radacct_p2021_12_01 | 2021-12-01     | 28 MB  | Would delete (dry run)
 ts_123      | radacct_p2021_12_02 | 2021-12-02     | 29 MB  | Would delete (dry run)
```

### Execute Deletion
```sql
-- Actually delete (minimum 365 days, safety check)
SELECT * FROM delete_old_archives(1095, FALSE);
```

**Safety Features:**
- ⚠️ Minimum 365 days (cannot delete newer data)
- ⚠️ Dry run by default
- ⚠️ Explicit confirmation required
- ⚠️ Logs all deletions

**When to use:** Only if external archival is not feasible and storage cost is critical

---

## Monitoring & Recommendations

### View Archive Growth
```sql
SELECT * FROM archive_growth_stats;
```

**Output:**
```
 tenant_schema | archived_partition_count | total_archived_size | oldest_partition | newest_archived_partition | retention_days
---------------+--------------------------+---------------------+------------------+---------------------------+----------------
 ts_123        | 365                      | 12 GB               | 2023-01-01       | 2023-12-31                | 365
 ts_456        | 180                      | 6.2 GB              | 2023-07-01       | 2023-12-31                | 180
```

### Get Automated Recommendations
```sql
SELECT * FROM get_archive_recommendations();
```

**Output:**
```
 tenant_schema | recommendation                                      | potential_savings | action_command
---------------+-----------------------------------------------------+-------------------+------------------------------------------
 ts_123        | Consider compression for partitions older than 6... | 4.8 GB            | SELECT * FROM compress_archived_partiti...
 ts_456        | Archive size is manageable                          | 0 bytes           | No action needed
```

---

## Recommended Workflow

### Phase 1: Initial Setup (Once)
```sql
-- 1. Install lifecycle management (already in Dockerfile)
-- 2. Set up archive tablespace (optional)
SELECT create_archive_tablespace('archive_storage', '/mnt/archive-storage');
```

### Phase 2: Automated Monthly (Scheduled)
```sql
-- Runs automatically on 1st of each month at 3 AM
-- Compresses partitions older than 6 months
SELECT run_archive_maintenance();
```

### Phase 3: Quarterly Review (Manual)
```sql
-- Check growth and get recommendations
SELECT * FROM archive_growth_stats;
SELECT * FROM get_archive_recommendations();

-- If needed, move to tiered storage
SELECT * FROM move_to_archive_storage(365);
```

### Phase 4: Annual Cleanup (Manual)
```sql
-- Export very old data to S3
SELECT * FROM generate_export_commands(730);
-- Execute export commands (see Strategy 3)
```

---

## Storage Impact Examples

### Scenario: 10,000 sessions/day, 5 years retention

| Strategy | Year 1 | Year 3 | Year 5 | Notes |
|----------|--------|--------|--------|-------|
| **No optimization** | 4.5 GB | 13.5 GB | 22.5 GB | Linear growth |
| **+ Compression** | 4.5 GB | 8.1 GB | 13.5 GB | 40% reduction |
| **+ Tiered storage** | 4.5 GB | 8.1 GB | 13.5 GB | Same size, lower cost |
| **+ External archival** | 4.5 GB | 6.3 GB | 6.3 GB | Stable after Year 2 |

### Cost Comparison (AWS example)

| Storage Type | Cost/GB/month | 1TB/month | Notes |
|--------------|---------------|-----------|-------|
| **EBS SSD (gp3)** | $0.08 | $80 | Active data |
| **EBS HDD (st1)** | $0.045 | $45 | Tiered storage |
| **S3 Standard** | $0.023 | $23 | External archive |
| **S3 Glacier** | $0.004 | $4 | Long-term archive |

**Savings with tiered approach:**
- Active (100GB SSD): $8/month
- Compressed (200GB HDD): $9/month
- External (700GB Glacier): $2.80/month
- **Total: $19.80/month vs $80/month (75% savings)**

---

## Best Practices

### 1. Start with Compression
- Enable automated monthly compression
- Lowest effort, highest impact
- No data loss risk

### 2. Monitor Growth
- Check `archive_growth_stats` monthly
- Set up alerts for unexpected growth
- Review recommendations quarterly

### 3. Plan Tiered Storage
- Set up archive tablespace early
- Move data older than 1 year
- Use cheaper disks (HDD vs SSD)

### 4. External Archival for Compliance
- Export data older than 2 years
- Store in S3 Glacier for compliance
- Document restore procedures

### 5. Avoid Deletion Unless Necessary
- Deletion is irreversible
- Always export first
- Document retention policies

---

## Troubleshooting

### Compression Taking Too Long
```sql
-- Compress in smaller batches
SELECT * FROM compress_archived_partitions(180) LIMIT 10;
```

### Out of Disk Space
```sql
-- Immediate action: Move to tiered storage
SELECT * FROM move_to_archive_storage(180);

-- Or export oldest data
SELECT * FROM generate_export_commands(365);
```

### Query Performance on Compressed Data
```sql
-- Decompress specific partition if needed
ALTER TABLE ts_123.radacct_p2024_01_15 SET (toast_compression = 'pglz');
VACUUM FULL ts_123.radacct_p2024_01_15;
```

---

## Summary

**You now have 4 strategies to manage archived partition growth:**

1. **Compression** (40-60% reduction) - Automated monthly ✅
2. **Tiered Storage** (cost optimization) - Manual setup
3. **External Archival** (S3/Glacier) - For very old data
4. **Optional Deletion** (last resort) - With safety checks

**Recommended approach:**
- ✅ Enable automated compression (done)
- ✅ Monitor growth monthly
- ✅ Set up tiered storage when needed
- ✅ Export to S3 for data older than 2 years
- ⚠️ Avoid deletion unless absolutely necessary

**This ensures indefinite retention while keeping storage costs manageable!**
