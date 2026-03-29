/**
 * E2E Test Suite Index
 * 
 * This file exports all E2E test suites for organized test execution.
 * 
 * Test Organization:
 * 1. auth.spec.js - Authentication flows (login, logout, protected routes)
 * 2. dashboard.spec.js - Dashboard functionality and navigation
 * 3. hotspot-users.spec.js - CRUD operations for hotspot users
 * 4. settings.spec.js - All settings pages including new features
 * 5. visual-regression.spec.js - UI consistency and dark background checks
 * 
 * Running Tests:
 * 
 * Run all tests:
 *   npm run test:e2e
 * 
 * Run specific test file:
 *   npx playwright test e2e/auth.spec.js
 * 
 * Run with UI mode:
 *   npx playwright test --ui
 * 
 * Run in headed mode:
 *   npx playwright test --headed
 * 
 * Run specific browser:
 *   npx playwright test --project=chromium
 * 
 * Update snapshots:
 *   npx playwright test --update-snapshots
 * 
 * Debug mode:
 *   npx playwright test --debug
 */

// Export test utilities for reuse
export * from './utils.js'
