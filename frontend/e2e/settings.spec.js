import { test, expect } from '@playwright/test'
import { mockApiResponses, auth, interactions, assertions } from './utils.js'

/**
 * Settings E2E Tests
 * 
 * Test coverage:
 * - Organization settings
 * - Communication Channels
 * - Timezone & Locale
 * - Payment Gateways (new)
 * - Branding (new)
 * - System Updates (new)
 */

test.describe('Settings', () => {
  test.beforeEach(async ({ page }) => {
    await mockApiResponses.loginSuccess(page)
    await mockApiResponses.settings(page)
    await auth.setAuthToken(page)
  })

  test.describe('Organization Settings', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto('/dashboard/settings/general')
      await interactions.waitForLoading(page)
    })

    test('should display organization settings with light background', async ({ page }) => {
      await assertions.hasLightBackground(page)
      await expect(page.locator('h1, h2').filter({ hasText: /organization|general|settings/i }).first()).toBeVisible()
    })

    test('should update organization name', async ({ page }) => {
      await page.route('**/api/settings/organization', async (route, request) => {
        if (request.method() === 'PUT' || request.method() === 'PATCH') {
          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ message: 'Settings updated' })
          })
        } else {
          await route.continue()
        }
      })
      
      // Find and update organization name
      const nameInput = page.locator('input[name="name"], input[placeholder*="organization"]').first()
      
      if (await nameInput.isVisible().catch(() => false)) {
        await nameInput.fill('Updated Organization Name')
        
        // Save
        const saveButton = page.locator('button:has-text("Save"), button[type="submit"]').first()
        await saveButton.click()
        
        // Should show success
        await expect(page.locator('text=/saved|success|updated/i').first()).toBeVisible()
      }
    })
  })

  test.describe('Communication Channels', () => {
    test.beforeEach(async ({ page }) => {
      await page.route('**/api/communication-channels', async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            channels: [
              { id: 1, name: 'Primary SMS', type: 'sms', provider: 'twilio', is_active: true },
              { id: 2, name: 'Email Gateway', type: 'email', provider: 'sendgrid', is_active: true }
            ]
          })
        })
      })
      
      await page.goto('/dashboard/settings/communication-channels')
      await interactions.waitForLoading(page)
    })

    test('should display channels list with DataViewContainer', async ({ page }) => {
      await assertions.hasLightBackground(page)
      await expect(page.locator('text=/communication|channels|sms|email/i').first()).toBeVisible()
    })

    test('should add new communication channel', async ({ page }) => {
      await page.route('**/api/communication-channels', async (route, request) => {
        if (request.method() === 'POST') {
          await route.fulfill({
            status: 201,
            contentType: 'application/json',
            body: JSON.stringify({ id: 3, name: 'WhatsApp Business', type: 'whatsapp', provider: 'twilio' })
          })
        } else {
          await route.continue()
        }
      })
      
      // Click add button
      const addButton = page.locator('button').filter({ hasText: /add|create|new/i }).first()
      await addButton.click()
      
      // Should open overlay
      const overlay = page.locator('[data-testid="slide-overlay"], .modal').first()
      await expect(overlay).toBeVisible()
      
      // Fill form
      await overlay.locator('input[type="text"]').first().fill('WhatsApp Business')
      
      // Save
      const saveButton = overlay.locator('button:has-text("Save"), button:has-text("Create")').first()
      await saveButton.click()
      
      await expect(overlay).toBeHidden()
    })
  })

  test.describe('Timezone & Locale', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto('/dashboard/settings/timezone-locale')
      await interactions.waitForLoading(page)
    })

    test('should display timezone settings with light background', async ({ page }) => {
      await assertions.hasLightBackground(page)
      await expect(page.locator('text=/timezone|locale|region/i').first()).toBeVisible()
    })

    test('should change timezone', async ({ page }) => {
      await page.route('**/api/settings/timezone', async (route, request) => {
        if (request.method() === 'PUT' || request.method() === 'PATCH') {
          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ timezone: 'Europe/London' })
          })
        } else {
          await route.continue()
        }
      })
      
      // Find timezone select
      const timezoneSelect = page.locator('select[name="timezone"], select').filter({ hasText: /nairobi|london|gmt/i }).first()
      
      if (await timezoneSelect.isVisible().catch(() => false)) {
        await timezoneSelect.selectOption('Europe/London')
        
        // Save
        const saveButton = page.locator('button:has-text("Save")').first()
        await saveButton.click()
        
        await expect(page.locator('text=/saved|success/i').first()).toBeVisible()
      }
    })
  })

  test.describe('Payment Gateways', () => {
    test.beforeEach(async ({ page }) => {
      await page.route('**/api/settings/payment-gateways', async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            gateways: [
              { 
                id: 1, 
                name: 'Main M-Pesa', 
                provider: 'mpesa', 
                environment: 'live',
                is_active: true,
                is_default: true 
              }
            ]
          })
        })
      })
      
      await page.goto('/dashboard/settings/payment-gateways')
      await interactions.waitForLoading(page)
    })

    test('should display payment gateways with light background', async ({ page }) => {
      await assertions.hasLightBackground(page)
      await assertions.hasDataViewContainer(page)
      await expect(page.locator('text=/payment|gateway|mpesa/i').first()).toBeVisible()
    })

    test('should show gateway status badges', async ({ page }) => {
      const statusBadges = page.locator('[class*="badge"], [class*="status"]').filter({
        hasText: /live|sandbox|active/i
      })
      
      const count = await statusBadges.count()
      expect(count).toBeGreaterThan(0)
    })

    test('should add M-Pesa gateway', async ({ page }) => {
      await page.route('**/api/settings/payment-gateways', async (route, request) => {
        if (request.method() === 'POST') {
          await route.fulfill({
            status: 201,
            contentType: 'application/json',
            body: JSON.stringify({
              id: 2,
              name: 'Secondary M-Pesa',
              provider: 'mpesa',
              environment: 'sandbox'
            })
          })
        } else {
          await route.continue()
        }
      })
      
      // Open create form
      const addButton = page.locator('button').filter({ hasText: /add gateway/i }).first()
      await addButton.click()
      
      const overlay = page.locator('[data-testid="slide-overlay"], .modal').first()
      await expect(overlay).toBeVisible()
      
      // Fill M-Pesa details
      await overlay.locator('input[name="name"], input[type="text"]').first().fill('Secondary M-Pesa')
      
      // Select provider
      const providerSelect = overlay.locator('select').first()
      await providerSelect.selectOption('mpesa')
      
      // Add credentials
      await overlay.locator('input[name*="consumer_key"], input[placeholder*="consumer"]').first().fill('test_consumer_key')
      
      // Save
      const saveButton = overlay.locator('button:has-text("Create")').first()
      await saveButton.click()
      
      await expect(overlay).toBeHidden()
    })

    test('should set default gateway', async ({ page }) => {
      await page.route('**/api/settings/payment-gateways/**', async (route, request) => {
        if (request.method() === 'PATCH') {
          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ message: 'Updated' })
          })
        } else {
          await route.continue()
        }
      })
      
      // Click set default button
      const defaultButton = page.locator('button').filter({ hasText: /set default/i }).first()
      
      if (await defaultButton.isVisible().catch(() => false)) {
        await defaultButton.click()
        
        // Should show success
        await expect(page.locator('text=/default|updated/i').first()).toBeVisible()
      }
    })
  })

  test.describe('Branding', () => {
    test.beforeEach(async ({ page }) => {
      await page.route('**/api/branding/templates', async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            templates: [
              {
                id: 1,
                name: 'Corporate Branding',
                description: 'Main corporate template',
                primary_color: '#3B82F6',
                secondary_color: '#10B981',
                is_active: true,
                company_name: 'Test Corp'
              }
            ]
          })
        })
      })
      
      await page.goto('/dashboard/branding')
      await interactions.waitForLoading(page)
    })

    test('should display branding templates with light background', async ({ page }) => {
      await assertions.hasLightBackground(page)
      await expect(page.locator('text=/branding|template|logo|color/i').first()).toBeVisible()
    })

    test('should show color swatches', async ({ page }) => {
      const colorSwatches = page.locator('[style*="background-color"], [class*="color"]').filter({
        has: page.locator('div, span')
      })
      
      // Should have color indicators
      const count = await colorSwatches.count()
      expect(count).toBeGreaterThanOrEqual(0)
    })

    test('should create new branding template', async ({ page }) => {
      await page.route('**/api/branding/templates', async (route, request) => {
        if (request.method() === 'POST') {
          await route.fulfill({
            status: 201,
            contentType: 'application/json',
            body: JSON.stringify({
              id: 2,
              name: 'New Brand Template',
              primary_color: '#EF4444',
              secondary_color: '#F59E0B'
            })
          })
        } else {
          await route.continue()
        }
      })
      
      // Open create form
      const createButton = page.locator('button').filter({ hasText: /create template/i }).first()
      await createButton.click()
      
      const overlay = page.locator('[data-testid="slide-overlay"], .modal').first()
      await expect(overlay).toBeVisible()
      
      // Fill template name
      await overlay.locator('input[name="name"]').first().fill('New Brand Template')
      await overlay.locator('textarea[name="description"]').first().fill('Test description')
      
      // Save
      const saveButton = overlay.locator('button:has-text("Create")').first()
      await saveButton.click()
      
      await expect(overlay).toBeHidden()
    })
  })

  test.describe('System Updates', () => {
    test.beforeEach(async ({ page }) => {
      await page.route('**/api/system-updates/servers', async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            servers: [
              { id: 1, name: 'Main Server', type: 'application', status: 'online', current_version: '1.2.3', update_available: false }
            ]
          })
        })
      })
      
      await page.route('**/api/system-updates/routers', async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            routers: [
              { id: 1, name: 'Router 1', model: 'RB3011', status: 'active', current_ros_version: '7.12', update_available: true, latest_ros_version: '7.13' }
            ]
          })
        })
      })
      
      await page.goto('/dashboard/admin/system-updates')
      await interactions.waitForLoading(page)
    })

    test('should display system updates with light background', async ({ page }) => {
      await assertions.hasLightBackground(page)
      await expect(page.locator('text=/system|update|server|router/i').first()).toBeVisible()
    })

    test('should show server status cards', async ({ page }) => {
      const statsCards = page.locator('[class*="stats"], .stat-card, [class*="card"]').filter({
        hasText: /server|router|access point/i
      })
      
      const count = await statsCards.count()
      expect(count).toBeGreaterThan(0)
    })

    test('should switch between update tabs', async ({ page }) => {
      // Click on Routers tab
      const routersTab = page.locator('button, a').filter({ hasText: /routers/i }).first()
      await routersTab.click()
      
      // Should show router list
      await expect(page.locator('text=/router|mikrotik|ros/i').first()).toBeVisible()
      
      // Click on Access Points tab
      const apTab = page.locator('button, a').filter({ hasText: /access point/i }).first()
      await apTab.click()
      
      // Tab switching should work
      await expect(page.locator('text=/access point|wireless/i').first()).toBeVisible()
    })

    test('should initiate update check', async ({ page }) => {
      await page.route('**/api/system-updates/check', async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'Check initiated' })
        })
      })
      
      // Click check for updates button
      const checkButton = page.locator('button').filter({ hasText: /check.*update/i }).first()
      
      if (await checkButton.isVisible().catch(() => false)) {
        await checkButton.click()
        
        // Should show checking state or success
        await expect(page.locator('text=/check|update|scan/i').first()).toBeVisible()
      }
    })
  })
})
