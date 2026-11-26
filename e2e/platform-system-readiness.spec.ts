import { expect, test } from '@playwright/test'

test('platform operator can review integration readiness without sample data', async ({ page }) => {
  let signedIn = true
  const account = { id: 84, name: 'Platform Operator', email: 'operator@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: true }

  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/me', route => route.fulfill({ status: signedIn ? 200 : 401, contentType: 'application/json', body: JSON.stringify(signedIn ? { data: account } : { message: 'Unauthenticated.' }) }))
  await page.route('**/api/v1/auth/login', route => {
    signedIn = true
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: account }) })
  })
  await page.route('**/api/v1/organizations*', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], preferred_organization_id: null, meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }) }))
  await page.route('**/api/v1/platform/overview', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { organizations: { total: 0, active: 0, suspended: 0 }, users: { total: 1, verified: 1 }, educators: 0, open_enquiries: 0, recent_organizations: [] } }) }))
  await page.route('**/api/v1/platform/system-readiness', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
    services: [
      { key: 'email', name: 'Email delivery', status: 'local_only', detail: 'The current mail transport is for local or test use; messages will not reach an inbox.' },
      { key: 'google_meet', name: 'Google Meet', status: 'optional', detail: 'Optional room creation is not configured. Staff can still use manual HTTPS meeting links.' },
    ],
    security_note: 'This view reports configuration signals only. It never returns credentials and does not change provider settings.',
  } }) }))

  await page.goto('/workspace/super-admin')
  await page.getByRole('button', { name: 'System settings', exact: true }).click()

  await expect(page.getByRole('heading', { name: 'Integration readiness' })).toBeVisible({ timeout: 30_000 })
  await expect(page.getByText('Email delivery')).toBeVisible()
  await expect(page.getByText('Local only')).toBeVisible()
  await expect(page.getByText('Google Meet', { exact: true })).toBeVisible()
  await expect(page.getByText('Optional · off')).toBeVisible()
  await expect(page.getByText('A sample view of system settings data')).toHaveCount(0)
})
