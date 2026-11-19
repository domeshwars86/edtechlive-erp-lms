import { expect, test } from '@playwright/test'

test('attendance register requires and displays a reason for corrections', async ({ page }) => {
  let signedIn = false
  let corrected = false
  let correctionReason = ''
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
  await page.route('**/api/v1/organizations/18/**', async route => {
    const request = route.request()
    const pathname = new URL(request.url()).pathname
    if (pathname.endsWith('/dashboard/summary')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        kind: 'organization', date: '2026-11-19', students: 1, teachers: 1, classes_today: 0,
        attendance_today: { records: 1, present: 1, late: 0, absent: 0, excused: 0, rate: 100 },
      } }) })
    }
    if (pathname.endsWith('/classes')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 4, name: 'Grade 5' }], meta: { current_page: 1, last_page: 1, total: 1 } }) })
    }
    if (pathname.endsWith('/sections')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 5, name: 'A', academic_session_id: 7, academic_session: { id: 7, name: '2026–2027' }, is_active: true }] }) })
    }
    if (pathname.endsWith('/attendance/corrections')) {
      const data = corrected ? [{
        id: 120, student: { id: 17, name: 'Learner Example', admission_number: 'ST-100' }, actor: account.name,
        previous_status: 'present', status: 'absent', previous_note: 'On time', note: null,
        reason: correctionReason, created_at: '2026-11-19T12:30:00.000Z',
      }] : []
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data, meta: { current_page: 1, last_page: 1, total: data.length } }) })
    }
    if (pathname.endsWith('/attendance') && request.method() === 'GET') {
      const status = corrected ? 'absent' : 'present'
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [{ id: 17, name: 'Learner Example', admission_number: 'ST-100', status, note: corrected ? null : 'On time' }],
        context: { section: { id: 5, name: 'A' }, class: { id: 4, name: 'Grade 5' }, session: { id: 7, name: '2026–2027' }, date: '2026-11-19' },
        meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
      }) })
    }
    if (pathname.endsWith('/attendance') && request.method() === 'PUT') {
      const body = request.postDataJSON() as { records: { correction_reason?: string }[] }
      correctionReason = body.records[0]?.correction_reason ?? ''
      corrected = true
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ message: 'Attendance saved.', saved: 1 }) })
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } }) })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: /Browser Test School/ }).click()
  await page.getByRole('button', { name: 'Attendance', exact: true }).click()

  await expect(page.getByRole('heading', { name: 'Attendance' })).toBeVisible()
  await expect(page.getByText('Correction history')).toBeVisible()
  await page.getByLabel('Attendance status for Learner Example').selectOption('absent')
  const reason = page.getByLabel('Correction reason for Learner Example')
  await expect(reason).toBeVisible()
  await reason.fill('Attendance sheet corrected after a roll-call review.')
  await page.getByRole('button', { name: 'Save this page' }).click()

  await expect.poll(() => correctionReason).toBe('Attendance sheet corrected after a roll-call review.')
  await expect(page.getByText('Reason: Attendance sheet corrected after a roll-call review.')).toBeVisible()
  await expect(page.getByText('present → absent')).toBeVisible()
})
