#!/bin/bash

# WiFi Hotspot Database Performance Testing
# This script tests the database performance and TPS

# Load environment variables from script directory first, then parent directory
if [ -f "$(dirname "$0")/.env" ]; then
    export $(grep -v '^#' "$(dirname "$0")/.env" | xargs)
elif [ -f "$(dirname "$(dirname "$0")")/.env" ]; then
    export $(grep -v '^#' "$(dirname "$(dirname "$0")/.env")" | xargs)
fi

# Set default values if not set
DB_HOST=${DB_HOST:-localhost}
DB_PORT=${DB_PORT:-5432}
DB_NAME=${DB_NAME:-wifi_hotspot}
DB_USER=${DB_USER:-postgres}
DB_PASSWORD=${DB_PASSWORD:-postgres}
TEST_ITERATIONS=${TEST_ITERATIONS:-5}

# Function to check database connection
check_db_connection() {
    echo "🔍 Testing database connection to ${DB_HOST}:${DB_PORT}..."
    
    # Check if psql is installed
    if ! command -v psql &> /dev/null; then
        echo "❌ Error: psql is not installed. Please install PostgreSQL client tools."
        exit 1
    fi
    
    # Test the connection
    PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1" -t &>/dev/null
    
    if [ $? -ne 0 ]; then
        echo "❌ Error: Could not connect to the database. Please check your connection settings:"
        echo "  Host: ${DB_HOST}"
        echo "  Port: ${DB_PORT}"
        echo "  Database: ${DB_NAME}"
        echo "  User: ${DB_USER}"
        echo "
To fix this, you can:"
        echo "1. Update the connection settings in scripts/test-tps/.env"
        echo "2. Make sure the PostgreSQL server is running and accessible"
        echo "3. Verify your database credentials and permissions"
        echo "4. Check if you need to expose the PostgreSQL port (e.g., using -p 5432:5432 in docker run)"
        exit 1
    fi
    
    echo "✅ Successfully connected to the database"
    echo ""
}

# Function to run a PostgreSQL query and measure execution time
run_query() {
    local query=$1
    local description=$2
    
    echo -e "\n🔍 $description"
    echo "Query: $query"
    
    # Run the query and measure time
    start_time=$(date +%s%N)
    
    PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "$query" -t
    
    end_time=$(date +%s%N)
    duration=$(( (end_time - start_time) / 1000000 ))
    echo "Execution time: ${duration}ms"
}

# Function to test read performance
test_read_performance() {
    echo -e "\n📊 Testing Database Read Performance"
    echo "=================================="
    
    # Test 1: Simple SELECT
    run_query "SELECT COUNT(*) FROM users;" "Count all users"
    
    # Test 2: JOIN operation
    run_query "
        SELECT u.name, COUNT(s.id) as session_count 
        FROM users u
        LEFT JOIN sessions s ON u.id = s.user_id
        GROUP BY u.id
        ORDER BY session_count DESC
        LIMIT 10;" 
        "Top 10 users by session count"
    
    # Test 3: Complex query with subquery
    run_query "
        SELECT 
            date_trunc('day', created_at) as day,
            COUNT(*) as new_users
        FROM users
        WHERE created_at > NOW() - INTERVAL '30 days'
        GROUP BY 1
        ORDER BY 1;" 
        "New users per day (last 30 days)"
}

# Function to test write performance
test_write_performance() {
    echo -e "\n📝 Testing Database Write Performance"
    echo "=================================="
    
    # Create a test table if it doesn't exist
    PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "
        CREATE TABLE IF NOT EXISTS performance_test (
            id SERIAL PRIMARY KEY,
            data TEXT,
            created_at TIMESTAMPTZ DEFAULT NOW()
        );
    "
    
    # Test 1: Single INSERT
    run_query "
        INSERT INTO performance_test (data) 
        VALUES (md5(random()::text)) 
        RETURNING id, created_at;" 
        "Single INSERT performance"
    
    # Test 2: Bulk INSERT
    run_query "
        WITH inserted AS (
            INSERT INTO performance_test (data)
            SELECT md5(random()::text)
            FROM generate_series(1, 1000)
            RETURNING id
        ) 
        SELECT 
            COUNT(*) as inserted_rows, 
            MIN(id) as min_id, 
            MAX(id) as max_id 
        FROM inserted;" 
        "Bulk INSERT (1000 rows) performance"
    
    # Test 3: UPDATE performance
    run_query "
        UPDATE performance_test 
        SET data = md5(random()::text)
        WHERE id % 10 = 0
        RETURNING COUNT(*);" 
        "UPDATE performance (10% of rows)"
    
    # Test 4: DELETE performance
    run_query "
        WITH deleted AS (
            DELETE FROM performance_test 
            WHERE id % 2 = 0
            RETURNING id
        ) 
        SELECT COUNT(*) as deleted_rows FROM deleted;" 
        "DELETE performance (50% of rows)"
}

