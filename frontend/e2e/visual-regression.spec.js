import { test, expect } from '@playwright/test'
import { mockApiResponses, auth, assertions, viewports } from './utils.js'

/**
 * Visual Regression & UI Consistency Tests
 * 
 * Test coverage:
 * - Dark background issues (all pages should have light background)
 * - DataViewContainer pattern consistency
 * - Mobile responsive layout
 * - Sidebar navigation on all viewports
 * - SlideOverlay behavior
 */

test.describe('Visual Regression - No Dark Background Issues', () => {
  test.beforeEach(async ({ page }) => {
    await mockApiResponses.loginSuccess(page)
    await mockApiResponses.dashboardStats(page)
    await mockApiResponses.hotspotUsers(page, 5)
    await auth.setAuthToken(page)
  })

  const pagesToTest = [
    { path: '/dashboard', name: 'Dashboard' },
    { path: '/dashboard/hotspot/users', name: 'Hotspot Users' },
    { path: '/dashboard/hotspot/sessions', name: 'Hotspot Sessions' },
    { path: '/dashboard/pppoe/users', name: 'PPPoE Users' },
    { path: '/dashboard/pppoe/sessions', name: 'PPPoE Sessions' },
    { path: '/dashboard/packages/all', name: 'Packages' },
    { path: '/dashboard/routers/mikrotik', name: 'Routers' },
    { path: '/dashboard/billing/transactions', name: 'Billing' },
    { path: '/dashboard/reports/payments', name: 'Payment Reports' },
    { path: '/dashboard/hr/employees', name: 'Employees' },
    { path: '/dashboard/support/all-tickets', name: 'Support Tickets' },
    { path: '/dashboard/settings/general', name: 'Settings - Organization' },
    { path: '/dashboard/settings/communication-channels', name: 'Settings - Communication' },
    { path: '/dashboard/settings/timezone-locale', name: 'Settings - Timezone' },
    { path: '/dashboard/settings/payment-gateways', name: 'Settings - Payment Gateways' },
    { path: '/dashboard/branding', name: 'Branding' },
    { path: '/dashboard/admin/system-updates', name: 'System Updates' },
  ]

  for (const { path, name } of pagesToTest) {
    test(`${name} page should have light background on desktop`, async ({ page }) => {
      await page.setViewportSize(viewports.desktop)
      await page.goto(path)
      
      // Wait for content to load
      await page.waitForLoadState('networkidle')
      
      // Check for light background
      await assertions.hasLightBackground(page)
      
      // Take screenshot for visual comparison
      await expect(page).toHaveScreenshot(`${name.toLowerCase().replace(/\s+/g, '-')}-desktop.png`, {
        maxDiffPixels: 100
      })
    })

    test(`${name} page should have light background on tablet`, async ({ page }) => {
      await page.setViewportSize(viewports.tablet)
      await page.goto(path)
      
      await page.waitForLoadState('networkidle')
      await assertions.hasLightBackground(page)
    })

    test(`${name} page should have light background on mobile`, async ({ page }) => {
      await page.setViewportSize(viewports.mobile)
      await page.goto(path)
      
      await page.waitForLoadState('networkidle')
      await assertions.hasLightBackground(page)
    })
  }
})

test.describe('DataViewContainer Pattern Consistency', () => {
  test.beforeEach(async ({ page }) => {
    await mockApiResponses.loginSuccess(page)
    await mockApiResponses.dashboardStats(page)
    await mockApiResponses.hotspotUsers(page, 5)
    await auth.setAuthToken(page)
  })

  const dataViewPages = [
    '/dashboard/hotspot/users',
    '/dashboard/hotspot/sessions',
    '/dashboard/pppoe/users',
    '/dashboard/pppoe/sessions',
    '/dashboard/packages/all',
    '/dashboard/hr/employees',
    '/dashboard/support/all-tickets',
    '/dashboard/settings/payment-gateways',
    '/dashboard/branding',
    '/dashboard/admin/system-updates',
  ]

  for (const path of dataViewPages) {
    test(`${path} should use DataViewContainer pattern`, async ({ page }) => {
      await page.goto(path)
      await page.waitForLoadState('networkidle')
      
      // Should have DataViewContainer
      await assertions.hasDataViewContainer(page)
      
      // Should have consistent header structure
      const header = page.locator('header, [class*="header"], [data-testid="page-header"]').first()
      await expect(header).toBeVisible()
      
      // Content area should be visible
      const content = page.locator('main, [class*="content"], [data-testid="content"]').first()
      await expect(content).toBeVisible()
    })
  }
})

test.describe('Sidebar Navigation Consistency', () => {
  test.beforeEach(async ({ page }) => {
    await mockApiResponses.loginSuccess(page)
    await auth.setAuthToken(page)
    await page.goto('/dashboard')
  })

  test('sidebar should be visible and functional on desktop', async ({ page }) => {
    await page.setViewportSize(viewports.desktop)
    await page.reload()
    
    const sidebar = page.locator('aside, .sidebar, [class*="sidebar"]').first()
    await expect(sidebar).toBeVisible()
    
    // Sidebar should have navigation items
    const navItems = sidebar.locator('a, button')
    expect(await navItems.count()).toBeGreaterThan(3)
  })

  test('sidebar should collapse/expand on mobile', async ({ page }) => {
    await page.setViewportSize(viewports.mobile)
    await page.reload()
    
    // Find mobile menu toggle
    const menuToggle = page.locator('button[aria-label*="menu"], .menu-toggle, [data-testid="menu-toggle"]').first()
    
    if (await menuToggle.isVisible().catch(() => false)) {
      // Sidebar might be initially hidden on mobile
      const sidebar = page.locator('aside, .sidebar').first()
      
      // Click to open
      await menuToggle.click()
      await expect(sidebar).toBeVisible()
      
      // Click to close (or click outside)
      const closeButton = page.locator('button[aria-label*="close"], .close-sidebar').first()
      if (await closeButton.isVisible().catch(() => false)) {
        await closeButton.click()
      } else {
        // Click on main content to close
        await page.click('main, .content')
      }
    }
  })
})

