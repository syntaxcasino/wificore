#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# WiFiCore Database Backup & Restore Script
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${BACKUP_DIR:-${SCRIPT_DIR}/db_backups}"
COMPOSE_FILE="${SCRIPT_DIR}/docker-compose.production.yml"
POSTGRES_SERVICE="wificore-postgres"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

C_GREEN='\033[1;32m'
C_YELLOW='\033[1;33m'
C_RED='\033[1;31m'
C_CYAN='\033[1;36m'
C_RESET='\033[0m'

HAS_PV=0
if command -v pv &>/dev/null; then HAS_PV=1; fi

psql_exec() {
  docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
    bash -c "psql -q -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\""
}

# ---------------------------------------------------------------------------
# Extract data lines + schema creation + search_path from a pg_dump SQL file
# ---------------------------------------------------------------------------
extract_data_and_schemas() {
  awk '
  /^CREATE SCHEMA / {
    sub(/^CREATE SCHEMA /, "CREATE SCHEMA IF NOT EXISTS ")
    print
    next
  }
  /^SET / { print; next }
  /^COPY / { in_copy=1; print; next }
  /^\\\.$/ { in_copy=0; print; next }
  in_copy { print; next }
  /^INSERT INTO / { print; next }
  /^SELECT pg_catalog.setval/ { print; next }
  { next }
  '
}

# ---------------------------------------------------------------------------
# Progress bar
# ---------------------------------------------------------------------------
show_progress() {
  local total="$1"
  local label="${2:-Restoring}"
  awk -v total="$total" -v label="$label" '
  {
    print
    if (NR % 100 == 0 || NR == total) {
      pct = (total > 0) ? int(NR * 100 / total) : 0
      filled = int(pct * 40 / 100)
      empty = 40 - filled
      bar = ""
      for (j = 1; j <= filled; j++) bar = bar "="
      for (j = 1; j <= empty; j++) bar = bar " "
      printf "\r  %s [%s] %3d%% (%d/%d lines)", label, bar, pct, NR, total > "/dev/stderr"
    }
  }
  END {
    printf "\r  %s [========================================] 100%% (%d/%d lines) Done!\n", label, NR, total > "/dev/stderr"
  }'
}

# ---------------------------------------------------------------------------
# BACKUP
# ---------------------------------------------------------------------------
do_backup() {
  local mode="${1:-data}"
  local pgdump_opts=""
  local desc=""

  case "$mode" in
    data)
      pgdump_opts="--data-only --inserts --no-owner --no-acl"
      desc="DATA-ONLY (INSERTs, safe for overlay)"
      ;;
    full)
      pgdump_opts="--clean --if-exists --no-owner --no-acl"
      desc="FULL (DROP + CREATE + data, for fresh restore)"
      ;;
    schema)
      pgdump_opts="--schema-only --no-owner --no-acl"
      desc="SCHEMA-ONLY (no data)"
      ;;
    *)
      echo -e "${C_RED}Error: Unknown type '$mode'. Use: data, full, or schema.${C_RESET}" >&2
      exit 1
      ;;
  esac

  local backup_file="${BACKUP_DIR}/backup_${mode}_${TIMESTAMP}.sql"

  echo -e "${C_CYAN}=== WiFiCore Backup ===${C_RESET}"
  echo "  File: $(basename "$backup_file")"
  echo "  Mode: $desc"
  echo ""

  mkdir -p "$BACKUP_DIR"

  if [[ "$HAS_PV" -eq 1 ]]; then
    docker compose -f "$COMPOSE_FILE" exec "$POSTGRES_SERVICE" \
      bash -c "pg_dump -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" ${pgdump_opts}" \
      | pv -pte > "$backup_file"
  else
    echo -e "  ${C_YELLOW}Dumping...${C_RESET} (install 'pv' for progress: apt install pv)"
    docker compose -f "$COMPOSE_FILE" exec "$POSTGRES_SERVICE" \
      bash -c "pg_dump -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" ${pgdump_opts}" \
      > "$backup_file" &
    local pid=$!
    local spin='-\|/'
    local i=0
    while kill -0 "$pid" 2>/dev/null; do
      i=$(( (i + 1) % 4 ))
      printf "\r  %s" "${spin:$i:1}"
      sleep 0.3
    done
    wait "$pid"
    printf "\r  Done!       \n"
  fi

  local size
  size=$(du -h "$backup_file" | cut -f1)
  echo -e "  ${C_GREEN}Saved: $(basename "$backup_file") ($size)${C_RESET}"
}

