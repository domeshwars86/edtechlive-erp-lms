import { expect, test } from '@playwright/test'

test('homepage navigation opens searchable sample course details', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveTitle(/edTechLive/)
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Good learning')

  await page.getByRole('link', { name: 'Courses', exact: true }).first().click()
  await expect(page).toHaveURL(/\/courses$/)
  await page.getByRole('textbox', { name: 'Search courses' }).fill('Python')
  await expect(page.getByRole('link', { name: 'View sample course: The foundations of Python' })).toBeVisible()
  await expect(page.getByRole('link', { name: /Algebra/ })).toHaveCount(0)

  await page.getByRole('link', { name: 'View sample course: The foundations of Python' }).click()
  await expect(page).toHaveURL(/\/courses\/python-foundations$/)
  await expect(page.getByRole('heading', { level: 1 })).toContainText('foundations of Python')
  await expect(page.getByRole('heading', { name: 'Example syllabus' })).toBeVisible()
  await expect(page.getByText('enrollment is not open', { exact: false }).first()).toBeVisible()
})

test('mobile menu is operable and public pages avoid horizontal overflow', async ({ page }) => {
  for (const width of [320, 375, 430, 768]) {
    await page.setViewportSize({ width, height: 900 })
    await page.goto('/')
    const pageWidth = await page.evaluate(() => document.documentElement.scrollWidth)
    expect(pageWidth, `homepage overflows at ${width}px`).toBeLessThanOrEqual(width)
  }

  await page.setViewportSize({ width: 375, height: 812 })
  await page.getByRole('button', { name: 'Open menu' }).click()
  const mobileNav = page.getByRole('navigation', { name: 'Mobile navigation' })
  await expect(mobileNav).toBeVisible()
  await mobileNav.getByRole('link', { name: 'Courses' }).click()
  await expect(page).toHaveURL(/\/courses$/)
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  const catalogWidth = await page.evaluate(() => document.documentElement.scrollWidth)
  expect(catalogWidth, 'course catalog overflows at 375px').toBeLessThanOrEqual(375)
})

test('contact form validates and reports the API result', async ({ page }) => {
  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/enquiries', route => route.fulfill({
    status: 201,
    contentType: 'application/json',
    body: JSON.stringify({ data: { notification_sent: false } }),
  }))
  await page.goto('/contact')
  await page.getByRole('textbox', { name: /Full name/ }).fill('Browser Test User')
  await page.getByRole('textbox', { name: /Email address/ }).fill('browser-test@example.com')
  await page.getByLabel('I’m a… *').selectOption('Teacher or educator')
  await page.getByLabel('Enquiry type *').selectOption('Teaching and courses')
  await page.getByRole('textbox', { name: /What would you like us to know/ }).fill('I would like to learn how course spaces are organized.')
  await page.getByRole('checkbox', { name: /I agree that my information/ }).check()
  await page.getByRole('button', { name: 'Send enquiry' }).click()
  await expect(page.getByRole('heading', { name: /Thanks for sharing/ })).toBeVisible()
  await expect(page.getByText('Your enquiry has been saved.', { exact: false })).toBeVisible()
})

test('role preview renders without horizontal overflow on a small phone', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 800 })
  await page.goto('/workspace/student')
  await expect(page.getByRole('heading', { name: /Welcome|Good morning/ })).toBeVisible()
  const pageWidth = await page.evaluate(() => document.documentElement.scrollWidth)
  expect(pageWidth, 'student dashboard preview overflows at 320px').toBeLessThanOrEqual(320)
})

test('local account verification link leads back to a working sign-in flow', async ({ page }) => {
  let verified = false
  let signedIn = false
  const account = {
    id: 42,
    name: 'Browser Test User',
    email: 'browser-test@example.com',
    email_verified_at: '2026-11-19T12:00:00.000Z',
    is_platform_admin: false,
  }
  const membership = {
    membership_id: 72,
    role: 'admin',
    organization: {
      id: 18,
      name: 'Browser Test School',
      slug: 'browser-test-school',
      type: 'school',
      status: 'active',
      timezone: 'Asia/Kolkata',
    },
  }

  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/me', route => route.fulfill({
    status: signedIn ? 200 : 401,
    contentType: 'application/json',
    body: JSON.stringify(signedIn ? { data: account } : { message: 'Unauthenticated.' }),
  }))
  await page.route('**/api/v1/organizations?per_page=100', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data: [membership], preferred_organization_id: null, meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }),
  }))
  await page.route('**/api/v1/auth/register', route => route.fulfill({
    status: 202,
    contentType: 'application/json',
    body: JSON.stringify({
      status: 'verification_required',
      verification_url: 'http://127.0.0.1:8002/api/v1/auth/verify-email/42/test-hash?expires=1795093200&signature=test-signature',
    }),
  }))
  await page.context().route('**/api/v1/auth/verify-email/**', async route => {
    verified = true
    await route.fulfill({ status: 200, contentType: 'text/html', body: '<main>Email address verified. You can now sign in.</main>' })
  })
  await page.route('**/api/v1/auth/login', route => {
    if (!verified) {
      return route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ status: 'email_verification_required', message: 'Verify your email address before signing in.' }) })
    }

    signedIn = true
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: account }) })
  })

  await page.goto('/register')
  await page.getByRole('textbox', { name: 'Full name' }).fill(account.name)
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('textbox', { name: 'Organization name' }).fill(membership.organization.name)
  await page.getByLabel('Organization type').selectOption('school')
  await page.getByRole('textbox', { name: 'Confirm password' }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page.getByRole('heading', { name: 'You’re all set.' })).toBeVisible()
  await expect(page.getByRole('link', { name: /Verify email to unlock sign-in/ })).toBeVisible()

  const verificationTab = page.context().waitForEvent('page')
  await page.getByRole('link', { name: /Verify email to unlock sign-in/ }).click()
  const verifiedPage = await verificationTab
  await expect(verifiedPage.locator('body')).toContainText('Email address verified. You can now sign in.')
  expect(verified).toBe(true)

  await page.getByRole('link', { name: 'Log in', exact: true }).click()
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await expect(page).toHaveURL(/\/select-organization$/)
  await expect(page.getByRole('heading', { name: 'Choose your workspace' })).toBeVisible()
  await expect(page.getByRole('button', { name: /Browser Test School/ })).toBeVisible()
})

test('all seven dashboard previews fit narrow and desktop viewports', async ({ page }) => {
  test.setTimeout(120_000)
  const roles = ['super-admin', 'admin', 'management', 'teacher', 'student', 'parent', 'educator']
  for (const role of roles) {
    for (const width of [320, 768, 1440]) {
      await page.setViewportSize({ width, height: 900 })
      await page.goto(`/workspace/${role}`, { waitUntil: 'domcontentloaded' })
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      const pageWidth = await page.evaluate(() => document.documentElement.scrollWidth)
      expect(pageWidth, `${role} dashboard preview overflows at ${width}px`).toBeLessThanOrEqual(width)
    }
  }
})
