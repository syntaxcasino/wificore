#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# WiFiCore Database Backup & Restore Script
# Uses DATA-ONLY dumps (no schema/DROPs) to avoid FK conflicts during restore.
# Also handles old --clean backups by stripping DROP statements.
# Usage:
#   ./backup-restore.sh backup               # Create a new data-only backup
#   ./backup-restore.sh restore              # Restore latest backup
#   ./backup-restore.sh restore FILE.sql     # Restore a specific file
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
  local backup_file="${BACKUP_DIR}/backup_${TIMESTAMP}.sql"
  echo -e "${C_CYAN}=== WiFiCore Backup ===${C_RESET}"
  echo "  Backup dir : $BACKUP_DIR"
  echo "  Output file: $(basename "$backup_file")"
  echo "  Mode       : DATA-ONLY (no schema/DROPs)"
  echo ""

  mkdir -p "$BACKUP_DIR"

  if [[ "$HAS_PV" -eq 1 ]]; then
    echo -e "  ${C_YELLOW}Dumping database...${C_RESET}"
    docker compose -f "$COMPOSE_FILE" exec "$POSTGRES_SERVICE" \
      bash -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --data-only --inserts --no-owner --no-acl' \
      | pv -pte > "$backup_file"
  else
    echo -e "  ${C_YELLOW}Dumping database...${C_RESET} (install 'pv' for progress bar: apt install pv)"
    docker compose -f "$COMPOSE_FILE" exec "$POSTGRES_SERVICE" \
      bash -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --data-only --inserts --no-owner --no-acl' \
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

  # Detect if this is an old --clean dump (contains DROP statements)
  local is_clean_dump=0
  if [[ "${target_file##*.}" == "sql" ]]; then
    if grep -q '^DROP ' "$target_file" 2>/dev/null; then
      is_clean_dump=1
      echo "  Detected    : Old full-schema backup (with DROPs)"
      echo "  Action      : Removing DROP statements, creating missing schemas/tables"
    else
      echo "  Detected    : Data-only backup"
    fi
  fi

  echo ""
  read -rp "  ⚠️  This will OVERWRITE/INSERT data into existing tables. Continue? [y/N]: " confirm
  if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
    echo "  Restore cancelled."
    exit 0
  fi

  local ext="${target_file##*.}"
  echo ""

  if [[ "$ext" == "dump" || "$ext" == "backup" ]]; then
    # Custom-format dump
    echo -e "  ${C_YELLOW}Restoring data (custom format)...${C_RESET}"
    if [[ "$HAS_PV" -eq 1 ]]; then
      pv -pte "$target_file" | docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
        bash -c 'pg_restore --data-only --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
    else
      docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
        bash -c 'pg_restore --data-only --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
        < "$target_file"
    fi
  else
    # Plain SQL dump
    local total_lines
    total_lines=$(wc -l < "$target_file")

    if [[ "$is_clean_dump" -eq 1 ]]; then
      # Old --clean dump: strip DROP lines, let psql continue on errors
      echo -e "  ${C_YELLOW}Restoring data (stripping DROPs, errors on existing objects are OK)...${C_RESET}"
      if [[ "$HAS_PV" -eq 1 ]]; then
        pv -lpte -s "$total_lines" "$target_file" | sed '/^DROP /d' | docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
          bash -c 'psql -q -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
      else
        awk '
        /^DROP / { next }
        { print }
        {
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
        }' total="$total_lines" "$target_file" | sed '/^DROP /d' | docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
          bash -c 'psql -q -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
      fi
    else
      # New data-only dump
      echo -e "  ${C_YELLOW}Restoring data...${C_RESET}"
      if [[ "$HAS_PV" -eq 1 ]]; then
        pv -lpte -s "$total_lines" "$target_file" | docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
          bash -c 'psql -v ON_ERROR_STOP=1 -q -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
      else
        awk '
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
        }' total="$total_lines" "$target_file" | docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
          bash -c 'psql -v ON_ERROR_STOP=1 -q -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
      fi
    fi
  fi

  echo ""
  echo -e "  ${C_GREEN}Restore completed from: $(basename "$target_file")${C_RESET}"
  if [[ "$is_clean_dump" -eq 1 ]]; then
    echo -e "  ${C_YELLOW}Note: Some errors above (e.g. 'relation already exists') are expected and safe.${C_RESET}"
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
Usage: $(basename "$0") <command> [file]

Commands:
  backup               Create a new data-only SQL backup
  restore [file]       Restore from latest backup, or a specific file
  list                 List all available backups
  help                 Show this help message

Environment:
  BACKUP_DIR           Directory to store backups (default: ./db_backups)

Tip: Install 'pv' (pipe viewer) for smooth byte/line progress bars:
     sudo apt-get install pv
EOF
}

# ---------------------------------------------------------------------------
# MAIN
# ---------------------------------------------------------------------------
case "${1:-help}" in
  backup)
    do_backup
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
