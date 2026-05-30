#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# WiFiCore Database Backup & Restore Script
# Supports three backup levels: data, full, schema
# Auto-detects backup type on restore and handles full restores cleanly.
# Usage:
#   ./backup-restore.sh backup [data|full|schema]     # Create backup (default: data)
#   ./backup-restore.sh restore [FILE]                # Restore latest or specific file
#   ./backup-restore.sh list                          # List all backups
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${BACKUP_DIR:-${SCRIPT_DIR}/db_backups}"
COMPOSE_FILE="${SCRIPT_DIR}/docker-compose.production.yml"
POSTGRES_SERVICE="wificore-postgres"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Colors
C_GREEN='\033[1;32m'
C_YELLOW='\033[1;33m'
C_RED='\033[1;31m'
C_CYAN='\033[1;36m'
C_RESET='\033[0m'

# Check for pv (pipe viewer)
HAS_PV=0
if command -v pv &>/dev/null; then
  HAS_PV=1
fi

# ---------------------------------------------------------------------------
# Progress bar helper
# ---------------------------------------------------------------------------
show_progress() {
  local total="$1"
  local label="${2:-Restoring...}"
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
  local suffix="sql"
  local pgdump_opts=""
  local desc=""

  case "$mode" in
    data)
      suffix="sql"
      pgdump_opts="--data-only --inserts --no-owner --no-acl"
      desc="DATA-ONLY (INSERTs, safe for overlay restore)"
      ;;
    full)
      suffix="sql"
      pgdump_opts="--clean --if-exists --no-owner --no-acl"
      desc="FULL (DROP + CREATE + data — requires fresh DB or full restore)"
      ;;
    schema)
      suffix="sql"
      pgdump_opts="--schema-only --no-owner --no-acl"
      desc="SCHEMA-ONLY (CREATE tables/functions, no data)"
      ;;
    *)
      echo -e "${C_RED}Error: Unknown backup type '$mode'. Use: data, full, or schema.${C_RESET}" >&2
      exit 1
      ;;
  esac

  local backup_file="${BACKUP_DIR}/backup_${mode}_${TIMESTAMP}.${suffix}"

  echo -e "${C_CYAN}=== WiFiCore Backup ===${C_RESET}"
  echo "  Backup dir : $BACKUP_DIR"
  echo "  Output file: $(basename "$backup_file")"
  echo "  Mode       : $desc"
  echo ""

  mkdir -p "$BACKUP_DIR"

  if [[ "$HAS_PV" -eq 1 ]]; then
    echo -e "  ${C_YELLOW}Dumping...${C_RESET}"
    docker compose -f "$COMPOSE_FILE" exec "$POSTGRES_SERVICE" \
      bash -c "pg_dump -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" ${pgdump_opts}" \
      | pv -pte > "$backup_file"
  else
    echo -e "  ${C_YELLOW}Dumping...${C_RESET} (install 'pv' for progress bar: apt install pv)"
    docker compose -f "$COMPOSE_FILE" exec "$POSTGRES_SERVICE" \
      bash -c "pg_dump -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" ${pgdump_opts}" \
      > "$backup_file" &
    local pid=$!
    local spin='-\|/'
    local i=0
    while kill -0 "$pid" 2>/dev/null; do
      i=$(( (i + 1) % 4 ))
      printf "\r  Dumping... %s" "${spin:$i:1}"
      sleep 0.3
    done
    wait "$pid"
    printf "\r  Dumping... done!       \n"
  fi

  local size
  size=$(du -h "$backup_file" | cut -f1)
  echo -e "  ${C_GREEN}Backup saved: $(basename "$backup_file") ($size)${C_RESET}"
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

  # Detect backup type from filename or content
  if [[ "$filename" == *"_data_"* ]]; then
    detected_mode="data"
  elif [[ "$filename" == *"_schema_"* ]]; then
    detected_mode="schema"
  elif [[ "$filename" == *"_full_"* ]]; then
    detected_mode="full"
  elif [[ "${filename##*.}" == "sql" ]]; then
    if grep -q '^DROP ' "$target_file" 2>/dev/null; then
      detected_mode="full"
    elif grep -q '^COPY ' "$target_file" 2>/dev/null || grep -q '^INSERT ' "$target_file" 2>/dev/null; then
      detected_mode="data"
    else
      detected_mode="schema"
    fi
  fi

  echo -e "${C_CYAN}=== WiFiCore Restore ===${C_RESET}"
  echo "  Backup file : $filename"
  echo "  Target DB   : ${POSTGRES_SERVICE}"
  echo "  Detected    : ${detected_mode} backup"

  # -------------------------------------------------------------------------
  # FULL backup restore: drop DB + recreate + restore everything
  # -------------------------------------------------------------------------
  if [[ "$detected_mode" == "full" ]]; then
    echo ""
    echo "  ${C_YELLOW}This is a FULL backup. You have two options:${C_RESET}"
    echo "    1) Full restore  — drops entire DB, recreates, restores everything (CLEAN)"
    echo "    2) Data restore  — keeps existing schema, inserts data only (may fail if"
    echo "                       schemas/tables are missing or data already exists)"
    echo ""
    read -rp "  Choose restore mode [1/2]: " restore_choice

    if [[ "$restore_choice" == "1" ]]; then
      echo ""
      read -rp "  ⚠️  This will DESTROY the entire database and rebuild it. Continue? [y/N]: " confirm
      if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
        echo "  Restore cancelled."
        exit 0
      fi

      echo ""
      echo -e "  ${C_YELLOW}[1/3] Dropping database (force-closing connections)...${C_RESET}"
      docker compose -f "$COMPOSE_FILE" exec "$POSTGRES_SERVICE" \
        bash -c 'psql -U "$POSTGRES_USER" -d template1 -c "DROP DATABASE IF EXISTS \""$POSTGRES_DB"\" WITH (FORCE);"' \
        >/dev/null 2>&1 || true

      echo -e "  ${C_YELLOW}[2/3] Creating database...${C_RESET}"
      docker compose -f "$COMPOSE_FILE" exec "$POSTGRES_SERVICE" \
        bash -c 'psql -U "$POSTGRES_USER" -d template1 -c "CREATE DATABASE \""$POSTGRES_DB"\";"' \
        >/dev/null 2>&1

      echo -e "  ${C_YELLOW}[3/3] Restoring into fresh database...${C_RESET}"
      local total_lines
      total_lines=$(wc -l < "$target_file")

      if [[ "$HAS_PV" -eq 1 ]]; then
        pv -lpte -s "$total_lines" "$target_file" | \
          docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
          bash -c 'psql -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" > /dev/null'
      else
        show_progress "$total_lines" "Restoring" < "$target_file" | \
          docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
          bash -c 'psql -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" > /dev/null'
      fi

      echo ""
      echo -e "  ${C_GREEN}Full restore completed successfully.${C_RESET}"

    else
      # Data-only from full backup
      echo ""
      read -rp "  ⚠️  This will attempt to insert data into existing tables. Continue? [y/N]: " confirm
      if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
        echo "  Restore cancelled."
        exit 0
      fi

      echo ""
      echo -e "  ${C_YELLOW}Stripping schema statements, restoring data only...${C_RESET}"
      local total_lines
      total_lines=$(wc -l < "$target_file")

      # Strip DROP, CREATE, ALTER, SET, etc. Keep COPY, INSERT, and \. (end of COPY)
      if [[ "$HAS_PV" -eq 1 ]]; then
        pv -lpte -s "$total_lines" "$target_file" | \
          sed '/^DROP /d; /^CREATE /d; /^ALTER /d; /^SET /d; /^SELECT pg_catalog/d; /^COMMENT /d; /^REVOKE /d; /^GRANT /d; /^--/d; /^$/d' | \
          docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
          bash -c 'psql -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" > /dev/null'
      else
        sed '/^DROP /d; /^CREATE /d; /^ALTER /d; /^SET /d; /^SELECT pg_catalog/d; /^COMMENT /d; /^REVOKE /d; /^GRANT /d; /^--/d; /^$/d' "$target_file" | \
          show_progress "$total_lines" "Restoring data" | \
          docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
          bash -c 'psql -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" > /dev/null'
      fi

      echo ""
      echo -e "  ${C_GREEN}Data restore attempted.${C_RESET}"
      echo -e "  ${C_YELLOW}Note: Errors about missing schemas or duplicate keys may appear above.${C_RESET}"
    fi

  # -------------------------------------------------------------------------
  # DATA backup restore: straightforward
  # -------------------------------------------------------------------------
  elif [[ "$detected_mode" == "data" ]]; then
    echo ""
    read -rp "  ⚠️  This will insert data into existing tables. Continue? [y/N]: " confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
      echo "  Restore cancelled."
      exit 0
    fi

    echo ""
    local total_lines
    total_lines=$(wc -l < "$target_file")

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

  # -------------------------------------------------------------------------
  # SCHEMA backup restore: create objects, skip if they exist
  # -------------------------------------------------------------------------
  else
    echo ""
    read -rp "  ⚠️  This will create schema objects (tables, indexes, etc.). Continue? [y/N]: " confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
      echo "  Restore cancelled."
      exit 0
    fi

    echo ""
    local total_lines
    total_lines=$(wc -l < "$target_file")

    if [[ "$HAS_PV" -eq 1 ]]; then
      pv -lpte -s "$total_lines" "$target_file" | \
        docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
        bash -c 'psql -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" > /dev/null'
    else
      show_progress "$total_lines" "Restoring schema" < "$target_file" | \
        docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
        bash -c 'psql -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" > /dev/null'
    fi

    echo ""
    echo -e "  ${C_GREEN}Schema restore completed.${C_RESET}"
    echo -e "  ${C_YELLOW}Note: 'already exists' messages above are expected for existing objects.${C_RESET}"
  fi
}

# ---------------------------------------------------------------------------
# LIST BACKUPS
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
  backup [type]        Create a new backup (default: data)
                       Types:
                         data   = Data only (INSERTs, safe for overlay)
                         full   = Full DB (DROP + CREATE + INSERTs, for fresh restore)
                         schema = Schema only (CREATE tables, no data)

  restore [file]       Restore from latest backup, or a specific file.
                       Auto-detects backup type and guides you through the process.

  list                 List all available backups
  help                 Show this help message

Examples:
  $(basename "$0") backup data       # Create data-only backup
  $(basename "$0") backup full       # Create full destructive backup
  $(basename "$0") backup schema     # Create schema-only backup
  $(basename "$0") restore           # Auto-restore latest backup
  $(basename "$0") restore backup_full_20260530_143022.sql

Environment:
  BACKUP_DIR           Directory to store backups (default: ./db_backups)

Tip: Install 'pv' (pipe viewer) for smooth progress bars:
     sudo apt-get install pv
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
