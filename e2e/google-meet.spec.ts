import { expect, test } from '@playwright/test'

test('management creates a Google Meet link and schedules it with the class meeting', async ({ page }) => {
  let signedIn = false
  let createdMeeting: Record<string, unknown> | null = null
  const account = { id: 44, name: 'School Administrator', email: 'admin@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: false }
  const membership = {
    membership_id: 51,
    role: 'admin',
    organization: { id: 18, name: 'Browser Test School', slug: 'browser-test-school', type: 'school', status: 'active', timezone: 'Asia/Kolkata' },
  }

  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/me', route => route.fulfill({
    status: signedIn ? 200 : 401,
    contentType: 'application/json',
    body: JSON.stringify(signedIn ? { data: account } : { message: 'Unauthenticated.' }),
  }))
  await page.route('**/api/v1/auth/login', route => {
    signedIn = true
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: account }) })
  })
  await page.route('**/api/v1/organizations?per_page=100', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data: [membership], preferred_organization_id: null, meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }),
  }))
  await page.route('**/api/v1/me/organization', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: membership }) }))
  await page.route('**/api/v1/organizations/18/**', route => {
    const pathname = new URL(route.request().url()).pathname
    if (pathname.endsWith('/dashboard/summary')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        kind: 'organization', date: '2026-11-19', students: 20, teachers: 3, classes_today: 0,
        attendance_today: { records: 0, present: 0, late: 0, absent: 0, excused: 0, rate: null },
      } }) })
    }
    if (pathname.endsWith('/dashboard/activity')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { from: '2026-11-13', to: '2026-11-19', days: [] } }) })
    }
    if (pathname.endsWith('/meetings/google/status')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ configured: true, connected: true, google_email: 'organizer@example.test', connected_at: '2026-11-18T12:00:00Z' }) })
    }
    if (pathname.endsWith('/meetings/google/spaces')) {
      return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ meeting_url: 'https://meet.google.com/abc-defg-hij' }) })
    }
    if (pathname.endsWith('/meetings') && route.request().method() === 'POST') {
      createdMeeting = route.request().postDataJSON() as Record<string, unknown>
      return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: {
        id: 10, kind: 'class', title: createdMeeting.title, description: createdMeeting.description,
        starts_at: createdMeeting.starts_at, ends_at: createdMeeting.ends_at, meeting_url: createdMeeting.meeting_url,
        status: 'scheduled', section: { id: 8, name: 'A', class: 'Grade 8' }, course: null,
        creator: { id: account.id, name: account.name },
      } }) })
    }
    if (pathname.endsWith('/meetings') && route.request().method() === 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) })
    }
    if (pathname.endsWith('/classes')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 2, name: 'Grade 8' }] }) })
    }
    if (pathname.endsWith('/classes/2/sections')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 8, name: 'A', academic_session_id: 7 }] }) })
    }
    if (pathname.includes('/recordings')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) })
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: /Browser Test School/ }).click()
  await page.getByRole('button', { name: 'Meetings', exact: true }).click()

  await expect(page.getByText('Connected as organizer@example.test. Room links are created by your Google account.')).toBeVisible()
  await page.getByRole('button', { name: 'Create Meet link' }).click()
  await expect(page.getByLabel('Meeting URL')).toHaveValue('https://meet.google.com/abc-defg-hij')
  await page.getByLabel('Class section').selectOption('8')
  await page.getByLabel('Meeting title').fill('Biology review')
  await page.getByLabel('Starts at').fill('2027-01-12T09:00')
  await page.getByLabel('Ends at').fill('2027-01-12T10:00')
  await page.getByRole('button', { name: 'Schedule meeting' }).click()

  await expect(page.getByRole('status').getByText('Meeting scheduled.')).toBeVisible()
  expect(createdMeeting).toMatchObject({
    kind: 'class',
    academic_section_id: 8,
    academic_session_id: 7,
    title: 'Biology review',
    meeting_url: 'https://meet.google.com/abc-defg-hij',
  })
})
