import { expect, test } from '@playwright/test'

test('admin can edit a timetable entry and see a server conflict', async ({ page }) => {
  let signedIn = false
  let start = '09:00:00'
  let end = '10:00:00'
  let rejectUpdate = false
  let updatedPayload: Record<string, unknown> | null = null
  const account = { id: 84, name: 'School Administrator', email: 'admin@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: false }
  const membership = {
    membership_id: 92,
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
    const request = route.request()
    const pathname = new URL(request.url()).pathname
    if (pathname.endsWith('/dashboard/summary')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        kind: 'organization', date: '2026-11-19', students: 1, teachers: 1, classes_today: 0,
        attendance_today: { records: 0, present: 0, late: 0, absent: 0, excused: 0, rate: null },
      } }) })
    }
    if (pathname.endsWith('/academic-sessions')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 7, name: '2026–2027', is_current: true, is_active: true }] }) })
    if (pathname.endsWith('/classes/15/sections')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 31, name: 'A', academic_session_id: 7, is_active: true }] }) })
    if (pathname.endsWith('/classes')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 15, name: 'Grade 8', is_active: true }] }) })
    if (pathname.endsWith('/subjects')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 23, name: 'Mathematics', is_active: true }] }) })
    if (pathname.endsWith('/teachers')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 19, name: 'Asha Rao', status: 'active' }] }) })
    if (pathname.endsWith('/timetable/51') && request.method() === 'PATCH') {
      updatedPayload = request.postDataJSON() as Record<string, unknown>
      if (rejectUpdate) return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'The given data was invalid.', errors: { starts_at: ['This class time conflicts with another scheduled entry.'] } }) })
      start = String(updatedPayload.starts_at) + ':00'
      end = String(updatedPayload.ends_at) + ':00'
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: entry() }) })
    }
    if (pathname.endsWith('/timetable')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [entry()], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }) })
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: /Browser Test School/ }).click()
  await page.getByRole('button', { name: 'Timetable', exact: true }).click()

  await page.getByRole('button', { name: 'Edit Monday Mathematics timetable entry' }).click()
  await page.getByLabel('Starts').fill('10:00')
  await page.getByLabel('Ends').fill('11:00')
  await page.getByRole('button', { name: 'Save changes' }).click()
  await expect(page.getByRole('status').getByText('Timetable entry updated.')).toBeVisible()
  await expect(page.getByText(/Monday · 10:00–11:00 · Mathematics/)).toBeVisible()
  expect(updatedPayload).toMatchObject({ academic_session_id: 7, academic_section_id: 31, subject_id: 23, teacher_id: 19, weekday: 0, starts_at: '10:00', ends_at: '11:00', room: 'Room 4' })

  rejectUpdate = true
  await page.getByRole('button', { name: 'Edit Monday Mathematics timetable entry' }).click()
  await page.getByLabel('Starts').fill('11:00')
  await page.getByLabel('Ends').fill('12:00')
  await page.getByRole('button', { name: 'Save changes' }).click()
  await expect(page.getByRole('alert')).toHaveText('This class time conflicts with another scheduled entry.')
  await expect(page.getByRole('heading', { name: 'Edit class time' })).toBeVisible()

  function entry() {
    return {
      id: 51, academic_session_id: 7, academic_section_id: 31, weekday: 0,
      starts_at: start, ends_at: end, room: 'Room 4', meeting_url: null, is_active: true,
      academic_section: { id: 31, name: 'A', academic_class: { id: 15, name: 'Grade 8' } },
      subject: { id: 23, name: 'Mathematics' }, teacher: { id: 19, name: 'Asha Rao' },
    }
  }
})