# ---------------------------------------------------------------------------
# RESTORE
# ---------------------------------------------------------------------------
do_restore() {
  local target_file=""

  if [[ -n "${1:-}" ]]; then
    target_file="$1"
    if [[ ! -f "$target_file" ]]; then
      target_file="${BACKUP_DIR}/$1"
      if [[ ! -f "$target_file" ]]; then
        echo -e "${C_RED}  Error: File not found: $1${C_RESET}" >&2
        exit 1
      fi
    fi
  else
    target_file=$(find "$BACKUP_DIR" -maxdepth 1 -type f \
      \( -name '*.sql' -o -name '*.dump' -o -name '*.backup' \) \
      -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -1 | cut -d' ' -f2-)

    if [[ -z "$target_file" || ! -f "$target_file" ]]; then
      echo -e "${C_RED}  Error: No backups found in ${BACKUP_DIR}${C_RESET}" >&2
      exit 1
    fi
  fi

  local filename=$(basename "$target_file")
  local detected_mode="unknown"

  if [[ "$filename" == *"_data_"* ]]; then detected_mode="data"
  elif [[ "$filename" == *"_schema_"* ]]; then detected_mode="schema"
  elif [[ "$filename" == *"_full_"* ]]; then detected_mode="full"
  elif [[ "${filename##*.}" == "sql" ]]; then
    if grep -q '^DROP ' "$target_file" 2>/dev/null; then detected_mode="full"
    elif grep -q '^COPY ' "$target_file" 2>/dev/null || grep -q '^INSERT ' "$target_file" 2>/dev/null; then detected_mode="data"
    else detected_mode="schema"
    fi
  fi

  echo -e "${C_CYAN}=== WiFiCore Restore ===${C_RESET}"
  echo "  File    : $filename"
  echo "  Target  : ${POSTGRES_SERVICE}"
  echo "  Type    : ${detected_mode} backup"
  echo ""

  # ========================================================================
  # FULL backup restore
  # ========================================================================
  if [[ "$detected_mode" == "full" ]]; then
    echo "  Options:"
    echo "    1) Full restore — drops entire DB, recreates clean, restores all"
    echo "    2) Data restore   — creates missing schemas, inserts data only"
    echo ""
    read -rp "  Choose [1/2]: " restore_choice

    if [[ "$restore_choice" == "1" ]]; then
      echo ""
      read -rp "  ⚠️  DESTROY database and rebuild from scratch? [y/N]: " confirm
      if [[ ! "$confirm" =~ ^[Yy]$ ]]; then echo "  Cancelled."; exit 0; fi

      echo ""
      echo -e "  ${C_YELLOW}[1/3] Dropping database...${C_RESET}"
      docker compose -f "$COMPOSE_FILE" exec "$POSTGRES_SERVICE" \
        bash -c 'psql -U "$POSTGRES_USER" -d template1 -c "DROP DATABASE IF EXISTS \""$POSTGRES_DB"\" WITH (FORCE);"' \
        2>/dev/null || true

      echo -e "  ${C_YELLOW}[2/3] Creating database...${C_RESET}"
      docker compose -f "$COMPOSE_FILE" exec "$POSTGRES_SERVICE" \
        bash -c 'psql -U "$POSTGRES_USER" -d template1 -c "CREATE DATABASE \""$POSTGRES_DB"\";"' \
        2>/dev/null

      echo -e "  ${C_YELLOW}[3/3] Restoring...${C_RESET}"
      local total_lines; total_lines=$(wc -l < "$target_file")
      if [[ "$HAS_PV" -eq 1 ]]; then
        pv -lpte -s "$total_lines" "$target_file" | psql_exec > /dev/null
      else
        show_progress "$total_lines" "Restoring" < "$target_file" | psql_exec > /dev/null
      fi
      echo ""
      echo -e "  ${C_GREEN}Full restore completed.${C_RESET}"

    else
      # Data-only from full backup
      echo ""
      read -rp "  ⚠️  Insert data into existing tables? [y/N]: " confirm
      if [[ ! "$confirm" =~ ^[Yy]$ ]]; then echo "  Cancelled."; exit 0; fi

      echo ""
      echo -e "  ${C_YELLOW}[1/2] Creating missing schemas...${C_RESET}"
      # Extract CREATE SCHEMA lines and convert to IF NOT EXISTS
      grep '^CREATE SCHEMA ' "$target_file" 2>/dev/null | \
        sed 's/^CREATE SCHEMA /CREATE SCHEMA IF NOT EXISTS /' | \
        psql_exec > /dev/null 2>&1 || true
      echo -e "  ${C_GREEN}Schemas ready.${C_RESET}"

      echo -e "  ${C_YELLOW}[2/2] Inserting data...${C_RESET}"
      local total_lines; total_lines=$(wc -l < "$target_file")
      if [[ "$HAS_PV" -eq 1 ]]; then
        pv -lpte -s "$total_lines" "$target_file" | \
          extract_data_and_schemas | psql_exec > /dev/null 2>&1 || true
      else
        show_progress "$total_lines" "Inserting data" < "$target_file" | \
          extract_data_and_schemas | psql_exec > /dev/null 2>&1 || true
      fi
      echo ""
      echo -e "  ${C_GREEN}Data insert completed.${C_RESET}"
      echo -e "  ${C_YELLOW}Note: Duplicate rows were skipped. New rows were inserted.${C_RESET}"
    fi

  # ========================================================================
  # DATA backup restore
  # ========================================================================
  elif [[ "$detected_mode" == "data" ]]; then
    read -rp "  ⚠️  Insert data into existing tables? [y/N]: " confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then echo "  Cancelled."; exit 0; fi

    echo ""
    local total_lines; total_lines=$(wc -l < "$target_file")
    if [[ "$HAS_PV" -eq 1 ]]; then
      pv -lpte -s "$total_lines" "$target_file" | \
        docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
        bash -c 'psql -v ON_ERROR_STOP=1 -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" > /dev/null'
    else
      show_progress "$total_lines" "Restoring data" < "$target_file" | \
        docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
        bash -c 'psql -v ON_ERROR_STOP=1 -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" > /dev/null'
    fi
    echo ""
    echo -e "  ${C_GREEN}Data restore completed.${C_RESET}"

  # ========================================================================
  # SCHEMA backup restore
  # ========================================================================
  else
    read -rp "  ⚠️  Create/update schema objects? [y/N]: " confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then echo "  Cancelled."; exit 0; fi

    echo ""
    local total_lines; total_lines=$(wc -l < "$target_file")
    if [[ "$HAS_PV" -eq 1 ]]; then
      pv -lpte -s "$total_lines" "$target_file" | psql_exec > /dev/null
    else
      show_progress "$total_lines" "Restoring schema" < "$target_file" | psql_exec > /dev/null
    fi
    echo ""
    echo -e "  ${C_GREEN}Schema restore completed.${C_RESET}"
    echo -e "  ${C_YELLOW}Note: 'already exists' messages are expected.${C_RESET}"
  fi
}

