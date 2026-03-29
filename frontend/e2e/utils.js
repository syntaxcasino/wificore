/**
 * Shared E2E Test Utilities
 * 
 * This file contains common utilities, fixtures, and helper functions
 * used across all E2E tests.
 */

import { expect } from '@playwright/test'

/**
 * Test user credentials
 */
export const TEST_USERS = {
  tenant: {
    email: 'test@example.com',
    password: 'password123',
    organization: 'Test Organization'
  },
  systemAdmin: {
    email: 'admin@traidnet.com',
    password: 'admin123'
  }
}

/**
 * API mocking utilities for consistent test data
 */
export const mockApiResponses = {
  /**
   * Mock successful login response
   */
  loginSuccess: (page, userData = {}) => {
    return page.route('**/api/auth/login', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          token: 'test-jwt-token',
          user: {
            id: 1,
            email: userData.email || TEST_USERS.tenant.email,
            name: 'Test User',
            role: userData.role || 'tenant',
            organization: userData.organization || TEST_USERS.tenant.organization,
            ...userData
          }
        })
      })
    })
  },

  /**
   * Mock dashboard stats
   */
  dashboardStats: (page) => {
    return page.route('**/api/dashboard/stats', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          hotspot_users: 150,
          hotspot_sessions: 75,
          pppoe_users: 50,
          pppoe_sessions: 30,
          total_revenue: 125000,
          pending_payments: 15,
          active_tickets: 5
        })
      })
    })
  },

  /**
   * Mock hotspot users list
   */
  hotspotUsers: (page, count = 10) => {
    const users = Array.from({ length: count }, (_, i) => ({
      id: i + 1,
      username: `user${i + 1}`,
      phone: `+2547123456${i.toString().padStart(2, '0')}`,
      package_name: ['Basic', 'Standard', 'Premium'][i % 3],
      status: ['active', 'inactive', 'expired'][i % 3],
      data_used: `${(Math.random() * 10).toFixed(1)} GB`,
      expiry_date: new Date(Date.now() + 86400000 * (i + 1)).toISOString()
    }))

    return page.route('**/api/hotspot/users**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          users,
          total: count,
          page: 1,
          per_page: 10
        })
      })
    })
  },

  /**
   * Mock settings data
   */
  settings: (page) => {
    return page.route('**/api/settings/**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          organization: {
            name: 'Test Organization',
            email: 'admin@test.com',
            phone: '+254712345678'
          },
          timezone: 'Africa/Nairobi',
          locale: 'en-GB'
        })
      })
    })
  }
}

/**
 * Authentication helpers
 */
export const auth = {
  /**
   * Login as a specific user type
   */
  login: async (page, userType = 'tenant') => {
    const user = TEST_USERS[userType]
    
    await page.goto('/login')
    await page.fill('[data-testid="email-input"]', user.email)
    await page.fill('[data-testid="password-input"]', user.password)
    await page.click('[data-testid="login-button"]')
    
    // Wait for navigation to dashboard
    await page.waitForURL('**/dashboard**')
  },

  /**
   * Set auth token in localStorage (for API mocking scenarios)
   */
  setAuthToken: async (page, token = 'test-jwt-token') => {
    await page.evaluate((t) => {
      localStorage.setItem('auth_token', t)
    }, token)
  },

  /**
   * Clear authentication
   */
  logout: async (page) => {
    await page.evaluate(() => {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('user')
    })
  }
}

/**
 * Common page interactions
 */
export const interactions = {
  /**
   * Wait for loading state to complete
   */
  waitForLoading: async (page) => {
    // Wait for loading indicators to disappear
    const loadingSelectors = [
      '[data-testid="loading-indicator"]',
      '.loading',
      '.skeleton',
      '[role="progressbar"]'
    ]
    
    for (const selector of loadingSelectors) {
      try {
        await page.waitForSelector(selector, { state: 'hidden', timeout: 5000 })
      } catch {
        // Selector might not exist, continue
      }
    }
  },

  /**
   * Open a slide overlay by clicking a button
   */
  openOverlay: async (page, triggerSelector) => {
    await page.click(triggerSelector)
    await page.waitForSelector('[data-testid="slide-overlay"]', { state: 'visible' })
  },

  /**
   * Close slide overlay
   */
  closeOverlay: async (page) => {
    const closeButton = page.locator('[data-testid="slide-overlay"] button[aria-label="Close"], [data-testid="slide-overlay-close"]')
    if (await closeButton.isVisible().catch(() => false)) {
      await closeButton.click()
    }
  },

  /**
   * Fill form fields from an object
   */
  fillForm: async (page, fields) => {
    for (const [selector, value] of Object.entries(fields)) {
      const element = page.locator(selector)
      const tagName = await element.evaluate(el => el.tagName.toLowerCase())
      
      if (tagName === 'select') {
        await element.selectOption(value)
      } else if (tagName === 'input' && await element.getAttribute('type') === 'checkbox') {
        if (value) await element.check()
        else await element.uncheck()
      } else {
        await element.fill(value)
      }
    }
  },

  /**
   * Take screenshot with timestamp
   */
  screenshot: async (page, name) => {
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-')
    await page.screenshot({ 
      path: `e2e/screenshots/${name}-${timestamp}.png`,
      fullPage: true 
    })
  }
}

/**
 * Assertion helpers
 */
export const assertions = {
  /**
   * Verify page has light background (not dark mode issues)
   */
  hasLightBackground: async (page) => {
    const bgColor = await page.evaluate(() => {
      const main = document.querySelector('main, .content-area, [class*="bg-slate-50"]')
      return main ? window.getComputedStyle(main).backgroundColor : null
    })
    
    // Check for light/white background colors
    const lightColors = ['rgb(248, 250, 252)', 'rgb(255, 255, 255)', 'rgb(241, 245, 249)']
    expect(lightColors.some(color => bgColor?.includes(color) || bgColor === color)).toBeTruthy()
  },

  /**
   * Verify DataViewContainer pattern is used
   */
  hasDataViewContainer: async (page) => {
    await expect(page.locator('[data-testid="data-view-container"]').first()).toBeVisible()
  },

  /**
   * Verify no console errors
   */
  hasNoConsoleErrors: async (page) => {
    const errors = []
    page.on('console', msg => {
      if (msg.type() === 'error') {
        errors.push(msg.text())
      }
    })
    return errors
  }
}

/**
 * Mobile viewport configurations
 */
export const viewports = {
  mobile: { width: 375, height: 667 },
  tablet: { width: 768, height: 1024 },
  desktop: { width: 1280, height: 720 },
  wide: { width: 1920, height: 1080 }
}
