# Partition Retention Strategy - Indefinite Data Retention

## Overview

This document explains how the partition management system retains customer data **indefinitely** while maintaining optimal query performance on active data.

## The Problem

Traditional partition retention strategies face a dilemma:
- **Drop old partitions**: Loses customer data permanently
- **Keep all partitions attached**: Degrades query performance over time

## Our Solution: Detach & Archive

We use a **hybrid approach** that provides both performance and data retention:

1. **Active Partitions** (0-90 days): Attached to main table, optimized for queries
2. **Archived Partitions** (90+ days): Detached and moved to archive schema, data preserved indefinitely

---

## How It Works

### Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Main Table (Partitioned)                  │
│  ┌──────────┬──────────┬──────────┬─────┬──────────┐       │
│  │ Today    │ Today-1  │ Today-2  │ ... │ Today-90 │       │
│  └──────────┴──────────┴──────────┴─────┴──────────┘       │
│         ↑ Active Partitions (Fast Queries)                   │
└─────────────────────────────────────────────────────────────┘
                            ↓
                    (After 90 days)
                            ↓
┌─────────────────────────────────────────────────────────────┐
│              Archive Schema (Detached Partitions)            │
│  ┌──────────┬──────────┬──────────┬─────────────────┐      │
│  │ Day-91   │ Day-92   │ Day-93   │ ... (Forever)   │      │
│  └──────────┴──────────┴──────────┴─────────────────┘      │
│         ↑ Archived Data (Preserved Indefinitely)             │
└─────────────────────────────────────────────────────────────┘
```

### Configuration

**File:** `postgres/partitioning-setup.sql`

```sql
UPDATE partman.part_config 
SET retention = '90 days',              -- Detach after 90 days
    retention_keep_table = TRUE,        -- Keep detached partitions (don't drop)
    retention_keep_index = TRUE,        -- Keep indexes on archived partitions
    retention_schema = 'archive',       -- Move to archive schema
    infinite_time_partitions = TRUE     -- Continue creating partitions forever
WHERE parent_table = v_parent_table;
```

---

## Benefits

### 1. **Performance** 🚀
- Queries on recent data (0-90 days) only scan active partitions
- Query planner ignores archived partitions automatically
- Indexes remain optimal size for active data

### 2. **Data Retention** 💾
- **ALL customer data preserved indefinitely**
- No data loss ever
- Compliance with data retention regulations
- Historical analysis always possible

### 3. **Storage Efficiency** 💰
- Archived partitions can be moved to cheaper storage (future enhancement)
- Compressed archived partitions (future enhancement)
- Clear separation between hot and cold data

### 4. **Operational Simplicity** ⚙️
- Automated via pg_cron (runs daily at 2 AM)
- No manual intervention required
- Transparent to application code

---

## Partition Lifecycle

### Phase 1: Active (0-90 days)
- **Location**: Main table (e.g., `public.radacct`)
- **Status**: Attached partition
- **Query Performance**: Optimal (indexed, recent data)
- **Use Case**: Real-time queries, dashboards, reports

### Phase 2: Archived (90+ days)
- **Location**: Archive schema (e.g., `archive.radacct_p2024_01_15`)
- **Status**: Detached (standalone table)
- **Query Performance**: Good (still indexed, but not in main table scan)
- **Use Case**: Historical analysis, compliance, audits

### Accessing Archived Data

```sql
-- Query recent data (fast - only scans active partitions)
SELECT * FROM public.radacct 
WHERE acctstarttime >= CURRENT_DATE - INTERVAL '30 days';

-- Query archived data (direct access to specific partition)
SELECT * FROM archive.radacct_p2024_01_15 
WHERE acctstarttime BETWEEN '2024-01-15' AND '2024-01-16';

-- Query across active + archived (union query)
SELECT * FROM public.radacct 
WHERE acctstarttime >= '2024-01-01'
UNION ALL
SELECT * FROM archive.radacct_p2024_01_15
WHERE acctstarttime BETWEEN '2024-01-15' AND '2024-01-16';
```

---

## Monitoring

### View Active Partitions

```sql
SELECT * FROM partition_info;
```

**Output:**
```
 schemaname |      tablename       | total_size | partition_count 
------------+----------------------+------------+-----------------
 ts_123     | radacct              | 2.5 GB     | 90
 ts_123     | radpostauth          | 450 MB     | 90
 ts_123     | water_transactions   | 1.2 GB     | 90
```

### View Archived Partitions

```sql
SELECT * FROM archived_partitions;
```

**Output:**
```
 schemaname |        tablename         |  size   | partition_count 
------------+--------------------------+---------+-----------------
 archive    | radacct_p2024_01_15      | 28 MB   | 1
 archive    | radacct_p2024_01_16      | 29 MB   | 1
 archive    | radpostauth_p2024_01_15  | 5 MB    | 1
```

### View Storage Usage

```sql
SELECT * FROM get_storage_usage();
```

**Output:**
```
 schema_name | total_size | table_count 
-------------+------------+-------------
 ts_123      | 4.2 GB     | 270
 archive     | 15.8 GB    | 1825
 public      | 120 MB     | 45
```

---

## Maintenance Schedule

**Automated via pg_cron:**
- **Frequency**: Daily at 2:00 AM
- **Actions**:
  1. Create new partitions (7 days ahead)
  2. Detach partitions older than 90 days
  3. Move detached partitions to archive schema
  4. Update statistics (ANALYZE)

**Manual execution:**
```sql
SELECT run_partition_maintenance();
```

---

## Storage Optimization (Future Enhancements)

### 1. Compression
Archive old partitions with PostgreSQL table compression:

```sql
-- Compress archived partition
ALTER TABLE archive.radacct_p2024_01_15 SET (toast_compression = 'lz4');
VACUUM FULL archive.radacct_p2024_01_15;
```

### 2. Tablespace Migration
Move archived partitions to cheaper storage:

```sql
-- Create tablespace on slower/cheaper disk
CREATE TABLESPACE archive_storage LOCATION '/mnt/archive';

-- Move archived partition
ALTER TABLE archive.radacct_p2024_01_15 SET TABLESPACE archive_storage;
```

### 3. External Archival
Export very old data to external storage (S3, Glacier):

```bash
# Export partition to CSV
psql -c "COPY archive.radacct_p2024_01_15 TO STDOUT CSV HEADER" | gzip > radacct_2024_01_15.csv.gz

# Upload to S3
aws s3 cp radacct_2024_01_15.csv.gz s3://wificore-archive/

# Drop local copy (data in S3)
DROP TABLE archive.radacct_p2024_01_15;
```

---

## Performance Characteristics

### Query Performance by Age

| Data Age | Location | Query Time (1M rows) | Notes |
|----------|----------|----------------------|-------|
| 0-7 days | Active | ~50ms | Hot data, in memory |
| 8-30 days | Active | ~100ms | Warm data, indexed |
| 31-90 days | Active | ~200ms | Cool data, indexed |
| 90+ days | Archive | ~500ms | Cold data, direct access |

### Storage Growth

**Example tenant with 10,000 daily sessions:**

| Timeframe | Active Size | Archive Size | Total |
|-----------|-------------|--------------|-------|
| Month 1 | 300 MB | 0 MB | 300 MB |
| Month 3 | 900 MB | 0 MB | 900 MB |
| Month 6 | 900 MB | 1.8 GB | 2.7 GB |
| Year 1 | 900 MB | 3.6 GB | 4.5 GB |
| Year 2 | 900 MB | 7.2 GB | 8.1 GB |
| Year 5 | 900 MB | 18 GB | 18.9 GB |

**Active table size remains constant** (~900 MB for 90 days of data)

---

## Compliance & Regulations

### Data Retention Requirements

This strategy supports various compliance requirements:

- **GDPR**: Data retained for legitimate business purposes
- **SOX**: Financial transaction data preserved indefinitely
- **HIPAA**: Healthcare data retention (if applicable)
- **Industry Standards**: Telecom/ISP data retention laws

### Data Deletion (GDPR Right to Erasure)

If a customer requests data deletion:

```sql
-- Delete from active partitions
DELETE FROM public.radacct WHERE username = 'customer@example.com';

-- Delete from archived partitions
DO $$
DECLARE
    partition_name TEXT;
BEGIN
    FOR partition_name IN 
        SELECT tablename FROM pg_tables WHERE schemaname = 'archive' AND tablename LIKE 'radacct_%'
    LOOP
        EXECUTE format('DELETE FROM archive.%I WHERE username = $1', partition_name) 
        USING 'customer@example.com';
    END LOOP;
END $$;
```

---

## Troubleshooting

### Partition Not Being Detached

```sql
-- Check retention configuration
SELECT parent_table, retention, retention_keep_table, retention_schema 
FROM partman.part_config;

-- Manually trigger maintenance
SELECT partman.run_maintenance_proc();
```

### Archive Schema Growing Too Large

```sql
-- Check archive size
SELECT pg_size_pretty(pg_database_size('wms_770_ts'));

-- Identify largest archived partitions
SELECT 
    schemaname || '.' || tablename as partition,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as size
FROM pg_tables
WHERE schemaname = 'archive'
ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC
LIMIT 20;
```

### Query Performance on Archived Data

```sql
-- Ensure indexes exist on archived partitions
SELECT schemaname, tablename, indexname 
FROM pg_indexes 
WHERE schemaname = 'archive';

-- Rebuild index if needed
REINDEX TABLE archive.radacct_p2024_01_15;
```

---

## Best Practices

### 1. Regular Monitoring
- Monitor archive schema growth weekly
- Set up alerts for unexpected growth
- Review partition count monthly

### 2. Query Optimization
- Always include date filters in queries
- Use partition-aware queries when accessing old data
- Consider materialized views for common historical queries

### 3. Backup Strategy
- Include archive schema in backups
- Consider separate backup schedule for archive (less frequent)
- Test restore procedures for archived partitions

### 4. Documentation
- Document partition naming conventions
- Maintain list of archived partitions
- Track data retention policies per table

---

## Summary

**Key Points:**
- ✅ **All customer data retained indefinitely**
- ✅ **Active queries remain fast** (only scan recent 90 days)
- ✅ **Automated maintenance** via pg_cron
- ✅ **No data loss** - partitions detached, not dropped
- ✅ **Scalable** - archive schema can grow indefinitely
- ✅ **Compliant** - meets data retention regulations
- ✅ **Cost-effective** - future optimization options available

**Trade-offs:**
- Storage grows over time (but predictably)
- Queries spanning active + archived data require unions
- Archive schema requires monitoring

**This strategy provides the best of both worlds: performance on recent data and indefinite retention of all historical data.**
