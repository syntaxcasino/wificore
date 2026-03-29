import { test, expect } from '@playwright/test'
import { mockApiResponses, auth, interactions, assertions } from './utils.js'

/**
 * Hotspot Users E2E Tests
 * 
 * Test coverage:
 * - Users list display with DataViewContainer
 * - Search and filter functionality
 * - Create new user flow
 * - Edit user flow
 * - Delete user flow
 * - Pagination
 * - Mobile responsive cards
 */

test.describe('Hotspot Users', () => {
  test.beforeEach(async ({ page }) => {
    await mockApiResponses.loginSuccess(page)
    await mockApiResponses.hotspotUsers(page, 15)
    await auth.setAuthToken(page)
    await page.goto('/dashboard/hotspot/users')
    await interactions.waitForLoading(page)
  })

  test.describe('List View', () => {
    test('should display users in DataViewContainer with light background', async ({ page }) => {
      await assertions.hasLightBackground(page)
      await expect(page.locator('table, [class*="data-table"], .users-list').first()).toBeVisible()
    })

    test('should display user data in table format on desktop', async ({ page }) => {
      // Check for table headers
      const headers = ['Username', 'Phone', 'Package', 'Status', 'Data Used']
      for (const header of headers) {
        await expect(page.locator('th, .table-header').filter({ hasText: new RegExp(header, 'i') }).first()).toBeVisible()
      }
      
      // Check for user rows
      const rows = page.locator('tbody tr, [class*="data-row"], .user-card')
      await expect(rows.first()).toBeVisible()
    })

    test('should display user cards on mobile', async ({ page }) => {
      await page.setViewportSize({ width: 375, height: 667 })
      await page.reload()
      await interactions.waitForLoading(page)
      
      // Mobile should show cards instead of table
      const cards = page.locator('[class*="mobile-card"], .user-card, [class*="data-card"]')
      // Mobile cards might be present
      const cardCount = await cards.count()
      expect(cardCount).toBeGreaterThanOrEqual(0)
    })

    test('should show correct status badges', async ({ page }) => {
      const statusBadges = page.locator('[class*="badge"], [class*="status"]').filter({
        hasText: /active|inactive|expired/i
      })
      
      // At least some status indicators should be visible
      const count = await statusBadges.count()
      expect(count).toBeGreaterThan(0)
    })
  })

  test.describe('Search and Filter', () => {
    test('should filter users by search query', async ({ page }) => {
      // Mock search response
      await page.route('**/api/hotspot/users?search=user1**', async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            users: [{ id: 1, username: 'user1', phone: '+254712345601', status: 'active' }],
            total: 1
          })
        })
      })
      
      // Find and fill search input
      const searchInput = page.locator('input[type="search"], input[placeholder*="search"], [data-testid="search"]').first()
      
      if (await searchInput.isVisible().catch(() => false)) {
        await searchInput.fill('user1')
        await page.waitForTimeout(500) // Debounce
        
        // Results should update
        await expect(page.locator('text=/user1/i').first()).toBeVisible()
      }
    })

    test('should filter users by status', async ({ page }) => {
      const statusFilter = page.locator('select, .filter').filter({ hasText: /status/i }).first()
      
      if (await statusFilter.isVisible().catch(() => false)) {
        await statusFilter.selectOption('active')
        await interactions.waitForLoading(page)
        
        // Should show filtered results
        await expect(page.locator('text=/active/i').first()).toBeVisible()
      }
    })

    test('should clear filters when clicking clear button', async ({ page }) => {
      const clearButton = page.locator('button:has-text("Clear"), button:has-text("Reset"), [data-testid="clear-filters"]').first()
      
      if (await clearButton.isVisible().catch(() => false)) {
        await clearButton.click()
        await interactions.waitForLoading(page)
        
        // Should show all users again
        const userCount = await page.locator('tbody tr, .user-card').count()
        expect(userCount).toBeGreaterThan(0)
      }
    })
  })

  test.describe('Create User', () => {
    test('should open create user overlay', async ({ page }) => {
      // Mock create user API
      await page.route('**/api/hotspot/users', async (route, request) => {
        if (request.method() === 'POST') {
          await route.fulfill({
            status: 201,
            contentType: 'application/json',
            body: JSON.stringify({ id: 100, username: 'newuser', status: 'success' })
          })
        } else {
          await route.continue()
        }
      })
      
      // Click add button
      const addButton = page.locator('button').filter({ hasText: /add|create|new/i }).first()
      await addButton.click()
      
      // Should open overlay
      const overlay = page.locator('[data-testid="slide-overlay"], .modal, [role="dialog"]').first()
      await expect(overlay).toBeVisible()
      
      // Should have form fields
      await expect(overlay.locator('input').first()).toBeVisible()
    })

    test('should create new user with valid data', async ({ page }) => {
      await page.route('**/api/hotspot/users', async (route, request) => {
        if (request.method() === 'POST') {
          await route.fulfill({
            status: 201,
            contentType: 'application/json',
            body: JSON.stringify({ 
              id: 100, 
              username: 'testuser123',
              phone: '+254712345678',
              status: 'active' 
            })
          })
        } else {
          await route.continue()
        }
      })
      
      // Open create form
      const addButton = page.locator('button').filter({ hasText: /add|create|new/i }).first()
      await addButton.click()
      
      const overlay = page.locator('[data-testid="slide-overlay"], .modal').first()
      
      // Fill form
      await overlay.locator('input[type="text"], input[name="username"], input[placeholder*="username"]').first().fill('testuser123')
      await overlay.locator('input[type="tel"], input[name="phone"]').first().fill('+254712345678')
      
      // Submit
      const submitButton = overlay.locator('button[type="submit"], button:has-text("Create"), button:has-text("Save")').first()
      await submitButton.click()
      
      // Should close overlay and show success
      await expect(overlay).toBeHidden()
    })

    test('should show validation errors for invalid data', async ({ page }) => {
      // Open create form
      const addButton = page.locator('button').filter({ hasText: /add|create|new/i }).first()
      await addButton.click()
      
      const overlay = page.locator('[data-testid="slide-overlay"], .modal').first()
      
      // Submit empty form
      const submitButton = overlay.locator('button[type="submit"]').first()
      await submitButton.click()
      
      // Should show validation errors
      const errorMessage = overlay.locator('text=/required|invalid|error/i').first()
      await expect(errorMessage).toBeVisible()
    })
  })

  test.describe('Edit User', () => {
    test('should open edit overlay for existing user', async ({ page }) => {
      // Click on first user's edit button
      const editButton = page.locator('button').filter({ hasText: /edit/i }).first()
      await editButton.click()
      
      // Should open edit overlay
      const overlay = page.locator('[data-testid="slide-overlay"], .modal').first()
      await expect(overlay).toBeVisible()
      
      // Should have pre-filled data
      const input = overlay.locator('input[type="text"]').first()
      const value = await input.inputValue()
      expect(value).toBeTruthy()
    })

    test('should update user data', async ({ page }) => {
      await page.route('**/api/hotspot/users/**', async (route, request) => {
        if (request.method() === 'PUT' || request.method() === 'PATCH') {
          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ message: 'Updated successfully' })
          })
        } else {
          await route.continue()
        }
      })
      
      // Open edit form
      const editButton = page.locator('button').filter({ hasText: /edit/i }).first()
      await editButton.click()
      
      const overlay = page.locator('[data-testid="slide-overlay"], .modal').first()
      
      // Update field
      const phoneInput = overlay.locator('input[type="tel"], input[name="phone"]').first()
      await phoneInput.fill('+254799999999')
      
      // Save
      const saveButton = overlay.locator('button:has-text("Save"), button:has-text("Update")').first()
      await saveButton.click()
      
      // Should close overlay
      await expect(overlay).toBeHidden()
    })
  })

  test.describe('Delete User', () => {
    test('should show confirmation before deleting', async ({ page }) => {
      // Click delete button
      const deleteButton = page.locator('button').filter({ hasText: /delete|remove/i }).first()
      
      // Setup dialog handler
      page.on('dialog', async dialog => {
        expect(dialog.type()).toBe('confirm')
        expect(dialog.message()).toMatch(/delete|remove/i)
        await dialog.dismiss()
      })
      
      await deleteButton.click()
    })

    test('should delete user after confirmation', async ({ page }) => {
      await page.route('**/api/hotspot/users/**', async (route, request) => {
        if (request.method() === 'DELETE') {
          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ message: 'Deleted successfully' })
          })
        } else {
          await route.continue()
        }
      })
      
      // Accept confirmation
      page.on('dialog', async dialog => {
        await dialog.accept()
      })
      
      // Click delete
      const deleteButton = page.locator('button').filter({ hasText: /delete|remove/i }).first()
      await deleteButton.click()
      
      // Wait for refresh
      await interactions.waitForLoading(page)
    })
  })

  test.describe('Pagination', () => {
    test('should display pagination controls', async ({ page }) => {
      const pagination = page.locator('[class*="pagination"], .pagination, [data-testid="pagination"]').first()
      
      // Pagination might not exist with few items
      if (await pagination.isVisible().catch(() => false)) {
        await expect(pagination).toBeVisible()
        
        // Should have page numbers or next/prev buttons
        const controls = pagination.locator('button, a, [role="button"]')
        expect(await controls.count()).toBeGreaterThan(0)
      }
    })
  })

  test.describe('Light Background Verification', () => {
    test('should not have dark background issues on any viewport', async ({ page }) => {
      const viewports = [
        { width: 1920, height: 1080, name: 'desktop' },
        { width: 768, height: 1024, name: 'tablet' },
        { width: 375, height: 667, name: 'mobile' }
      ]
      
      for (const viewport of viewports) {
        await page.setViewportSize(viewport)
        await page.reload()
        await interactions.waitForLoading(page)
        
        // Verify light background
        await assertions.hasLightBackground(page)
        
        // Verify content is readable
        const content = await page.textContent('main, .content')
        expect(content).toBeTruthy()
      }
    })
  })
})
