import { expect, test } from '@playwright/test'

test('independent educator course analytics opens the course progress report', async ({ page }) => {
  let signedIn = false
  let courseProgressRequested = false
  const account = { id: 31, name: 'Educator Test', email: 'educator@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: false }
  const membership = { membership_id: 6, role: 'independent_educator', organization: { id: 18, name: 'Educator Studio', slug: 'educator-studio', type: 'independent_educator', status: 'active', timezone: 'Asia/Kolkata' } }

  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/me', route => route.fulfill({ status: signedIn ? 200 : 401, contentType: 'application/json', body: JSON.stringify(signedIn ? { data: account } : { message: 'Unauthenticated.' }) }))
  await page.route('**/api/v1/auth/login', route => { signedIn = true; return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: account }) }) })
  await page.route('**/api/v1/organizations?per_page=100', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [membership], preferred_organization_id: null }) }))
  await page.route('**/api/v1/me/organization', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: membership }) }))
  await page.route('**/api/v1/organizations/18/**', route => {
    const pathname = new URL(route.request().url()).pathname
    let payload: unknown = { data: [] }
    if (pathname.endsWith('/reports/course-progress')) {
      courseProgressRequested = true
      payload = { data: [], summary: { courses: 0, learners: 0, average_percent: 0 } }
    } else if (pathname.endsWith('/reports/attendance')) {
      payload = { data: { from: '2026-11-01', to: '2026-11-21', totals: { records: 0, present: 0, late: 0, absent: 0, excused: 0, attendance_rate: 0 }, sections: [] } }
    } else if (pathname.endsWith('/dashboard/summary')) {
      payload = { data: { kind: 'educator', date: '2026-11-21', published_courses: 0, learners: 0, pending_reviews: 0, upcoming_meetings: 0 } }
    } else if (pathname.endsWith('/dashboard/activity')) {
      payload = { data: { days: [] } }
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payload) })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: /Educator Studio/ }).click()
  await page.getByRole('button', { name: 'Course analytics', exact: true }).click()

  await expect(page.getByRole('button', { name: 'Course progress' })).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByRole('heading', { name: 'Course progress' })).toBeVisible()
  await expect.poll(() => courseProgressRequested).toBe(true)
})
