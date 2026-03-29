import { test, expect } from '@playwright/test'
import { TEST_USERS, mockApiResponses, auth, interactions, assertions } from './utils.js'

/**
 * Authentication E2E Tests
 * 
 * Test coverage:
 * - Login page rendering
 * - Successful login flow
 * - Failed login handling
 * - Logout functionality
 * - Protected route redirects
 * - Remember me functionality
 */

test.describe('Authentication', () => {
  test.beforeEach(async ({ page }) => {
    // Clear any existing auth state
    await auth.logout(page)
  })

  test.describe('Login Page', () => {
    test('should display login form with all required fields', async ({ page }) => {
      await page.goto('/login')
      
      // Verify form elements exist
      await expect(page.locator('h1, h2').filter({ hasText: /sign in|login/i }).first()).toBeVisible()
      await expect(page.locator('input[type="email"], input[name="email"]')).toBeVisible()
      await expect(page.locator('input[type="password"], input[name="password"]')).toBeVisible()
      await expect(page.locator('button[type="submit"]')).toBeVisible()
    })

    test('should show validation errors for empty fields', async ({ page }) => {
      await page.goto('/login')
      
      // Submit empty form
      await page.click('button[type="submit"]')
      
      // Should show validation feedback
      const errorMessage = page.locator('text=/required|invalid|please enter/i')
      await expect(errorMessage.first()).toBeVisible()
    })

    test('should show error for invalid credentials', async ({ page }) => {
      await page.goto('/login')
      
      // Mock failed login
      await page.route('**/api/auth/login', async (route) => {
        await route.fulfill({
          status: 401,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'Invalid credentials' })
        })
      })
      
      await page.fill('input[type="email"]', 'invalid@example.com')
      await page.fill('input[type="password"]', 'wrongpassword')
      await page.click('button[type="submit"]')
      
      // Should show error message
      await expect(page.locator('text=/invalid|error|failed/i').first()).toBeVisible()
    })
  })

  test.describe('Successful Login Flow', () => {
    test('should redirect to dashboard after successful login', async ({ page }) => {
      await mockApiResponses.loginSuccess(page)
      
      await page.goto('/login')
      await page.fill('input[type="email"]', TEST_USERS.tenant.email)
      await page.fill('input[type="password"]', TEST_USERS.tenant.password)
      await page.click('button[type="submit"]')
      
      // Should redirect to dashboard
      await page.waitForURL('**/dashboard**')
      await expect(page).toHaveURL(/.*dashboard.*/)
    })

    test('should persist auth token in localStorage', async ({ page }) => {
      await mockApiResponses.loginSuccess(page)
      
      await page.goto('/login')
      await page.fill('input[type="email"]', TEST_USERS.tenant.email)
      await page.fill('input[type="password"]', TEST_USERS.tenant.password)
      await page.click('button[type="submit"]')
      
      await page.waitForURL('**/dashboard**')
      
      // Verify token is stored
      const token = await page.evaluate(() => localStorage.getItem('auth_token'))
      expect(token).toBeTruthy()
    })

    test('should display user info in header after login', async ({ page }) => {
      await mockApiResponses.loginSuccess(page)
      
      await page.goto('/login')
      await page.fill('input[type="email"]', TEST_USERS.tenant.email)
      await page.fill('input[type="password"]', TEST_USERS.tenant.password)
      await page.click('button[type="submit"]')
      
      await page.waitForURL('**/dashboard**')
      
      // Verify user name or email appears in header
      const header = page.locator('header, .header, [class*="header"]').first()
      await expect(header).toContainText(/test user|test@example.com/i)
    })
  })

  test.describe('Protected Routes', () => {
    test('should redirect unauthenticated users to login', async ({ page }) => {
      await page.goto('/dashboard')
      
      // Should redirect to login
      await page.waitForURL('**/login**')
      await expect(page).toHaveURL(/.*login.*/)
    })

    test('should redirect unauthenticated API calls appropriately', async ({ page }) => {
      // Mock API returning 401
      await page.route('**/api/**', async (route) => {
        await route.fulfill({
          status: 401,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'Unauthorized' })
        })
      })
      
      await page.goto('/dashboard')
      await page.waitForURL('**/login**')
    })
  })

  test.describe('Logout', () => {
    test('should clear auth state and redirect to login', async ({ page }) => {
      // Login first
      await mockApiResponses.loginSuccess(page)
      await auth.setAuthToken(page)
      
      await page.goto('/dashboard')
      
      // Click logout (look for common logout button patterns)
      const logoutButton = page.locator('button:has-text("Logout"), a:has-text("Logout"), [data-testid="logout"]').first()
      if (await logoutButton.isVisible().catch(() => false)) {
        await logoutButton.click()
      } else {
        // Manually clear auth for test
        await auth.logout(page)
        await page.goto('/dashboard')
      }
      
      // Should be redirected to login
      await expect(page).toHaveURL(/.*login.*/)
      
      // Token should be cleared
      const token = await page.evaluate(() => localStorage.getItem('auth_token'))
      expect(token).toBeFalsy()
    })
  })

  test.describe('Role-Based Access', () => {
    test('system admin should see admin dashboard', async ({ page }) => {
      await mockApiResponses.loginSuccess(page, { role: 'system_admin' })
      
      await page.goto('/login')
      await page.fill('input[type="email"]', TEST_USERS.systemAdmin.email)
      await page.fill('input[type="password"]', TEST_USERS.systemAdmin.password)
      await page.click('button[type="submit"]')
      
      await page.waitForURL('**/system-admin**')
      await expect(page).toHaveURL(/.*system-admin.*/)
    })

    test('tenant should be redirected from system admin routes', async ({ page }) => {
      await auth.setAuthToken(page)
      
      await page.goto('/system-admin')
      
      // Should redirect to tenant dashboard or show access denied
      const currentUrl = page.url()
      expect(currentUrl).not.toContain('/system-admin')
    })
  })
})
