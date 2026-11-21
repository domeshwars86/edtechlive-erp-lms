import { readFile } from 'node:fs/promises'
import { expect, test } from '@playwright/test'

test('authorized organization admin exports every matching student page to a safe CSV', async ({ page }) => {
  test.setTimeout(90_000)
  let signedIn = false
  const account = { id: 84, name: 'Workspace Administrator', email: 'admin@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: false }
  const membership = {
    membership_id: 92,
    role: 'admin',
    organization: { id: 18, name: 'Browser Test School', slug: 'browser-test-school', type: 'school', status: 'active', timezone: 'Asia/Kolkata' },
  }
  const students = [
    { id: 1, name: '=StudentOne', admission_number: 'ADM-001', email: 'student.one@example.test', phone: null, status: 'active', enrolled_on: '2026-04-01', branch: null, academic_class: { id: 2, name: 'Class 2' }, academic_section: { id: 3, name: 'A' }, academic_session: { id: 4, name: '2026–27' } },
    { id: 2, name: 'Second Student', admission_number: 'ADM-002', email: null, phone: '+91 1234567890', status: 'inactive', enrolled_on: null, branch: { id: 5, name: 'North', code: 'N' }, academic_class: null, academic_section: null, academic_session: null },
  ]
  let studentPatch: Record<string, unknown> | null = null

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
  await page.route('**/api/v1/organizations/18/branches**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }))
  await page.route('**/api/v1/organizations/18/classes**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }))
  await page.route('**/api/v1/organizations/18/academic-sessions**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }))
  await page.route('**/api/v1/organizations/18/students**', async route => {
    const url = new URL(route.request().url())
    const pageNumber = Number(url.searchParams.get('page') ?? 1)
    const perPage = Number(url.searchParams.get('per_page') ?? 25)
    const data = [students[pageNumber - 1]].filter(Boolean)
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data, meta: { current_page: pageNumber, last_page: 2, per_page: perPage, total: 2 } }),
    })
  })

  await page.route('**/api/v1/organizations/18/students/1', async route => {
    if (route.request().method() !== 'PATCH') return route.continue()
    studentPatch = route.request().postDataJSON() as Record<string, unknown>
    Object.assign(students[0], studentPatch)
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: students[0] }) })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: /Browser Test School/ }).click()
  await page.getByRole('button', { name: 'Students', exact: true }).click()
  await expect(page.getByText('Loading workspace…')).toHaveCount(0, { timeout: 30_000 })
  await expect(page.getByRole('heading', { name: 'Students', level: 2 })).toBeVisible({ timeout: 15_000 })
  await expect(page.getByText('Page 1 of 2 · 2 students')).toBeVisible()
  await page.getByRole('button', { name: 'Next', exact: true }).click()
  await expect(page.getByText('Second Student')).toBeVisible()
  await expect(page.getByText('Page 2 of 2 · 2 students')).toBeVisible()

  await page.getByLabel('Choose CSV').setInputFiles({
    name: 'students-update.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from('Admission number,Student name,Email,Status\r\nADM-001,"\'=StudentOne, Updated",new.student@example.test,inactive\r\nADM-999,Unmatched Student,missing@example.test,inactive\r\n'),
  })
  await expect(page.getByRole('status')).toContainText('Compared 2 CSV rows: 1 with changes, 1 unmatched, 0 duplicated.')
  await expect(page.getByText('Current: student.one@example.test')).toBeVisible()
  await expect(page.getByRole('checkbox').nth(2)).not.toBeChecked()
  await page.getByRole('button', { name: 'Apply selected updates' }).click()
  await expect(page.getByRole('status')).toContainText('Applied 1 record update; 0 failed.')
  expect(studentPatch).toEqual({ name: '=StudentOne, Updated', email: 'new.student@example.test' })

  const downloadPromise = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Export CSV' }).click()
  const download = await downloadPromise
  expect(download.suggestedFilename()).toMatch(/^students-\d{4}-\d{2}-\d{2}\.csv$/)
  const filePath = await download.path()
  expect(filePath).not.toBeNull()
  const csv = await readFile(filePath!, 'utf8')
  expect(csv).toContain('"ADM-001","\'=StudentOne, Updated","new.student@example.test"')
  expect(csv).toContain('"ADM-002","Second Student"')
  await expect(page.getByText('Exported 2 student records.')).toBeVisible()
})
