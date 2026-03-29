# Frontend End-to-End Tests

This directory contains comprehensive end-to-end tests for the TraidNet frontend using Playwright.

## Test Files

| File | Description | Test Count |
|------|-------------|------------|
| `auth.spec.js` | Authentication flows (login, logout, protected routes) | 33 |
| `dashboard.spec.js` | Dashboard functionality, stats, navigation | ~45 |
| `hotspot-users.spec.js` | CRUD operations for hotspot users | ~50 |
| `settings.spec.js` | All settings pages including new features | ~60 |
| `visual-regression.spec.js` | UI consistency, dark background detection | ~190 |
| `vue.spec.js` | Basic smoke tests | 3 |

**Total: 384 tests**

## Test Coverage

### Authentication Tests
- Login form validation
- Successful/failed login flows
- Token persistence in localStorage
- Protected route redirects
- Logout functionality
- Role-based access control

### Dashboard Tests
- Stats cards display
- Sidebar navigation
- Mobile responsive layout
- Quick action buttons
- Error state handling

### Hotspot Users Tests
- List view with DataViewContainer
- Search and filter functionality
- Create/Edit/Delete user flows
- Pagination
- Mobile card view

### Settings Tests
- Organization settings
- Communication channels
- Timezone & Locale
- Payment Gateways (new)
- Branding (new)
- System Updates (new)

### Visual Regression Tests
- Light background verification (51 pages × 3 viewports = 153 tests)
- DataViewContainer pattern consistency
- Sidebar navigation on all viewports
- SlideOverlay behavior
- Dark mode issue detection
- Responsive layout verification

## Running Tests

### Run all tests
```bash
cd /home/kja2aro/Projects/traidnet/wificore/frontend
npm run test:e2e
```

### Run specific test file
```bash
npx playwright test e2e/auth.spec.js
```

### Run with UI mode (interactive debugging)
```bash
npx playwright test --ui
```

### Run in headed mode (visible browser)
```bash
npx playwright test --headed
```

### Run specific browser
```bash
npx playwright test --project=chromium
```

### Run tests matching a pattern
```bash
npx playwright test --grep "dark background"
```

### Update visual snapshots
```bash
npx playwright test --update-snapshots
```

### Debug mode
```bash
npx playwright test --debug
```

## Test Configuration

The Playwright configuration is in `playwright.config.js`:
- **Test directory**: `./e2e`
- **Browsers tested**: Chromium, Firefox, WebKit
- **Base URL**: http://localhost:5173 (dev) or http://localhost:4173 (CI)
- **Timeout**: 30 seconds per test
- **Retries**: 2 retries on CI, 0 locally

## Utilities

The `utils.js` file provides:
- `TEST_USERS` - Test credentials
- `mockApiResponses` - API mocking helpers
- `auth` - Authentication helpers
- `interactions` - Common page interactions
- `assertions` - Custom assertions (light background, DataViewContainer)
- `viewports` - Standard viewport sizes

## Key Features Tested

### Dark Background Fix Verification
The visual-regression tests specifically verify that no pages have dark background issues:
- Checks 51 different pages across desktop, tablet, and mobile
- Verifies `bg-slate-50` or white backgrounds
- Detects `bg-gray-900`, `bg-slate-900` in main content areas
- Ensures text is readable with proper contrast

### DataViewContainer Pattern
Tests verify that all data display pages use the standardized `DataViewContainer` component:
- Consistent header structure
- Light background styling
- Proper stats and filter placement
- SlideOverlay integration

## CI Integration

For CI environments, set:
```bash
export CI=true
```

This will:
- Run tests headless
- Use preview server (port 4173)
- Enable 2 retries
- Run tests sequentially (workers: 1)
- Generate HTML report

## Writing New Tests

Example test structure:
```javascript
import { test, expect } from '@playwright/test'
import { mockApiResponses, auth, interactions } from './utils.js'

test.describe('Feature Name', () => {
  test.beforeEach(async ({ page }) => {
    await mockApiResponses.loginSuccess(page)
    await auth.setAuthToken(page)
    await page.goto('/dashboard/feature')
  })

  test('should do something', async ({ page }) => {
    // Test implementation
  })
})
```

## Troubleshooting

### Tests fail with "browser not found"
Install Playwright browsers:
```bash
npx playwright install
```

### Tests timeout
Increase timeout in `playwright.config.js` or run with:
```bash
npx playwright test --timeout=60000
```

### Flaky tests
Use retries or add explicit waits:
```javascript
await page.waitForLoadState('networkidle')
await interactions.waitForLoading(page)
```
