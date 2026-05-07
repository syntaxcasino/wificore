#!/usr/bin/env bash
set -euo pipefail

role="${POSTGRES_ROLE:-primary}"
primary_host="${POSTGRES_PRIMARY_HOST:-wificore-postgres}"
primary_port="${POSTGRES_PRIMARY_PORT:-5432}"
repl_user="${POSTGRES_REPLICATION_USER:-replicator}"
repl_password="${POSTGRES_REPLICATION_PASSWORD:-}"
repl_slot="${POSTGRES_REPLICATION_SLOT:-wificore_replica}"
repl_app_name="${POSTGRES_REPLICATION_APPLICATION_NAME:-wificore-postgres-replica}"

if [ -z "${repl_password}" ]; then
  echo "POSTGRES_REPLICATION_PASSWORD is required" >&2
  exit 1
fi

if [ "${role}" = "replica" ]; then
  admin_user="${POSTGRES_USER:-postgres}"
  admin_password="${POSTGRES_PASSWORD:-}"

  if [ ! -s "${PGDATA}/PG_VERSION" ]; then
    rm -rf "${PGDATA:?}"/*

    export PGPASSWORD="${admin_password}"
    until pg_isready -h "${primary_host}" -p "${primary_port}" -U "${admin_user}" -d postgres >/dev/null 2>&1; do
      echo "Waiting for primary ${primary_host}:${primary_port} to accept connections..."
      sleep 2
    done

    export PGPASSWORD="${repl_password}"
    while true; do
      set +e
      basebackup_out=$(pg_basebackup \
        -h "${primary_host}" \
        -p "${primary_port}" \
        -U "${repl_user}" \
        -D "${PGDATA}" \
        -Fp \
        -Xs \
        -P \
        -R \
        -S "${repl_slot}" 2>&1)
      basebackup_rc=$?
      set -e

      if [ ${basebackup_rc} -eq 0 ]; then
        break
      fi

      echo "Waiting for replication role/pg_hba/slot on primary..."
      sleep 2
    done

    {
      echo "primary_conninfo = 'host=${primary_host} port=${primary_port} user=${repl_user} password=${repl_password} application_name=${repl_app_name}'"
      echo "primary_slot_name = '${repl_slot}'"
    } >> "${PGDATA}/postgresql.auto.conf"

    chown -R postgres:postgres "${PGDATA}"
  fi

  exec /usr/local/bin/docker-entrypoint.sh "$@"
fi

/usr/local/bin/docker-entrypoint.sh "$@" &
pg_pid=$!

# ---------------------------------------------------------------------------
# Wait for the docker-entrypoint.sh init cycle to FULLY complete.
#
# During first boot the official entrypoint:
#   1. Starts a temporary postgres to run /docker-entrypoint-initdb.d/*.sql
#   2. Shuts it down
#   3. Starts postgres for real
#
# pg_isready returns true during step 1, so we must wait until step 3.
# We detect completion by waiting for the postmaster.pid to appear, disappear
# (shutdown after init), and reappear (final start).  On subsequent boots
# (data dir already initialised) we just wait for pg_isready once.
# ---------------------------------------------------------------------------
pidfile="${PGDATA}/postmaster.pid"

if [ ! -s "${PGDATA}/PG_VERSION" ] || [ -f "${PGDATA}/.docker-entrypoint-initdb-in-progress" ]; then
  # First boot — wait for init-phase postgres to appear, then disappear, then restart
  echo "Waiting for docker-entrypoint init phase to complete..."

  # Phase 1: wait for init-phase postgres to start
  for i in $(seq 1 120); do
    [ -f "${pidfile}" ] && break
    sleep 1
  done

  # Phase 2: wait for init-phase postgres to shut down (entrypoint restarts it)
  for i in $(seq 1 120); do
    [ ! -f "${pidfile}" ] && break
    sleep 1
  done

  # Phase 3: wait for final postgres to be ready
  for i in $(seq 1 60); do
    if pg_isready -U "${POSTGRES_USER:-postgres}" -d postgres >/dev/null 2>&1; then
      break
    fi
    sleep 1
  done
else
  # Subsequent boot — just wait for pg_isready
  until pg_isready -U "${POSTGRES_USER:-postgres}" -d postgres >/dev/null 2>&1; do
    sleep 1
  done
fi

echo "PostgreSQL is running in normal mode — configuring replication..."

app_db="${POSTGRES_DB:-postgres}"
if [ -n "${app_db}" ] && [ "${app_db}" != "postgres" ]; then
  export PGPASSWORD="${POSTGRES_PASSWORD:-}"
  # Wait up to 30s for the DB to exist (entrypoint creates it)
  for i in $(seq 1 15); do
    if psql --username "${POSTGRES_USER:-postgres}" --dbname postgres -tAc "SELECT 1 FROM pg_database WHERE datname='${app_db}';" 2>/dev/null | grep -q 1; then
      break
    fi
    sleep 2
  done
  # Idempotent: only create if still missing after waiting
  if ! psql --username "${POSTGRES_USER:-postgres}" --dbname postgres -tAc "SELECT 1 FROM pg_database WHERE datname='${app_db}';" 2>/dev/null | grep -q 1; then
    echo "Database '${app_db}' not found after init wait — creating..."
    createdb --username "${POSTGRES_USER:-postgres}" "${app_db}" 2>/dev/null || \
      echo "Database '${app_db}' already exists or creation handled by entrypoint, skipping."
  fi
fi

hba_file="${PGDATA}/pg_hba.conf"
if [ -f "${hba_file}" ] && ! grep -qE "^host\s+replication\s+${repl_user}\s" "${hba_file}"; then
  echo "host replication ${repl_user} 172.70.0.0/16 scram-sha-256" >> "${hba_file}"
fi

psql -v ON_ERROR_STOP=1 \
  --username "${POSTGRES_USER:-postgres}" \
  --dbname postgres <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${repl_user}') THEN
    EXECUTE format('CREATE ROLE %I WITH REPLICATION LOGIN PASSWORD %L', '${repl_user}', '${repl_password}');
  ELSE
    EXECUTE format('ALTER ROLE %I WITH REPLICATION LOGIN PASSWORD %L', '${repl_user}', '${repl_password}');
  END IF;
END\$\$;

DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_replication_slots WHERE slot_name = '${repl_slot}') THEN
    PERFORM pg_create_physical_replication_slot('${repl_slot}');
  END IF;
END\$\$;
SQL

psql --username "${POSTGRES_USER:-postgres}" --dbname postgres -c "SELECT pg_reload_conf();" >/dev/null

wait "${pg_pid}"
