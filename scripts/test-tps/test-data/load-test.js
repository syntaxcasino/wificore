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
