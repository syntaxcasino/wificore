#!/bin/bash

# WiFi Hotspot API Load Testing
# This script tests the TPS (Transactions Per Second) of the API endpoints

# Load environment variables
if [ -f "../../.env" ]; then
    export $(grep -v '^#' ../../.env | xargs)
fi

# Set default values if not set
API_BASE_URL=${VITE_API_BASE_URL:-http://localhost/api}
TEST_USERS=${TEST_USERS:-50}
TEST_DURATION=${TEST_DURATION:-1m}

# Create test users if they don't exist
create_test_users() {
    echo "Creating test users..."
    mkdir -p test-data
    
    # Create a test user if it doesn't exist
    if [ ! -f "test-data/test-user.json" ]; then
        echo "Creating test user..."
        USER_RESPONSE=$(curl -s -X POST "${API_BASE_URL}/auth/register" \
            -H "Content-Type: application/json" \
            -d '{
                "name": "Test User",
                "email": "test@example.com",
                "password": "password123",
                "password_confirmation": "password123"
            }')
        
        if [ $? -ne 0 ]; then
            echo "Error creating test user. The API might be down or the user already exists."
            # Try to login instead
            LOGIN_RESPONSE=$(curl -s -X POST "${API_BASE_URL}/auth/login" \
                -H "Content-Type: application/json" \
                -d '{"email": "test@example.com", "password": "password123"}')
                
            if [ $? -ne 0 ]; then
                echo "Failed to authenticate with test user. Please check your API."
                exit 1
            fi
        fi
        
        # Save test user credentials
        echo '{"email": "test@example.com", "password": "password123"}' > test-data/test-user.json
    fi
}

# Run k6 load test
run_load_test() {
    echo "Starting API load test with ${TEST_USERS} users for ${TEST_DURATION}..."
    
    # Create a k6 test script on the fly
    cat > test-data/load-test.js << 'EOL'
import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { Trend } from 'k6/metrics';

// Configuration
const BASE_URL = '${__ENV.API_BASE_URL}';
const TEST_EMAIL = 'test@example.com';
const TEST_PASSWORD = 'password123';

// Custom metrics
const authDuration = new Trend('auth_duration');
const profileDuration = new Trend('profile_duration');
const packagesDuration = new Trend('packages_duration');

export const options = {
  // Run with 10 virtual users for 30 seconds
  vus: 10,
  duration: '30s',
  
  // Define thresholds
  thresholds: {
    http_req_duration: ['p(95)<500'], // 95% of requests should be below 500ms
    http_req_failed: ['rate<0.1'],    // Less than 10% failed requests
    auth_duration: ['p(95)<1000'],     // Auth should be fast
  },
  
  // Disable default summary
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)'],
};

// Get auth token
function getAuthToken() {
  const url = `${BASE_URL}/auth/login`;
  const payload = JSON.stringify({
    email: TEST_EMAIL,
    password: TEST_PASSWORD,
  });
  
  const params = {
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    timeout: '30s',
  };
  
  try {
    const start = new Date();
    const res = http.post(url, payload, params);
    const end = new Date() - start;
    
    if (res.status === 200) {
      authDuration.add(end);
      return res.json().token;
    }
    
    console.error(`Auth failed (${res.status}): ${res.body}`);
    return null;
    
  } catch (e) {
    console.error(`Auth error: ${e.message}`);
    return null;
  }
}

// Make API request with timing
function makeRequest(method, url, params, metric) {
  try {
    const start = new Date();
    const res = http.request(method, url, null, params);
    const end = new Date() - start;
    
    if (metric) {
      metric.add(end);
    }
    
    return res;
    
  } catch (e) {
    console.error(`Request error (${url}): ${e.message}`);
    return { status: 0, error: e.message };
  }
}

export default function () {
  // Get auth token
  const token = getAuthToken();
  if (!token) {
    console.error('Failed to get auth token');
    return;
  }
  
  // Common request parameters
  const params = {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    timeout: '30s',
  };
  
  // Test 1: Get user profile
  const profileRes = makeRequest('GET', `${BASE_URL}/user`, params, profileDuration);
  check(profileRes, {
    'profile status is 200': (r) => r.status === 200,
  });
  
  // Test 2: Get available packages
  const packagesRes = makeRequest('GET', `${BASE_URL}/packages`, params, packagesDuration);
  check(packagesRes, {
    'packages status is 200': (r) => r.status === 200,
  });
  
  // Add a small delay between iterations
  sleep(1);
}

// Handle summary
// export function handleSummary(data) {
//   return {
//     'test-data/k6-output/summary.json': JSON.stringify(data, null, 2),
//   };
// }
EOL

    # Run k6 test with proper environment variable handling
    echo "Running k6 test with API_BASE_URL: ${API_BASE_URL}"
    
    # Create a temporary file with the test script and replace the API_BASE_URL
    sed "s|\${__ENV.API_BASE_URL}|${API_BASE_URL}|g" test-data/load-test.js > test-data/load-test-temp.js
    
    # Create output directory if it doesn't exist
    mkdir -p test-data/k6-output
    
    # Run the test with JSON output
    echo "Running load test with output to test-data/k6-output/..."
    
    # Create a temporary directory for k6 output
    mkdir -p test-data/k6-output
    
    # Run the test with proper volume mounting
    docker run --rm -i \
      -v "$(pwd)/test-data:/test-data" \
      -e K6_OUT=json=/test-data/k6-output/result.json \
      -e K6_SUMMARY_EXPORT=summary.json \
      grafana/k6:latest run \
      --out json=/test-data/k6-output/result.json \
      --summary-export=/test-data/k6-output/summary.json \
      - < test-data/load-test-temp.js
      
    # Clean up
    rm -f test-data/load-test-temp.js
}

# Main execution
main() {
    create_test_users
    run_load_test
}

# Run main function
main "$@"

exit 0
