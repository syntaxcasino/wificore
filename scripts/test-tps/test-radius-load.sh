#!/bin/bash

# WiFi Hotspot RADIUS Load Testing
# This script tests the RADIUS server performance and TPS

# Load environment variables
if [ -f "../.env" ]; then
    export $(grep -v '^#' ../.env | xargs)
fi

# Set default values if not set
RADIUS_HOST=${RADIUS_HOST:-traidnet-freeradius}
RADIUS_PORT=${RADIUS_PORT:-1812}
RADIUS_SECRET=${RADIUS_SECRET:-testing123}
TEST_USERS=${TEST_USERS:-100}
TEST_ITERATIONS=${TEST_ITERATIONS:-10}

# Function to check if radclient is installed
check_radclient() {
    if ! command -v radclient &> /dev/null; then
        echo "radclient is not installed. Installing freeradius-utils..."
        
        if [ -x "$(command -v apt-get)" ]; then
            sudo apt-get update && sudo apt-get install -y freeradius-utils
        elif [ -x "$(command -v yum)" ]; then
            sudo yum install -y freeradius-utils
        elif [ -x "$(command -v dnf)" ]; then
            sudo dnf install -y freeradius-utils
        else
            echo "Error: Cannot install radclient. Please install freeradius-utils manually."
            exit 1
        fi
    fi
}

