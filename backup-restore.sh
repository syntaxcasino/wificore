#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# WiFiCore Database Backup & Restore Script
# Usage:
#   ./backup-restore.sh backup          # Create a new timestamped backup
#   ./backup-restore.sh restore         # Restore from the latest backup found
#   ./backup-restore.sh restore FILE    # Restore from a specific backup file
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${BACKUP_DIR:-${SCRIPT_DIR}/backups}"
COMPOSE_FILE="${SCRIPT_DIR}/docker-compose.production.yml"
POSTGRES_SERVICE="wificore-postgres"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Ensure backup directory exists
mkdir -p "$BACKUP_DIR"

# ---------------------------------------------------------------------------
# BACKUP
# ---------------------------------------------------------------------------
do_backup() {
  local backup_file="${BACKUP_DIR}/backup_${TIMESTAMP}.sql"
  echo "=== WiFiCore Backup ==="
  echo "Backup dir : $BACKUP_DIR"
  echo "Output file: $backup_file"
  echo "Dumping database from container '${POSTGRES_SERVICE}' ..."

  docker compose -f "$COMPOSE_FILE" exec "$POSTGRES_SERVICE" \
    bash -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --no-owner --no-acl' \
    > "$backup_file"

  echo "✅ Backup saved: $backup_file"
  ls -lh "$backup_file"
}

# ---------------------------------------------------------------------------
# RESTORE
# ---------------------------------------------------------------------------
do_restore() {
  local target_file=""

  if [[ -n "${1:-}" ]]; then
    # User provided a specific file
    target_file="$1"
    if [[ ! -f "$target_file" ]]; then
      # Try relative to backup dir
      target_file="${BACKUP_DIR}/$1"
      if [[ ! -f "$target_file" ]]; then
        echo "❌ Error: File not found: $1" >&2
        exit 1
      fi
    fi
  else
    # Auto-pick the latest backup
    target_file=$(find "$BACKUP_DIR" -maxdepth 1 -type f \
      \( -name '*.sql' -o -name '*.dump' -o -name '*.backup' \) \
      -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -1 | cut -d' ' -f2-)

    if [[ -z "$target_file" || ! -f "$target_file" ]]; then
      echo "❌ Error: No backup files found in ${BACKUP_DIR}" >&2
      echo "   Expected files: *.sql, *.dump, *.backup" >&2
      exit 1
    fi
  fi

  echo "=== WiFiCore Restore ==="
  echo "Backup file : $target_file"
  echo "Target DB   : ${POSTGRES_SERVICE}"
  echo ""
  read -rp "⚠️  This will DROP and RECREATE the database. Continue? [y/N]: " confirm
  if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
    echo "Restore cancelled."
    exit 0
  fi

  local ext="${target_file##*.}"
  echo "Restoring ..."

  if [[ "$ext" == "dump" || "$ext" == "backup" ]]; then
    # Custom-format dump (pg_restore)
    docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
      bash -c 'pg_restore --clean --if-exists --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
      < "$target_file"
  else
    # Plain SQL dump (psql)
    docker compose -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" \
      bash -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
      < "$target_file"
  fi

  echo "✅ Restore completed from: $target_file"
}

# ---------------------------------------------------------------------------
# LIST BACKUPS
# ---------------------------------------------------------------------------
do_list() {
  echo "=== Available Backups ==="
  echo "Directory: $BACKUP_DIR"
  echo ""
  if ! find "$BACKUP_DIR" -maxdepth 1 -type f \( -name '*.sql' -o -name '*.dump' -o -name '*.backup' \) | grep -q .; then
    echo "No backups found."
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
  backup           Create a new timestamped SQL backup
  restore [file]   Restore from the latest backup, or a specific file
  list             List all available backups
  help             Show this help message

Environment:
  BACKUP_DIR       Directory to store backups (default: ./backups)
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
    echo "❌ Unknown command: ${1}" >&2
    do_help >&2
    exit 1
    ;;
esac
