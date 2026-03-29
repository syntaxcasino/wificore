# PostgreSQL Read Replica - Commands Used

This document captures the exact commands that were used during implementation, rebuild, and validation of the PostgreSQL primary + read replica setup.

> Environment: Docker Compose on the same host.

## 1) Build / (Re)Create Services

### Build and recreate primary + replica + backend

```bash
docker compose up -d --build wificore-postgres wificore-postgres-replica wificore-backend
```

### Force recreate primary + replica (apply compose changes)

```bash
docker compose up -d --force-recreate wificore-postgres wificore-postgres-replica
```

### Force recreate only the replica (apply replica-only fixes)

```bash
docker compose up -d --force-recreate wificore-postgres-replica
```

### Build + recreate primary + replica (apply image changes)

```bash
docker compose up -d --build --force-recreate wificore-postgres wificore-postgres-replica
```

### Build + recreate primary + replica + backend

```bash
docker compose up -d --build --force-recreate wificore-postgres wificore-postgres-replica wificore-backend
```

## 2) Validate Compose Config

### Validate the compose file parses correctly

```bash
docker compose config -q
```

## 3) Container Status / Health

### Check container status

```bash
docker compose ps
```

### Check Postgres primary + replica status

```bash
docker compose ps wificore-postgres wificore-postgres-replica
```

## 4) Logs

### Tail primary + replica logs

```bash
docker compose logs --tail 80 wificore-postgres wificore-postgres-replica
```

### Tail primary logs

```bash
docker compose logs --tail 200 wificore-postgres
```

### Tail replica logs

```bash
docker compose logs --tail 200 wificore-postgres-replica
```

## 5) Readiness Checks

### Check primary readiness

```bash
docker compose exec -T wificore-postgres pg_isready -U admin -d wms_770_ts
```

### Check replica readiness

```bash
docker compose exec -T wificore-postgres-replica pg_isready -U admin -d wms_770_ts
```

## 6) Replication Verification (Primary)

### Confirm sync settings (for async-by-default setup)

```bash
docker compose exec -T wificore-postgres psql -U admin -d wms_770_ts -c "SHOW synchronous_commit; SHOW synchronous_standby_names;"
```

### Confirm the replica is streaming (and whether it’s async/sync)

```bash
docker compose exec -T wificore-postgres psql -U admin -d wms_770_ts -c "SELECT application_name, client_addr, state, sync_state FROM pg_stat_replication;"
```

### Check replication slots on the primary

```bash
docker compose exec -T wificore-postgres psql -U admin -d wms_770_ts -c "SELECT slot_name, slot_type, active FROM pg_replication_slots ORDER BY slot_name;"
```

## 7) Replication Verification (Replica)

### Confirm the replica is in recovery

```bash
docker compose exec -T wificore-postgres-replica psql -U admin -d wms_770_ts -c "SELECT pg_is_in_recovery() AS in_recovery;"
```

### Confirm WAL receiver is streaming (PostgreSQL 17 columns)

```bash
docker compose exec -T wificore-postgres-replica psql -U admin -d wms_770_ts -c "SELECT status, sender_host, slot_name, written_lsn, flushed_lsn, latest_end_lsn FROM pg_stat_wal_receiver;"
```

## 8) Notes

- The replica may temporarily report `health: starting` while it is bootstrapping (e.g., during initial `pg_basebackup`).
- Some Postgres parameters must be >= the primary’s values on the replica (example: `max_locks_per_transaction`).
