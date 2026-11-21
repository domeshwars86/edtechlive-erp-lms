import { expect, test } from '@playwright/test'

test('student can open enrolled courses and use course lesson study help', async ({ page }) => {
  let signedIn = false
  const account = { id: 22, name: 'Student Test', email: 'student@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: false }
  const membership = { membership_id: 3, role: 'student', organization: { id: 18, name: 'Browser Test School', slug: 'browser-test-school', type: 'school', status: 'active', timezone: 'Asia/Kolkata' } }
  const course = { id: 7, title: 'Published Science', slug: 'published-science', level: 'Grade 6', description: 'A published course.', status: 'published', is_public_listing: false, subject: { id: 3, name: 'Science' }, teacher: null }
  let studentQuestion: Record<string, unknown> | undefined
  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/me', route => route.fulfill({ status: signedIn ? 200 : 401, contentType: 'application/json', body: JSON.stringify(signedIn ? { data: account } : { message: 'Unauthenticated.' }) }))
  await page.route('**/api/v1/auth/login', route => { signedIn = true; return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: account }) }) })
  await page.route('**/api/v1/organizations?per_page=100', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [membership], preferred_organization_id: null, meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }) }))
  await page.route('**/api/v1/me/organization', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: membership }) }))
  await page.route('**/api/v1/organizations/18/**', route => {
    const pathname = new URL(route.request().url()).pathname
    const payload = pathname.endsWith('/dashboard/summary')
      ? { data: { kind: 'student', date: '2026-11-21', active_courses: 0, lessons_completed_week: 0, assignments_pending: 0, attendance_recent: { records: 0, rate: null } } }
      : pathname.endsWith('/dashboard/activity') ? { data: { days: [] } }
        : pathname.endsWith('/ai/config') ? { enabled: true, model: 'student-test-model', daily_limit: 10, usage: { requests_30_days: 0, prompt_tokens_30_days: 0, completion_tokens_30_days: 0, requests_today: 0 } }
          : pathname.endsWith('/courses/7/student-question') && route.request().method() === 'POST' ? (() => { studentQuestion = route.request().postDataJSON() as Record<string, unknown>; return { data: { answer: 'Evaporation changes liquid water into vapor.', model: 'student-test-model', usage: { prompt_tokens: 30, completion_tokens: 12 }, review_required: false } } })()
            : pathname.endsWith('/courses') ? { data: [course] }
              : pathname.endsWith('/courses/7/lessons') ? { data: [{ id: 9, title: 'Water Cycle', content: 'Evaporation changes liquid water into vapor.', external_url: null, status: 'published', sort_order: 1, module_id: null }] }
                : { data: [] }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payload) })
  })
  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: /Browser Test School/ }).click()
  await expect(page.getByRole('heading', { name: 'Welcome, Student Test' })).toBeVisible()
  await page.getByRole('button', { name: 'My courses', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Enrolled courses' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Study with your course lessons' })).toBeVisible()
  await page.getByRole('textbox', { name: 'Question' }).fill('What happens during evaporation?')
  await page.getByRole('button', { name: 'Ask about this course' }).click()
  await expect(page.locator('.course-ai-result pre')).toHaveText('Evaporation changes liquid water into vapor.')
  expect(studentQuestion).toEqual({ question: 'What happens during evaporation?', language: 'English' })
  await page.getByRole('button', { name: 'AI learning help', exact: true }).click()
  const studyHelpHeading = page.getByRole('heading', { name: 'Study with your course lessons' })
  await expect(studyHelpHeading).toBeFocused()
})