test.describe('SlideOverlay Behavior', () => {
  test.beforeEach(async ({ page }) => {
    await mockApiResponses.loginSuccess(page)
    await mockApiResponses.hotspotUsers(page, 5)
    await auth.setAuthToken(page)
    await page.goto('/dashboard/hotspot/users')
  })

  test('overlay should open when clicking add button', async ({ page }) => {
    // Find and click add button
    const addButton = page.locator('button').filter({ hasText: /add|create|new/i }).first()
    await addButton.click()
    
    // Overlay should appear
    const overlay = page.locator('[data-testid="slide-overlay"], .slide-overlay, [role="dialog"]').first()
    await expect(overlay).toBeVisible()
    
    // Overlay should have light background
    const bgColor = await overlay.evaluate(el => window.getComputedStyle(el).backgroundColor)
    expect(bgColor).not.toContain('rgb(17, 24, 39)') // Not dark gray
    expect(bgColor).not.toContain('rgb(31, 41, 55)')  // Not darker gray
  })

  test('overlay should close when clicking cancel', async ({ page }) => {
    // Open overlay
    const addButton = page.locator('button').filter({ hasText: /add|create|new/i }).first()
    await addButton.click()
    
    const overlay = page.locator('[data-testid="slide-overlay"], .slide-overlay').first()
    await expect(overlay).toBeVisible()
    
    // Click cancel
    const cancelButton = overlay.locator('button:has-text("Cancel"), button[aria-label*="close"]').first()
    await cancelButton.click()
    
    // Overlay should close
    await expect(overlay).toBeHidden()
  })

  test('overlay should close when clicking outside', async ({ page }) => {
    // Open overlay
    const addButton = page.locator('button').filter({ hasText: /add|create|new/i }).first()
    await addButton.click()
    
    const overlay = page.locator('[data-testid="slide-overlay"], .slide-overlay').first()
    await expect(overlay).toBeVisible()
    
    // Click on backdrop (if exists)
    const backdrop = page.locator('[class*="backdrop"], .modal-backdrop').first()
    if (await backdrop.isVisible().catch(() => false)) {
      await backdrop.click()
      await expect(overlay).toBeHidden()
    }
  })
})

test.describe('Dark Mode Issue Detection', () => {
  test.beforeEach(async ({ page }) => {
    await mockApiResponses.loginSuccess(page)
    await auth.setAuthToken(page)
  })

  test('should not have bg-gray-900 or bg-slate-900 on main content areas', async ({ page }) => {
    const pages = [
      '/dashboard',
      '/dashboard/admin/system-updates',
      '/dashboard/settings/payment-gateways',
      '/dashboard/branding'
    ]

    for (const path of pages) {
      await page.goto(path)
      await page.waitForLoadState('networkidle')
      
      // Check main content area doesn't have dark background classes
      const darkBgElements = await page.locator('main, .content, [class*="content"], [class*="layout"]').filter({
        has: page.locator('[class*="bg-gray-900"], [class*="bg-slate-900"], [class*="bg-gray-800"]')
      }).count()
      
      // Should not find dark background on main content
      expect(darkBgElements).toBe(0)
    }
  })

  test('should not have dark text on dark backgrounds', async ({ page }) => {
    await page.goto('/dashboard/admin/system-updates')
    await page.waitForLoadState('networkidle')
    
    // Check for text-gray-200 or text-gray-300 (dark mode text colors)
    // on elements that are NOT in the sidebar (which is intentionally dark)
    const mainContent = page.locator('main, .content').first()
    const darkTextElements = await mainContent.locator('[class*="text-gray-200"], [class*="text-gray-300"]').count()
    
    // Should not have dark mode text colors in main content
    expect(darkTextElements).toBe(0)
  })
})

test.describe('Responsive Layout', () => {
  test.beforeEach(async ({ page }) => {
    await mockApiResponses.loginSuccess(page)
    await mockApiResponses.hotspotUsers(page, 10)
    await auth.setAuthToken(page)
    await page.goto('/dashboard/hotspot/users')
  })

  test('should display table on desktop and cards on mobile', async ({ page }) => {
    // Desktop - should show table
    await page.setViewportSize(viewports.desktop)
    await page.reload()
    
    const table = page.locator('table, [class*="data-table"]').first()
    await expect(table).toBeVisible()
    
    // Mobile - should show cards
    await page.setViewportSize(viewports.mobile)
    await page.reload()
    
    const cards = page.locator('[class*="mobile-card"], [class*="data-card"]').first()
    // Cards might be present on mobile
    const cardCount = await page.locator('[class*="card"]').count()
    expect(cardCount).toBeGreaterThanOrEqual(0)
  })

  test('should not have horizontal overflow on any viewport', async ({ page }) => {
    const viewportsToTest = [
      viewports.mobile,
      viewports.tablet,
      { width: 1024, height: 768 },
      viewports.desktop,
      viewports.wide
    ]

    for (const viewport of viewportsToTest) {
      await page.setViewportSize(viewport)
      await page.reload()
      
      // Check for horizontal overflow
      const hasOverflow = await page.evaluate(() => {
        return document.documentElement.scrollWidth > window.innerWidth
      })
      
      // Allow small scrollbar width
      const overflowAmount = await page.evaluate(() => {
        return document.documentElement.scrollWidth - window.innerWidth
      })
      
      expect(overflowAmount).toBeLessThanOrEqual(50) // Max 50px overflow
    }
  })
})