# Function to create test users in the database
create_test_users() {
    echo "Creating test users in the database..."
    
    # Create test users in the database
    for i in $(seq 1 $TEST_USERS); do
        username="testuser$i"
        password="password$i"
        
        # Check if user already exists
        USER_EXISTS=$(PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -t -c "
            SELECT COUNT(*) FROM radcheck WHERE username = '$username';
        " | tr -d '[:space:]')
        
        if [ "$USER_EXISTS" -eq "0" ]; then
            # Create user in radcheck
            PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "
                INSERT INTO radcheck (username, attribute, op, value) 
                VALUES ('$username', 'Cleartext-Password', ':=', '$password');
                
                -- Add user to default group
                INSERT INTO radusergroup (username, groupname, priority) 
                VALUES ('$username', 'users', 1);
                
                -- Add user info
                INSERT INTO raduserinfo (username, firstname, lastname, email, department)
                VALUES ('$username', 'Test', 'User $i', '$username@example.com', 'Testing');
            "
            
            if [ $? -ne 0 ]; then
                echo "Error creating user $username"
            fi
        fi
    done
    
    echo "Created $TEST_USERS test users."
}

# Function to test single RADIUS authentication
test_radius_auth() {
    local username=$1
    local password=$2
    
    # Create a temporary RADIUS client config
    cat > /tmp/radius_client.conf <<- EOM
$RADIUS_HOST $RADIUS_SECRET
EOM
    
    # Create a temporary users file
    echo "$username Cleartext-Password := \"$password\"" > /tmp/radius_users
    
    # Run radclient
    echo "User-Name=$username,User-Password=$password" | \
        radclient -f /tmp/radius_users $RADIUS_HOST:$RADIUS_PORT auth $RADIUS_SECRET
    
    # Clean up
    rm -f /tmp/radius_client.conf /tmp/radius_users
}

# Function to test concurrent RADIUS authentication
test_concurrent_auth() {
    local num_users=$1
    local iterations=$2
    
    echo -e "\n🚀 Testing $num_users concurrent RADIUS authentications for $iterations iterations..."
    
    # Create a temporary directory for test files
    mkdir -p test-data/radius
    
    # Create a test script
    cat > test-data/radius/run_test.sh << 'EOL'
#!/bin/bash

USERNAME=$1
PASSWORD=$2
RADIUS_HOST=$3
RADIUS_PORT=$4
RADIUS_SECRET=$5
ITERATION=$6
TOTAL_ITERATIONS=$7

# Create a temporary RADIUS client config
echo "$RADIUS_HOST $RADIUS_SECRET" > /tmp/radius_client_${USERNAME}.conf

# Create a temporary users file
echo "$USERNAME Cleartext-Password := \"$PASSWORD\"" > /tmp/radius_users_${USERNAME}

# Run radclient and measure time
START_TIME=$(date +%s%N)

echo "User-Name=$USERNAME,User-Password=$PASSWORD" | \
    radclient -f /tmp/radius_users_${USERNAME} $RADIUS_HOST:$RADIUS_PORT auth $RADIUS_SECRET > /tmp/radius_output_${USERNAME} 2>&1

END_TIME=$(date +%s%N)
DURATION_MS=$(( (END_TIME - START_TIME) / 1000000 ))

# Check if authentication was successful
if grep -q "Access-Accept" /tmp/radius_output_${USERNAME}; then
    STATUS="SUCCESS"
else
    STATUS="FAILED"
fi

# Output result in CSV format
echo "$ITERATION,$USERNAME,$DURATION_MS,$STATUS"

# Clean up
rm -f /tmp/radius_client_${USERNAME}.conf /tmp/radius_users_${USERNAME} /tmp/radius_output_${USERNAME}
EOL

    # Make the test script executable
    chmod +x test-data/radius/run_test.sh
    
    # Create a results file with header
    echo "iteration,username,duration_ms,status" > test-data/radius/results.csv
    
    # Run tests
    for iter in $(seq 1 $iterations); do
        echo -n "Iteration $iter/$iterations: "
        
        # Create a file descriptor for the results
        exec 3<> test-data/radius/results_${iter}.csv
        echo "iteration,username,duration_ms,status" >&3
        
        # Start background processes for concurrent authentications
        for i in $(seq 1 $num_users); do
            username="testuser$(( (RANDOM % TEST_USERS) + 1 ))"
            password="password${username#testuser}"
            
            # Run test in background
            (
                result=$(./test-data/radius/run_test.sh "$username" "$password" "$RADIUS_HOST" "$RADIUS_PORT" "$RADIUS_SECRET" "$iter" "$iterations" 2>/dev/null)
                echo "$result" >> test-data/radius/results_${iter}.csv
                echo -n "."
            ) &
        done
        
        # Wait for all background processes to complete
        wait
        echo " Done"
        
        # Close the file descriptor
        exec 3>&-
        
        # Append to main results file (skip header)
        tail -n +2 test-data/radius/results_${iter}.csv >> test-data/radius/results.csv
        
        # Calculate stats for this iteration
        success_count=$(grep -c "SUCCESS$" test-data/radius/results_${iter}.csv)
        fail_count=$((num_users - success_count))
        avg_duration=$(awk -F, 'NR>1 {sum+=$3; count++} END {print (count>0 ? sum/count : 0)}' test-data/radius/results_${iter}.csv)
        
        echo "  Success: $success_count, Failed: $fail_count, Avg. Duration: ${avg_duration%.*}ms"
        
        # Small delay between iterations
        sleep 1
    done
    
    # Calculate overall statistics
    total_tests=$((num_users * iterations))
    total_success=$(grep -c "SUCCESS$" test-data/radius/results.csv)
    total_fail=$((total_tests - total_success))
    success_rate=$(awk "BEGIN {printf \"%.2f\", ($total_success / $total_tests) * 100}")
    avg_duration=$(awk -F, 'NR>1 {sum+=$3; count++} END {printf "%.2f", (count>0 ? sum/count : 0)}' test-data/radius/results.csv)
    
    # Print summary
    echo -e "\n📊 Test Results Summary"
    echo "====================="
    echo "Total Tests: $total_tests"
    echo "Successful: $total_success"
    echo "Failed: $total_fail"
    echo "Success Rate: $success_rate%"
    echo "Average Duration: ${avg_duration}ms"
    echo "TPS (Transactions Per Second): $(awk "BEGIN {printf \"%.2f\", $total_tests / ($iterations * 1.0)}")"
    
    # Save summary to file
    echo -e "\nTest Configuration:" > test-data/radius/summary.txt
    echo "RADIUS Server: $RADIUS_HOST:$RADIUS_PORT" >> test-data/radius/summary.txt
    echo "Test Users: $TEST_USERS" >> test-data/radius/summary.txt
    echo "Concurrent Users: $num_users" >> test-data/radius/summary.txt
    echo "Iterations: $iterations" >> test-data/radius/summary.txt
    echo -e "\nResults:" >> test-data/radius/summary.txt
    echo "Total Tests: $total_tests" >> test-data/radius/summary.txt
    echo "Successful: $total_success" >> test-data/radius/summary.txt
    echo "Failed: $total_fail" >> test-data/radius/summary.txt
    echo "Success Rate: $success_rate%" >> test-data/radius/summary.txt
    echo "Average Duration: ${avg_duration}ms" >> test-data/radius/summary.txt
    echo "TPS (Transactions Per Second): $(awk "BEGIN {printf \"%.2f\", $total_tests / ($iterations * 1.0)}")" >> test-data/radius/summary.txt
    
    echo -e "\nDetailed results saved to test-data/radius/"
}

# Function to analyze RADIUS server logs
analyze_radius_logs() {
    echo -e "\n📋 Analyzing RADIUS server logs..."
    
    # Get container logs
    docker logs traidnet-freeradius > test-data/radius/radius_logs.txt 2>&1
    
    # Count authentication attempts
    auth_attempts=$(grep -c "Login OK" test-data/radius/radius_logs.txt)
    auth_failures=$(grep -c "Login incorrect" test-data/radius/radius_logs.txt)
    
    echo "Authentication Attempts: $auth_attempts"
    echo "Authentication Failures: $auth_failures"
    
    # Extract response times
    grep "Ready to process" test-data/radius/radius_logs.txt | \
        awk '{print $1" "$2" "$3" "$4" "$5" "$6" "$7" "$8" "$9" "$10" "$11" "$12" "$13" "$14" "$15" "$16" "$17" "$18" "$19" "$20}' | \
        sort -k4 > test-data/radius/radius_timing.txt
    
    # Calculate average response time
    if [ -s test-data/radius/radius_timing.txt ]; then
        avg_response=$(awk '{sum+=$7; count++} END {if(count>0) print sum/count; else print 0}' test-data/radius/radius_timing.txt)
        echo "Average Response Time: ${avg_response}ms"
    fi
}

# Main function
main() {
    echo "🔍 Starting RADIUS Load Tests"
    echo "=================================="
    
    # Check if radclient is installed
    check_radclient
    
    # Create test users if they don't exist
    create_test_users
    
    # Run tests
    test_concurrent_auth 10 $TEST_ITERATIONS
    
    # Analyze logs
    analyze_radius_logs
    
    echo -e "\n✅ RADIUS load tests completed. Results saved to test-data/radius/"
}

# Run main function
main "$@"

exit 0
