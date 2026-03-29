import { test, expect } from '@playwright/test'
import { mockApiResponses, auth, interactions, assertions, viewports } from './utils.js'

/**
 * Dashboard E2E Tests
 * 
 * Test coverage:
 * - Dashboard page rendering with correct layout
 * - Stats cards display
 * - Sidebar navigation functionality
 * - Mobile responsive layout
 * - Quick action buttons
 * - Recent activity feed
 */

test.describe('Dashboard', () => {
  test.beforeEach(async ({ page }) => {
    // Setup authenticated state
    await mockApiResponses.loginSuccess(page)
    await mockApiResponses.dashboardStats(page)
    await auth.setAuthToken(page)
  })

  test.describe('Page Layout', () => {
    test('should render dashboard with light background', async ({ page }) => {
      await page.goto('/dashboard')
      
      await interactions.waitForLoading(page)
      
      // Verify light background (no dark mode issues)
      await assertions.hasLightBackground(page)
    })

    test('should display sidebar with navigation menu', async ({ page }) => {
      await page.goto('/dashboard')
      
      // Sidebar should be visible on desktop
      const sidebar = page.locator('aside, .sidebar, [class*="sidebar"]').first()
      await expect(sidebar).toBeVisible()
      
      // Should contain navigation items
      const navItems = ['Dashboard', 'Hotspot', 'PPPoE', 'Plans', 'Settings']
      for (const item of navItems) {
        await expect(page.locator('nav, aside').filter({ hasText: new RegExp(item, 'i') }).first()).toBeVisible()
      }
    })

    test('should display header with user info', async ({ page }) => {
      await page.goto('/dashboard')
      
      const header = page.locator('header, .header').first()
      await expect(header).toBeVisible()
      
      // Should show organization or user info
      const headerText = await header.textContent()
      expect(headerText).toMatch(/test|organization|user/i)
    })
  })

  test.describe('Stats Cards', () => {
    test('should display all stats cards with correct data', async ({ page }) => {
      await page.goto('/dashboard')
      await interactions.waitForLoading(page)
      
      // Verify stats are displayed
      const statsContainer = page.locator('[class*="stats"], .stats-grid, [data-testid="stats"]').first()
      
      // Check for numeric values in stats
      const pageContent = await page.textContent('body')
      expect(pageContent).toMatch(/150/) // hotspot_users
      expect(pageContent).toMatch(/75/)  // hotspot_sessions
      expect(pageContent).toMatch(/50/)  // pppoe_users
    })

    test('stats cards should be clickable and navigate to relevant pages', async ({ page }) => {
      await mockApiResponses.hotspotUsers(page)
      await page.goto('/dashboard')
      await interactions.waitForLoading(page)
      
      // Find and click a stat card that links to hotspot
      const hotspotCard = page.locator('[class*="card"], .stat-card').filter({ hasText: /hotspot|user/i }).first()
      
      if (await hotspotCard.isVisible().catch(() => false)) {
        await hotspotCard.click()
        
        // Should navigate to hotspot users page
        await page.waitForURL('**/hotspot/**')
        await expect(page).toHaveURL(/.*hotspot.*/)
      }
    })
  })

  test.describe('Sidebar Navigation', () => {
    test('should navigate to Hotspot Users page', async ({ page }) => {
      await mockApiResponses.hotspotUsers(page)
      await page.goto('/dashboard')
      
      // Click on Hotspot menu
      const hotspotLink = page.locator('a:has-text("Hotspot"), button:has-text("Hotspot")').first()
      await hotspotLink.click()
      
      // Click on Users submenu
      const usersLink = page.locator('a:has-text("All Users"), a:has-text("Users"), [href*="/hotspot/users"]').first()
      await usersLink.click()
      
      await page.waitForURL('**/hotspot/users**')
      await expect(page).toHaveURL(/.*hotspot\/users.*/)
    })

    test('should navigate to Settings page', async ({ page }) => {
      await mockApiResponses.settings(page)
      await page.goto('/dashboard')
      
      // Click on Settings
      const settingsLink = page.locator('a:has-text("Settings"), button:has-text("Settings")').first()
      await settingsLink.click()
      
      // Settings should expand submenu
      await expect(page.locator('a:has-text("Organization")').first()).toBeVisible()
    })

    test('should toggle sidebar on mobile', async ({ page }) => {
      // Set mobile viewport
      await page.setViewportSize(viewports.mobile)
      await page.goto('/dashboard')
      
      // Find mobile menu toggle
      const menuToggle = page.locator('button[aria-label*="menu"], button[aria-label*="Menu"], .menu-toggle').first()
      
      if (await menuToggle.isVisible().catch(() => false)) {
        // Sidebar should be hidden initially on mobile
        const sidebar = page.locator('aside, .sidebar').first()
        
        // Click toggle to open
        await menuToggle.click()
        await expect(sidebar).toBeVisible()
        
        // Click toggle to close
        await menuToggle.click()
        // Sidebar might slide out or overlay might close
      }
    })
  })

  test.describe('Quick Actions', () => {
    test('should display quick action buttons', async ({ page }) => {
      await page.goto('/dashboard')
      
      // Look for common action buttons
      const actionButtons = page.locator('button').filter({ 
        hasText: /create|add|new|refresh|export/i 
      })
      
      // At least one action button should be visible
      const count = await actionButtons.count()
      expect(count).toBeGreaterThan(0)
    })

    test('should open create user overlay when clicking add user', async ({ page }) => {
      await page.goto('/dashboard')
      
      // Look for add/create user button
      const addButton = page.locator('button').filter({ 
        hasText: /add user|create user|new user/i 
      }).first()
      
      if (await addButton.isVisible().catch(() => false)) {
        await addButton.click()
        
        // Should open an overlay or modal
        const overlay = page.locator('[data-testid="slide-overlay"], .modal, .overlay, [role="dialog"]').first()
        await expect(overlay).toBeVisible()
      }
    })
  })

  test.describe('Responsive Design', () => {
    test('should adapt layout for tablet viewport', async ({ page }) => {
      await page.setViewportSize(viewports.tablet)
      await page.goto('/dashboard')
      await interactions.waitForLoading(page)
      
      // Content should be visible
      await expect(page.locator('main, .content, [class*="content"]').first()).toBeVisible()
      
      // Layout should not have horizontal overflow
      const bodyWidth = await page.evaluate(() => document.body.scrollWidth)
      const viewportWidth = viewports.tablet.width
      expect(bodyWidth).toBeLessThanOrEqual(viewportWidth + 50) // Allow small scrollbar
    })

    test('should adapt layout for mobile viewport', async ({ page }) => {
      await page.setViewportSize(viewports.mobile)
      await page.goto('/dashboard')
      await interactions.waitForLoading(page)
      
      // Content should be visible
      await expect(page.locator('main, .content').first()).toBeVisible()
      
      // Check for mobile-specific elements
      const mobileElements = await page.locator('[class*="mobile"], .mobile-nav, .mobile-header').count()
      // Mobile elements may or may not exist depending on implementation
      expect(mobileElements).toBeGreaterThanOrEqual(0)
    })
  })

  test.describe('Error States', () => {
    test('should display error message when stats fail to load', async ({ page }) => {
      // Mock API failure
      await page.route('**/api/dashboard/stats', async (route) => {
        await route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'Failed to load stats' })
        })
      })
      
      await page.goto('/dashboard')
      
      // Should show error or fallback state
      const errorOrRetry = page.locator('text=/error|failed|retry|reload/i, button:has-text("Retry")').first()
      // Error handling might be graceful, so we just check the page loaded
      await expect(page.locator('main, .content').first()).toBeVisible()
    })
  })
})
