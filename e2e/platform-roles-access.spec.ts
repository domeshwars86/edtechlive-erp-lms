import { expect, test } from '@playwright/test'

test('platform operator can review fixed roles and live membership totals', async ({ page }) => {
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
  await page.route('**/api/v1/platform/roles', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
    platform_role: { key: 'super_admin', name: 'Super Admin', scope: 'Platform operations', description: 'Verified allowlisted operator access.' },
    organization_roles: [{ key: 'admin', name: 'Admin', scope: 'Organization membership', description: 'Organization administration.', active_memberships: 2, suspended_memberships: 0 }],
    access_model: { editable_here: false, summary: 'These are fixed organization membership roles. General role editing is not available here.' },
  } }) }))

  await page.goto('/workspace/super-admin')
  await page.getByRole('button', { name: 'Roles & access', exact: true }).click()

  await expect(page.getByRole('heading', { name: 'Built-in roles and access scope' })).toBeVisible({ timeout: 30_000 })
  await expect(page.getByText('2 active', { exact: true })).toBeVisible()
  await expect(page.getByText('A sample view of roles & access data')).toHaveCount(0)
  for (const width of [320, 375, 768, 1440]) {
    await page.setViewportSize({ width, height: 900 })
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
})
