import { expect, test } from '@playwright/test'

test('teacher exam report shows scoped class-section performance and exports its summary', async ({ page }) => {
  let signedIn = false
  const account = { id: 84, name: 'Assigned Teacher', email: 'teacher@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: false }
  const membership = {
    membership_id: 92,
    role: 'teacher',
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
        kind: 'teacher', date: '2026-11-19', classes: 1, students: 18, reviews_pending: 0, classes_this_week: 1, courses: 1,
      } }) })
    }
    if (pathname.endsWith('/reports/assessments')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        summary: { published_quizzes: 0, submitted_attempts: 0, in_progress_attempts: 0, awaiting_review: 0, learner_quiz_participations: 0, average_score_percent: null, quizzes_with_submissions: 0 },
        quizzes: [],
        assignment_summary: { published_assignments: 0, submissions: 0, graded_submissions: 0, awaiting_review: 0, learner_submissions: 0, average_score_percent: null },
        assignments: [],
      } }) })
    }
    if (pathname.endsWith('/academic-sessions')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 7, name: '2026–2027' }] }) })
    }
    if (pathname.endsWith('/reports/exams/sections')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{
        exam_id: 3, exam: 'Term 1 examinations', session: '2026–2027', class_section: 'Grade 8 · A',
        paper_count: 1, subject_count: 1, student_count: 18, result_count: 18, graded_count: 17,
        absent_count: 1, excused_count: 0, average_percent: 76.7,
      }] }) })
    }
    if (pathname.endsWith('/reports/exams')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{
        paper_id: 4, exam: 'Term 1 examinations', session: '2026–2027', class_section: 'Grade 8 · A',
        subject: 'Mathematics', exam_on: '2026-11-18', max_marks: '100.00', result_count: 18,
        graded_count: 17, absent_count: 1, excused_count: 0, average_score: '76.70', average_percent: 76.7, paper_sections: [],
      }] }) })
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: /Browser Test School/ }).click()
  await page.getByRole('button', { name: 'Results', exact: true }).click()

  await expect(page.getByRole('heading', { name: 'Published exam performance' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Class-section performance by examination' })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'Grade 8 · A' }).first()).toBeVisible()
  await expect(page.getByRole('cell', { name: '76.7%', exact: true })).toBeVisible()

  const downloadReady = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Export section summary' }).click()
  expect((await downloadReady).suggestedFilename()).toBe('exam-section-performance.csv')
})
