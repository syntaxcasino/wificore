#!/bin/bash

# Configuration - Adjust these for your setup
DB_CONTAINER="traidnet-postgres"
DB_USER="postgres"
DB_NAME="wifi_hotspot_test"  # We'll create/drop this for testing
DB_PASSWORD=""  # Set if needed; script will prompt if empty but required
MAX_CLIENTS=125  # Test up to this (increment by 25; adjust based on expected max_connections)
INCREMENT=25
SCALE_FACTOR=1  # Smaller for faster init (-s flag in pgbench)
TEST_DURATION=10  # Seconds per test (-T flag)
THREADS=5  # pgbench threads (-j flag)

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'  # No Color

# Function to run command with optional PGPASSWORD
run_with_pg() {
    local cmd="$@"
    if [ -n "$DB_PASSWORD" ]; then
        PGPASSWORD="$DB_PASSWORD" docker exec -it "$DB_CONTAINER" $cmd
    else
        docker exec -it "$DB_CONTAINER" $cmd
    fi
    return $?
}

# Function to check if DB exists
db_exists() {
    run_with_pg --user "$DB_USER" psql -U "$DB_USER" -d postgres -t -c "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1
}

# Function to run SQL query via docker exec
run_sql() {
    local query="$1"
    run_with_pg --user "$DB_USER" psql -U "$DB_USER" -d "$DB_NAME" -c "$query" 2>/dev/null | tail -1
}

# Function to get max_connections
get_max_connections() {
    run_sql "SHOW max_connections;"
}

# Function to get current connections
get_current_connections() {
    run_sql "SELECT count(*) FROM pg_stat_activity;"
}

# Function to run pgbench test
run_pgbench_test() {
    local clients="$1"
    echo -e "${YELLOW}Testing with $clients concurrent clients...${NC}"
    
    # Run pgbench
    local cmd="pgbench -c $clients -j $THREADS -t 100 -T $TEST_DURATION -U $DB_USER $DB_NAME"
    local output=$(run_with_pg $cmd 2>&1)
    local exit_code=$?
    
    echo "$output"  # Print full output for debugging
    
    if [ "$exit_code" -ne 0 ]; then
        echo -e "${RED}FAILED: Hit connection limit or error at $clients clients!${NC}"
        return 1
    fi
    
    # Post-test: Check current connections
    local current=$(get_current_connections)
    local max_conn=$(get_max_connections)
    echo -e "${GREEN}SUCCESS: Test completed. Current connections: $current / Max: $max_conn${NC}\n"
    return 0
}

# Main script
echo "PostgreSQL Max Connections Tester"
echo "================================"

# Prompt for password if not set
if [ -z "$DB_PASSWORD" ]; then
    read -s -p "Enter DB password (leave blank if none): " DB_PASSWORD
    echo
fi

# Step 1: Check and create test DB if needed
echo "Checking for test database '$DB_NAME'..."
if db_exists; then
    echo "DB already exists. Dropping for fresh start..."
    run_with_pg --user "$DB_USER" dropdb -U "$DB_USER" "$DB_NAME" || echo "Warning: Could not drop existing DB."
fi

echo "Creating test database '$DB_NAME'..."
create_output=$(run_with_pg --user "$DB_USER" createdb -U "$DB_USER" "$DB_NAME" 2>&1)
create_code=$?
if [ "$create_code" -eq 0 ]; then
    echo -e "${GREEN}DB created successfully.${NC}"
else
    echo -e "${RED}Failed to create DB. Exit code: $create_code. Output: $create_output${NC}"
    echo "Check: User perms, password, or run manual 'createdb' as above."
    exit 1
fi

# Step 2: Initialize pgbench data
echo "Initializing pgbench data (scale $SCALE_FACTOR)..."
init_output=$(run_with_pg pgbench -i -s "$SCALE_FACTOR" -U "$DB_USER" "$DB_NAME" 2>&1)
init_code=$?
echo "$init_output"
if [ "$init_code" -ne 0 ]; then
    echo -e "${RED}Failed to initialize. Exit code: $init_code. Output above. Check DB user/password/container.${NC}"
    exit 1
fi
echo -e "${GREEN}Initialization complete.${NC}"

# Step 3: Get baseline max_connections
max_conn=$(get_max_connections)
echo -e "\nBaseline: Max connections allowed: $max_conn\n"

# Step 4: Incremental tests
for clients in $(seq $INCREMENT $INCREMENT $MAX_CLIENTS); do
    if [ "$clients" -gt "$((max_conn + 10))" ]; then
        echo "Skipping higher tests (already near/exceed limit)."
        break
    fi
    run_pgbench_test $clients
    if [ $? -eq 1 ]; then
        break
    fi
    sleep 2  # Brief pause to let connections idle/close
done

# Step 5: Cleanup
echo "Cleaning up test database..."
run_with_pg --user "$DB_USER" dropdb -U "$DB_USER" "$DB_NAME" >/dev/null 2>&1
echo -e "${GREEN}Test complete. Check logs above for results.${NC}"

# Optional: Monitor resources
echo "Current DB container stats:"
docker stats "$DB_CONTAINER" --no-stream