# Function to test transaction performance
test_transaction_performance() {
    echo -e "\n💳 Testing Transaction Performance"
    echo "=================================="
    
    # Create a test account table if it doesn't exist
    PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "
        CREATE TABLE IF NOT EXISTS test_accounts (
            id SERIAL PRIMARY KEY,
            name TEXT NOT NULL,
            balance DECIMAL(10, 2) NOT NULL DEFAULT 0,
            created_at TIMESTAMPTZ DEFAULT NOW()
        );
        
        -- Create test accounts if they don't exist
        INSERT INTO test_accounts (name, balance)
        SELECT 'Account ' || i, 1000.00
        FROM generate_series(1, 10) i
        WHERE NOT EXISTS (SELECT 1 FROM test_accounts LIMIT 1);
        
        CREATE TABLE IF NOT EXISTS test_transactions (
            id SERIAL PRIMARY KEY,
            from_account_id INTEGER REFERENCES test_accounts(id),
            to_account_id INTEGER REFERENCES test_accounts(id),
            amount DECIMAL(10, 2) NOT NULL,
            created_at TIMESTAMPTZ DEFAULT NOW()
        );
    "
    
    # Test 1: Simple transaction
    echo -e "\n🔁 Testing simple transaction..."
    for i in $(seq 1 $TEST_ITERATIONS); do
        # Get random accounts
        from_id=$((RANDOM % 10 + 1))
        to_id=$((RANDOM % 10 + 1))
        
        # Make sure from and to are different
        while [ "$from_id" -eq "$to_id" ]; do
            to_id=$((RANDOM % 10 + 1))
        done
        
        amount=$((RANDOM % 100 + 1)).$((RANDOM % 100))
        
        echo "Transaction $i: Transfer $amount from account $from_id to $to_id"
        
        # Execute transaction
        PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "
            BEGIN;
            
            -- Check if from account has enough balance
            DECLARE
                from_balance DECIMAL(10, 2);
            BEGIN
                SELECT balance INTO from_balance 
                FROM test_accounts 
                WHERE id = $from_id 
                FOR UPDATE;
                
                IF from_balance < $amount THEN
                    RAISE EXCEPTION 'Insufficient funds';
                END IF;
                
                -- Deduct from source account
                UPDATE test_accounts 
                SET balance = balance - $amount 
                WHERE id = $from_id;
                
                -- Add to target account
                UPDATE test_accounts 
                SET balance = balance + $amount 
                WHERE id = $to_id;
                
                -- Record transaction
                INSERT INTO test_transactions (from_account_id, to_account_id, amount)
                VALUES ($from_id, $to_id, $amount);
                
                COMMIT;
                SELECT 'Transaction successful' as result;
            EXCEPTION WHEN OTHERS THEN
                ROLLBACK;
                RAISE;
            END;
        "
    done
}

# Function to test concurrent connections
test_concurrent_connections() {
    echo -e "\n👥 Testing Concurrent Connections"
    echo "=================================="
    
    # Create a test script
    cat > test_concurrent.sql << 'EOL'
\set n 1000
\set sleep_time 1000  -- in milliseconds

-- Simple query that takes some time
SELECT pg_sleep(:sleep_time / 1000.0), 
       'Query ' || :n || ' completed at ' || NOW() as result;
EOL
    
    echo "Starting 10 concurrent connections..."
    
    for i in {1..10}; do
        PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -v n=$i -v sleep_time=$((RANDOM % 1000 + 500)) -f test_concurrent.sql &
    done
    
    # Wait for all background processes to complete
    wait
    
    echo "All concurrent connections completed."
}

# Main function
main() {
    if ! PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1" >/dev/null 2>&1; then
        echo "❌ Error: Could not connect to the database. Please check your connection settings."
        exit 1
    fi
}

# Main function
main() {
    echo "🔍 Starting Database Performance Tests"
    echo "=================================="
    
    # First, check database connection
    check_db_connection
    
    # Run tests
    test_read_performance
    test_write_performance
    test_transaction_performance
    test_concurrent_connections
    
    echo "\n✅ All tests completed successfully!"
}

# Run main function
main "$@"

exit 0
