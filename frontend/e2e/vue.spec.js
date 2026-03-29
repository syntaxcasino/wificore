import { test, expect } from '@playwright/test'

/**
 * Basic Application Smoke Test
 * 
 * This test verifies the application loads correctly and basic routing works.
 */

test('visits the app root url and verifies app loads', async ({ page }) => {
  await page.goto('/')
  
  // Wait for the app to load
  await page.waitForLoadState('networkidle')
  
  // Check that the page has loaded by looking for common elements
  // The app should either show login page or redirect to it
  const body = await page.textContent('body')
  
  // Should contain some content (login form, dashboard, or loading state)
  expect(body).toBeTruthy()
  expect(body.length).toBeGreaterThan(0)
  
  // Check that no major console errors occurred
  const consoleErrors = []
  page.on('console', msg => {
    if (msg.type() === 'error') {
      consoleErrors.push(msg.text())
    }
  })
  
  // Give time for any errors to be logged
  await page.waitForTimeout(500)
  
  // No critical JavaScript errors should be present
  const criticalErrors = consoleErrors.filter(err => 
    !err.includes('favicon') && 
    !err.includes('source map')
  )
  
  expect(criticalErrors).toHaveLength(0)
})

test('application should have proper viewport meta tag', async ({ page }) => {
  await page.goto('/')
  
  // Check for responsive viewport meta tag
  const viewport = await page.$eval('meta[name="viewport"]', el => el.getAttribute('content'))
  expect(viewport).toContain('width=device-width')
})

test('application should have proper title', async ({ page }) => {
  await page.goto('/')
  
  // Check page title exists
  const title = await page.title()
  expect(title).toBeTruthy()
  expect(title.length).toBeGreaterThan(0)
})
