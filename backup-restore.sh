#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# WiFiCore Database Backup & Restore Script
# Supports three backup levels: data, full, schema
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
      desc="DATA-ONLY (no schema/DROPs)"
      ;;
    full)
      suffix="sql"
      pgdump_opts="--clean --if-exists --no-owner --no-acl"
      desc="FULL (schema + data + DROP/CREATE)"
      ;;
    schema)
      suffix="sql"
      pgdump_opts="--schema-only --no-owner --no-acl"
      desc="SCHEMA-ONLY (no data)"
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
    echo -e "  ${C_YELLOW}Dumping database...${C_RESET}"
    docker compose -f "$COMPOSE_FILE" exec "$POSTGRES_SERVICE" \
      bash -c "pg_dump -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" ${pgdump_opts}" \
      | pv -pte > "$backup_file"
  else
    echo -e "  ${C_YELLOW}Dumping database...${C_RESET} (install 'pv' for progress bar: apt install pv)"
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
    printf "\r  Dumping... done!     \n"
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
        echo -e "${C_RED}Error: File not found: $1${C_RESET}" >&2
        exit 1
      fi
    fi
  else
    target_file=$(find "$BACKUP_DIR" -maxdepth 1 -type f \
      \( -name '*.sql' -o -name '*.dump' -o -name '*.backup' \) \
      -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -1 | cut -d' ' -f2-)

    if [[ -z "$target_file" || ! -f "$target_file" ]]; then
      echo -e "${C_RED}Error: No backups found in ${BACKUP_DIR}${C_RESET}" >&2
      exit 1
    fi
  fi

  echo -e "${C_CYAN}=== WiFiCore Restore ===${C_RESET}"
  echo "  Backup file : $(basename "$target_file")"
  echo "  Target DB   : ${POSTGRES_SERVICE}"

  # Detect backup type from filename or content
  local detected_mode="unknown"
  local filename=$(basename "$target_file")

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

  echo "  Detected    : ${detected_mode} backup"

  # Set restore behavior based on detected mode
  local on_error_stop=1
  local strip_drops=0
  local restore_desc=""

  case "$detected_mode" in
    full)
      restore_desc="DROP + CREATE + INSERT (destructive)"
      ;;
    schema)
      restore_desc="CREATE schema only (no data)"
      on_error_stop=0
      ;;
    data|*)
      restore_desc="INSERT data only"
      ;;
  esac

  echo "  Action      : $restore_desc"
  echo ""

  if [[ "$detected_mode" == "full" ]]; then
    read -rp "  ⚠️  This will DROP and RECREATE the entire database. Continue? [y/N]: " confirm
  else
    read -rp "  ⚠️  This will modify existing database objects. Continue? [y/N]: " confirm
  fi

  if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
    echo "  Restore cancelled."
    exit 0
  fi

  local ext="${target_file##*.}"
  echo ""

  if [[ "$ext" == "dump" || "$ext" == "backup" ]]; then
    # Custom-format dump
    echo -e "  ${C_YELLOW}Restoring (custom format)...${C_RESET}"
    if [[ "$HAS_PV" -eq 1 ]]; then
      pv -pte "$target_file" | docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
        bash -c 'pg_restore --clean --if-exists --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
    else
      docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
        bash -c 'pg_restore --clean --if-exists --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
        < "$target_file"
    fi
  else
    # Plain SQL dump
    local total_lines
    total_lines=$(wc -l < "$target_file")

    if [[ "$detected_mode" == "full" ]]; then
      echo -e "  ${C_YELLOW}Restoring full backup...${C_RESET}"
    elif [[ "$detected_mode" == "schema" ]]; then
      echo -e "  ${C_YELLOW}Restoring schema...${C_RESET}"
      on_error_stop=0
    else
      echo -e "  ${C_YELLOW}Restoring data...${C_RESET}"
    fi

    local psql_cmd="psql -q -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\""
    if [[ "$on_error_stop" -eq 1 ]]; then
      psql_cmd="psql -v ON_ERROR_STOP=1 -q -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\""
    fi

    if [[ "$HAS_PV" -eq 1 ]]; then
      pv -lpte -s "$total_lines" "$target_file" | docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
        bash -c "$psql_cmd"
    else
      awk -v total="$total_lines" '
      {
        print
        if (NR % 200 == 0 || NR == total) {
          pct = (total > 0) ? int(NR * 100 / total) : 0
          filled = int(pct * 50 / 100)
          empty = 50 - filled
          bar = ""
          for (j = 1; j <= filled; j++) bar = bar "="
          for (j = 1; j <= empty; j++) bar = bar " "
          printf "\r  [%s] %3d%%  (%d/%d lines)", bar, pct, NR, total > "/dev/stderr"
        }
      }
      END {
        printf "\r  [%s] %3d%%  (%d/%d lines)  Done!\n", "==================================================", 100, NR, total > "/dev/stderr"
      }' "$target_file" | docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
        bash -c "$psql_cmd"
    fi
  fi

  echo ""
  echo -e "  ${C_GREEN}Restore completed from: $(basename "$target_file")${C_RESET}"
  if [[ "$detected_mode" == "full" ]]; then
    echo -e "  ${C_YELLOW}Note: Some errors above (e.g. 'relation already exists') are expected for full restores.${C_RESET}"
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
                       Types: data, full, schema
                         data   = Data only (INSERTs, safe to restore over existing DB)
                         full   = Full DB (DROP + CREATE + INSERTs, destructive)
                         schema = Schema only (CREATE tables, no data)

  restore [file]       Restore from latest backup, or a specific file
  list                 List all available backups
  help                 Show this help message

Examples:
  $(basename "$0") backup data       # Data-only backup
  $(basename "$0") backup full       # Full destructive backup
  $(basename "$0") backup schema     # Schema-only backup
  $(basename "$0") restore           # Auto-restore latest backup
  $(basename "$0") restore backup_data_20260530_143022.sql

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
