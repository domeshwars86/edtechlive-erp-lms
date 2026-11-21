import { expect, test } from '@playwright/test'

test('teacher AI question-drafts navigation opens the course-grounded draft tool', async ({ page }) => {
  test.setTimeout(90_000)
  let signedIn = false
  let aiEnabled = true
  const submittedPrompts: Record<string, unknown>[] = []
  const account = { id: 42, name: 'Course Author', email: 'author@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: false }
  const membership = {
    membership_id: 12,
    role: 'teacher',
    organization: { id: 18, name: 'Browser Test School', slug: 'browser-test-school', type: 'school', status: 'active', timezone: 'Asia/Kolkata' },
  }
  const course = { id: 7, title: 'Foundations of Science', slug: 'foundations-of-science', level: 'Grade 6', description: 'Explore natural systems.', status: 'draft', is_public_listing: false, subject: { id: 3, name: 'Science' }, teacher: null }

  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/me', route => {
    return route.fulfill({
      status: signedIn ? 200 : 401,
      contentType: 'application/json',
      body: JSON.stringify(signedIn ? { data: account } : { message: 'Unauthenticated.' }),
    })
  })
  await page.route('**/api/v1/auth/login', route => {
    signedIn = true
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: account }) })
  })
  await page.route('**/api/v1/organizations?per_page=100', route => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: [membership], preferred_organization_id: null, meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }),
  }))
  await page.route('**/api/v1/me/organization', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: membership }) }))
  await page.route('**/api/v1/organizations/18/**', async route => {
    const request = route.request()
    const pathname = new URL(request.url()).pathname
    if (pathname.endsWith('/dashboard/summary')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        kind: 'admin', date: '2026-11-20', students: 0, teachers: 0, classes_today: 0, attendance_today: { records: 0, rate: null },
      } }) })
    }
    if (pathname.endsWith('/dashboard/activity')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { days: [] } }) })
    if (pathname.endsWith('/ai/config')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      enabled: aiEnabled, model: aiEnabled ? 'educator-test-model' : null, daily_limit: 10,
      usage: { requests_30_days: 1, prompt_tokens_30_days: 100, completion_tokens_30_days: 50, requests_today: 0 },
    }) })
    if (pathname.endsWith('/courses/7/lesson-plan') && request.method() === 'POST') {
      submittedPrompts.push(request.postDataJSON() as Record<string, unknown>)
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        draft: 'Learning goals\nExplain how the water cycle moves water through the environment.\n\nLesson sequence\n1. Observe a diagram.',
        model: 'educator-test-model', usage: { prompt_tokens: 125, completion_tokens: 42 }, review_required: true,
      } }) })
    }
    if (pathname.endsWith('/courses/7/question-drafts') && request.method() === 'POST') {
      submittedPrompts.push(request.postDataJSON() as Record<string, unknown>)
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        draft: JSON.stringify({ questions: [{ question: 'What changes water vapor into droplets?', options: ['Condensation', 'Evaporation', 'Sublimation', 'Filtration'], correct_index: 0, explanation: 'Cooling water vapor condenses.' }] }, null, 2),
        model: 'educator-test-model', usage: { prompt_tokens: 125, completion_tokens: 42 }, review_required: true,
      } }) })
    }
    if (pathname.endsWith('/courses') && request.method() === 'GET') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [course] }) })
    if (pathname.endsWith('/students')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) })
    if (/\/courses\/7\/(lessons|assets|modules)$/.test(pathname)) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) })
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }) })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: /Browser Test School/ }).click()
  await page.getByRole('button', { name: 'AI question drafts', exact: true }).click()

  await expect(page.getByRole('heading', { name: 'Courses and lessons' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'AI question drafts', level: 4 })).toBeVisible()
  await expect(page.getByLabel('Draft type')).toHaveValue('questions')
  await page.getByLabel('Draft type').selectOption('lesson_plan')
  await expect(page.getByRole('heading', { name: 'AI lesson-plan draft', level: 4 })).toBeVisible()
  await page.getByRole('textbox', { name: 'Topic' }).fill('The water cycle')
  await page.getByRole('textbox', { name: 'Learning objectives' }).fill('Explain how water moves through the environment.')
  await page.getByRole('button', { name: 'Generate lesson-plan draft' }).click()

  await expect(page.getByText(/Explain how the water cycle moves water/)).toBeVisible()
  await expect(page.getByRole('button', { name: 'Copy draft to edit' })).toBeVisible()
  await expect(page.getByText(/Draft generated with educator-test-model/)).toBeVisible()
  expect(submittedPrompts[0]).toEqual({ topic: 'The water cycle', grade_level: null, objectives: 'Explain how water moves through the environment.', language: 'English' })

  await page.getByLabel('Draft type').selectOption('questions')
  await page.getByLabel('Question count').selectOption('3')
  await page.getByLabel('Difficulty').selectOption('medium')
  await page.getByRole('button', { name: 'Generate MCQ drafts' }).click()
  await expect(page.locator('.course-ai-result pre')).toContainText('What changes water vapor into droplets?')
  await expect(page.locator('.course-ai-result pre')).toContainText('correct_index')
  expect(submittedPrompts[1]).toEqual({
    topic: 'The water cycle', grade_level: null, objectives: 'Explain how water moves through the environment.', language: 'English',
    question_count: 3, difficulty: 'medium',
  })

  await page.setViewportSize({ width: 320, height: 900 })
  const hasHorizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)
  expect(hasHorizontalOverflow).toBe(false)

  await page.setViewportSize({ width: 1280, height: 900 })
  aiEnabled = false
  await page.getByRole('button', { name: 'Live classes', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Live classes', level: 1 })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Scheduled meetings', level: 2 })).toBeVisible()
  await page.getByRole('button', { name: 'AI question drafts', exact: true }).click()
  await expect(page.getByText('AI drafts are currently unavailable. An operator must configure the private Ollama service before educators can generate drafts.')).toBeVisible()
  await expect(page.getByLabel('Draft type')).toHaveCount(0)
})