# ---------------------------------------------------------------------------
# LIST
# ---------------------------------------------------------------------------
do_list() {
  echo -e "${C_CYAN}=== Available Backups ===${C_RESET}"
  echo "  Directory: $BACKUP_DIR"
  echo ""
  if ! find "$BACKUP_DIR" -maxdepth 1 -type f \( -name '*.sql' -o -name '*.dump' -o -name '*.backup' \) | grep -q .; then
    echo "  No backups found."
    exit 0
  fi
  find "$BACKUP_DIR" -maxdepth 1 -type f \( -name '*.sql' -o -name '*.dump' -o -name '*.backup' \) \
    -printf '%T+ %s %p\n' | sort -r | while IFS= read -r line; do
    local dt size path
    dt=$(echo "$line" | awk '{print $1}')
    size=$(echo "$line" | awk '{print $2}')
    path=$(echo "$line" | awk '{print $3}')
    printf "  %-30s %10s  %s\n" "$dt" "$(numfmt --to=iec "$size" 2>/dev/null || echo "${size}B")" "$(basename "$path")"
  done
}

# ---------------------------------------------------------------------------
# HELP
# ---------------------------------------------------------------------------
do_help() {
  cat <<EOF
Usage: $(basename "$0") <command> [options]

Commands:
  backup [data|full|schema]    Create backup (default: data)
  restore [file]             Restore latest or specific file
  list                       List all backups
  help                       Show this help

Examples:
  $(basename "$0") backup data
  $(basename "$0") backup full
  $(basename "$0") restore
  $(basename "$0") restore backup_full_20260530_143022.sql

Environment:
  BACKUP_DIR     Directory for backups (default: ./db_backups)
EOF
}

# ---------------------------------------------------------------------------
# MAIN
# ---------------------------------------------------------------------------
case "${1:-help}" in
  backup)
    shift
    do_backup "${1:-data}"
    ;;
  restore)
    shift
    do_restore "${1:-}"
    ;;
  list)
    do_list
    ;;
  help|--help|-h)
    do_help
    ;;
  *)
    echo -e "${C_RED}Unknown command: ${1}${C_RESET}" >&2
    do_help >&2
    exit 1
    ;;
esac
