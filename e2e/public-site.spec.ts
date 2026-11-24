import { expect, test } from '@playwright/test'

test('home labels illustrative dashboard data and explains current integrations', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByText('Sample workspace')).toBeVisible()
  await expect(page.getByText('PREVIEW', { exact: true })).toBeVisible()
  await expect(page.getByText('Example class')).toBeVisible()
  await expect(page.getByText(/tenant-scoped workspaces for institutions and independent educators/)).toBeVisible()
  await page.getByRole('button', { name: 'Do I need to replace the tools my teachers already use?' }).click()
  await expect(page.getByText(/manually created HTTPS meeting links/)).toBeVisible()
})

test('public workspace illustration and contact transport copy avoid overclaims', async ({ page }) => {
  await page.goto('/solutions/students')
  await expect(page.getByText('WORKSPACE PREVIEW')).toBeVisible()
  await page.goto('/contact')
  await expect(page.getByText('Your enquiry is sent to edTechLive for a response.')).toBeVisible()
})

test('features page distinguishes connected, configurable, and unfinished work', async ({ page }) => {
  await page.goto('/features')
  await expect(page.getByRole('heading', { name: 'Built into the workspace' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Google Meet room creation' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'AI learning assistance' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Public course enrollment' })).toBeVisible()
  await expect(page.getByText('Coming later')).toHaveCount(0)
  await expect(page.getByText('Razorpay Test Mode checkout requires sandbox credentials and a verified sandbox transaction.')).toBeVisible()
})

test('public shell offers keyboard skip navigation and one main landmark', async ({ page }) => {
  await page.goto('/courses/python-foundations')
  const skipLink = page.getByRole('link', { name: 'Skip to content' })
  await page.keyboard.press('Tab')
  await expect(skipLink).toBeFocused()
  await expect(page.getByRole('main')).toHaveCount(1)
  await page.keyboard.press('Enter')
  await expect(page).toHaveURL(/#main-content$/)
  await expect(page.locator('#main-content')).toBeFocused()
})

test('dashboard preview offers keyboard skip navigation', async ({ page }) => {
  await page.goto('/workspace/admin')
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 30_000 })
  const skipLink = page.getByRole('link', { name: 'Skip to content' })
  await page.keyboard.press('Tab')
  await expect(skipLink).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(page).toHaveURL(/#dashboard-main$/)
  await expect(page.getByRole('main')).toHaveCount(1)
  await expect(page.locator('#dashboard-main')).toBeFocused()
  const overviewLink = page.getByRole('button', { name: 'Overview' })
  await expect(overviewLink).toHaveAttribute('aria-current', 'page')
  const studentsLink = page.getByRole('button', { name: 'Students', exact: true })
  await studentsLink.click()
  await expect(page.getByRole('heading', { name: 'Students', level: 1 })).toBeVisible()
  await expect(studentsLink).toHaveAttribute('aria-current', 'page')
  await expect(overviewLink).not.toHaveAttribute('aria-current', 'page')
})

test('account pages expose keyboard skip navigation to the sign-in form', async ({ page }) => {
  await page.goto('/login')
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible()
  const skipLink = page.getByRole('link', { name: 'Skip to account form' })
  await page.keyboard.press('Tab')
  await expect(skipLink).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(page).toHaveURL(/#account-main$/)
  await expect(page.getByRole('main')).toHaveCount(1)
  await expect(page.locator('#account-main')).toBeFocused()
})

test('homepage sample courses and API-backed public course discovery both work', async ({ page }) => {
  let enquiryPayload: Record<string, unknown> | null = null
  await page.route('**/api/v1/enquiries', async route => {
    enquiryPayload = route.request().postDataJSON() as Record<string, unknown>
    await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: { notification_sent: false } }) })
  })
  await page.goto('/')
  await expect(page).toHaveTitle(/edTechLive/)
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Good learning')

  await expect(page.getByRole('link', { name: 'View sample course: The foundations of Python' })).toBeVisible()
  await page.getByRole('link', { name: 'View sample course: The foundations of Python' }).click()
  await expect(page).toHaveURL(/\/courses\/python-foundations$/)
  await expect(page.getByRole('heading', { name: 'Example syllabus' })).toBeVisible()

  const course = {
    title: 'Foundations of Python',
    description: 'Learn to write small programs with clear examples.',
    level: 'Beginner',
    slug: 'foundations-of-python',
    subject: 'Computer Science',
    organization: { name: 'Browser Test School', slug: 'browser-test-school' },
    published_lesson_count: 2,
  }
  await page.route('**/api/v1/public/courses*', route => {
    const url = new URL(route.request().url())
    const visible = (!url.searchParams.has('q') || course.title.toLowerCase().includes(url.searchParams.get('q')!.toLowerCase()))
      && (!url.searchParams.has('level') || url.searchParams.get('level') === course.level)
      && (!url.searchParams.has('subject') || url.searchParams.get('subject') === course.subject)
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: visible ? [course] : [],
        meta: { current_page: Number(url.searchParams.get('page') || '1'), last_page: 1, per_page: 12, total: visible ? 1 : 0 },
        filters: { levels: ['Beginner'], subjects: [{ name: 'Computer Science' }] },
      }),
    })
  })
  await page.route('**/api/v1/public/organizations/browser-test-school/courses/foundations-of-python', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data: { ...course, lessons: [{ title: 'First steps', sort_order: 1 }, { title: 'Use variables', sort_order: 2 }] } }),
  }))

  await page.getByRole('link', { name: 'Courses', exact: true }).first().click()
  await expect(page).toHaveURL(/\/courses$/)
  await expect(page.getByRole('heading', { level: 1 })).toContainText('questions to begin', { timeout: 30_000 })
  await page.getByRole('textbox', { name: 'Search published courses' }).fill('Python')
  await expect(page.getByRole('link', { name: /Foundations of Python/ })).toBeVisible()
  await expect(page.getByText('Browser Test School')).toBeVisible()
  await page.getByRole('combobox', { name: 'Filter by subject' }).selectOption('Computer Science')
  await page.getByRole('link', { name: /Foundations of Python/ }).click()
  await expect(page).toHaveURL(/\/courses\/browser-test-school\/foundations-of-python$/)
  await expect(page.getByRole('heading', { name: 'Foundations of Python' })).toBeVisible()
  await expect(page.getByText('First steps')).toBeVisible()
  await page.getByRole('link', { name: 'Ask about this course' }).click()
  await expect(page).toHaveURL(/\/contact\?/)
  await expect(page.getByText(/Course enquiry: Foundations of Python · Browser Test School/)).toBeVisible()
  await expect(page.getByLabel('Enquiry type *')).toHaveValue('Teaching and courses')
  const suggestedMessage = page.getByRole('textbox', { name: /What would you like us to know/ })
  await expect(suggestedMessage).toHaveValue('I’m interested in “Foundations of Python” at Browser Test School. Please share more about availability and enrollment.')
  await page.getByRole('textbox', { name: /Full name/ }).fill('Course Inquiry Tester')
  await page.getByRole('textbox', { name: /Email address/ }).fill('course-inquiry@example.test')
  await page.getByLabel('I’m a… *').selectOption('Student')
  await page.getByRole('checkbox', { name: /I agree that my information/ }).check()
  await page.getByRole('button', { name: 'Send enquiry' }).click()
  await expect(page.getByRole('heading', { name: /Thanks for sharing/ })).toBeVisible()
  expect(enquiryPayload).toMatchObject({
    category: 'Teaching and courses',
    message: 'I’m interested in “Foundations of Python” at Browser Test School. Please share more about availability and enrollment.',
    consent: true,
  })
})

test('public course catalog explains when no courses have been listed', async ({ page }) => {
  await page.route('**/api/v1/public/courses*', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 12, total: 0 }, filters: { levels: [], subjects: [] } }),
  }))
  await page.goto('/courses')
  await expect(page.getByRole('heading', { name: 'No public courses yet' })).toBeVisible({ timeout: 30_000 })
  await expect(page.getByText(/active organization publishes a course/i)).toBeVisible()
})

test('public course catalog can recover from an API error', async ({ page }) => {
  let attempts = 0
  await page.route('**/api/v1/public/courses*', route => {
    attempts += 1
    if (attempts === 1) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Catalog temporarily unavailable.' }) })
    }
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 12, total: 0 }, filters: { levels: [], subjects: [] } }),
    })
  })
  await page.goto('/courses')
  await expect(page.getByRole('alert')).toContainText('Catalog temporarily unavailable.', { timeout: 30_000 })
  await page.getByRole('button', { name: 'Try again' }).click()
  await expect(page.getByRole('heading', { name: 'No public courses yet' })).toBeVisible()
  expect(attempts).toBe(2)
})

test('mobile menu is operable and public pages avoid horizontal overflow', async ({ page }) => {
  test.setTimeout(120_000)
  for (const width of [320, 375, 430, 768]) {
    await page.setViewportSize({ width, height: 900 })
    await page.goto('/', { waitUntil: 'domcontentloaded' })
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
  await page.route('**/api/v1/auth/verify-email/**', async route => {
    verified = true
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'verified', message: 'Email address verified. You can now sign in.' }) })
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
  await page.getByRole('button', { name: /Verify email to unlock sign-in/ }).click()
  await expect(page.getByRole('status')).toContainText('Email verified. You can log in now.')
  expect(verified).toBe(true)

  await page.getByRole('link', { name: 'Log in', exact: true }).click()
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await expect(page).toHaveURL(/\/select-organization$/)
  await expect(page.getByRole('heading', { name: 'Choose your workspace' })).toBeVisible()
  await expect(page.getByRole('button', { name: /Browser Test School/ })).toBeVisible()
})

test('login page lets an unverified account request and use an in-page verification action', async ({ page }) => {
  const email = 'unverified-browser@example.test'
  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/me', route => route.fulfill({
    status: 401,
    contentType: 'application/json',
    body: JSON.stringify({ message: 'Unauthenticated.' }),
  }))
  await page.route('**/api/v1/auth/email/verification-notification', route => route.fulfill({
    status: 202,
    contentType: 'application/json',
    body: JSON.stringify({
      message: 'If the address has an unverified account, a verification link has been sent.',
      verification_url: 'http://127.0.0.1:8002/api/v1/auth/verify-email/43/test-hash?expires=1795093200&signature=test-signature',
    }),
  }))
  await page.route('**/api/v1/auth/verify-email/**', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ status: 'verified', message: 'Email address verified. You can now sign in.' }),
  }))

  await page.goto('/login')
  await page.getByRole('button', { name: 'Need a verification link? Resend it' }).click()
  await expect(page.getByRole('alert')).toContainText('Enter your email address above')
  await page.getByRole('textbox', { name: 'Email address' }).fill(email)
  await page.getByRole('button', { name: 'Need a verification link? Resend it' }).click()
  await expect(page.getByText('Use the local verification button below, then return here to sign in.')).toBeVisible()
  const verifyButton = page.getByRole('button', { name: /Verify email to unlock sign-in/ })
  await expect(verifyButton).toBeVisible()
  await expect(page.getByRole('textbox', { name: 'Email address' })).toBeVisible()
  await verifyButton.click()
  await expect(page.getByRole('status')).toContainText('Email verified. You can log in now.')
  await expect(page.getByLabel('Password', { exact: true })).toBeVisible()
})

test('delegated management sees read-only member access without audit permission', async ({ page }) => {
  const expectConnected = expect.configure({ timeout: 60_000 })
  let signedIn = false
  const account = { id: 74, name: 'Workspace Manager', email: 'manager@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: false }
  const membership = {
    membership_id: 92,
    role: 'management',
    organization: { id: 18, name: 'Browser Test School', slug: 'browser-test-school', type: 'school', status: 'active', timezone: 'Asia/Kolkata' },
  }
  const member = {
    id: 92,
    user: { id: account.id, name: account.name, email: account.email },
    role: 'management',
    status: 'active',
    accepted_at: '2026-11-19T12:00:00.000Z',
    permissions: { effective: ['organization.memberships.read'], overrides: [], editable: false },
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
  await page.route('**/api/v1/me/organization', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data: membership }),
  }))
  await page.route('**/api/v1/organizations/18/memberships**', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      data: [member],
      permission_catalog: [
        { key: 'organization.memberships.read', label: 'View members', description: 'View organization membership records and their status.' },
        { key: 'organization.memberships.manage', label: 'Manage members', description: 'Change eligible member roles and suspend or restore membership access.' },
        { key: 'organization.memberships.audit', label: 'View access audit', description: 'View membership role, status, and permission change history.' },
      ],
      can_manage_memberships: false,
      can_manage_permissions: false,
      meta: { current_page: 1, last_page: 1, total: 1 },
    }),
  }))
  await page.route('**/api/v1/organizations/18/membership-access-audit**', route => route.fulfill({
    status: 403,
    contentType: 'application/json',
    body: JSON.stringify({ message: 'Forbidden.' }),
  }))
  await page.route('**/api/v1/organizations/18/notifications**', route => {
    if (route.request().method() === 'POST') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { id: 'notice-1', read_at: '2026-11-20T08:00:00.000Z' } }) })
    }
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [{ id: 'notice-1', type: 'announcement', title: 'Updated school calendar', body: 'The new term dates are ready.', read_at: null, created_at: '2026-11-20T07:30:00.000Z' }],
        meta: { current_page: 1, last_page: 1, total: 1, unread: 1 },
      }),
    })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: /Browser Test School/ }).click()
  await expect(page).toHaveURL(/\/workspace\/management$/)
  const accessNav = page.getByRole('button', { name: 'Members & roles', exact: true })
  await expect(accessNav).toBeVisible()
  await accessNav.click()
  await expect(page.getByRole('heading', { name: 'Members and roles' })).toBeVisible()
  await expect(page.getByRole('row', { name: new RegExp(account.email) })).toBeVisible()
  await expect(page.getByText('You can view members, but your workspace role does not include access history.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Your account' })).toBeDisabled()
  const inboxNav = page.getByRole('button', { name: 'Inbox', exact: true })
  await expect(inboxNav).toBeVisible()
  await inboxNav.click()
  await expectConnected(page.getByRole('heading', { name: 'Notices', level: 2 })).toBeVisible()
  await expectConnected(page.getByText('Updated school calendar')).toBeVisible()
  await expectConnected(page.getByText('1 unread.')).toBeVisible()
  await page.getByRole('button', { name: 'Mark read' }).click()
  await expectConnected(page.getByText('0 unread.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Mark read' })).toHaveCount(0)
})

test('organization administrators can grant membership permissions with required read access', async ({ page }) => {
  let signedIn = false
  let grantedPermissions: string[] = []
  const account = { id: 84, name: 'Workspace Administrator', email: 'admin@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: false }
  const membership = {
    membership_id: 92,
    role: 'admin',
    organization: { id: 18, name: 'Browser Test School', slug: 'browser-test-school', type: 'school', status: 'active', timezone: 'Asia/Kolkata' },
  }
  const administrator = {
    id: 92, user: { id: account.id, name: account.name, email: account.email }, role: 'admin', status: 'active', accepted_at: '2026-11-19T12:00:00.000Z',
    permissions: { effective: ['organization.memberships.audit', 'organization.memberships.manage', 'organization.memberships.read'], overrides: [], editable: false },
  }
  const manager = {
    id: 93, user: { id: 85, name: 'School Manager', email: 'school-manager@example.test' }, role: 'management', status: 'active', accepted_at: '2026-11-19T12:00:00.000Z',
    permissions: { effective: ['organization.memberships.read', ...grantedPermissions], overrides: [...grantedPermissions], editable: true },
  }
  const permissionCatalog = [
    { key: 'organization.memberships.read', label: 'View members', description: 'View organization membership records and their status.' },
    { key: 'organization.memberships.manage', label: 'Manage members', description: 'Change eligible member roles and suspend or restore membership access.' },
    { key: 'organization.memberships.audit', label: 'View access audit', description: 'View membership role, status, and permission change history.' },
  ]

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
  await page.route('**/api/v1/organizations/18/invitations*', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { total: 0 } }) }))
  await page.route('**/api/v1/organizations/18/students*', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { total: 0 } }) }))
  await page.route('**/api/v1/organizations/18/memberships**', route => {
    if (route.request().method() === 'PATCH') {
      const payload = route.request().postDataJSON() as { permissions?: string[] }
      grantedPermissions = payload.permissions ?? []
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ...manager, permissions: { effective: grantedPermissions, overrides: grantedPermissions, editable: true } } }) })
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [administrator, manager], permission_catalog: permissionCatalog, can_manage_memberships: true, can_manage_permissions: true, meta: { current_page: 1, last_page: 1, total: 2 } }) })
  })
  await page.route('**/api/v1/organizations/18/membership-access-audit**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } }) }))

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: /Browser Test School/ }).click()
  await page.getByRole('button', { name: 'Invitations', exact: true }).click()
  await page.getByRole('tab', { name: 'Members & roles' }).click()
  await page.getByRole('row', { name: /school-manager@example\.test/ }).getByRole('button', { name: 'Edit' }).click()
  await page.getByRole('checkbox', { name: /Manage members/ }).check()
  await expect(page.getByRole('checkbox', { name: /^View members/ })).toBeChecked()
  await page.getByRole('button', { name: 'Save permissions' }).click()
  await expect(page.getByText('Access updated for School Manager.')).toBeVisible()
  expect(grantedPermissions).toEqual(expect.arrayContaining(['organization.memberships.read', 'organization.memberships.manage']))
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

const publicAndAccountRoutes = [
  '/', '/about', '/features', '/courses', '/courses/python-foundations',
  '/courses/algebra-step-by-step', '/courses/living-world-stories',
  '/solutions/institutions', '/solutions/teachers', '/solutions/students',
  '/pricing', '/contact', '/privacy', '/terms', '/cookies', '/acceptable-use',
  '/refund-policy', '/login', '/register',
]

for (const width of [320, 1440]) {
  test(`all public and account routes fit ${width}px viewport`, async ({ page }) => {
    test.setTimeout(120_000)
    await page.setViewportSize({ width, height: 900 })
    for (const route of publicAndAccountRoutes) {
      await page.goto(route, { waitUntil: 'domcontentloaded' })
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
      const pageWidth = await page.evaluate(() => document.documentElement.scrollWidth)
      expect(pageWidth, `${route} overflows at ${width}px`).toBeLessThanOrEqual(width)
    }
  })
}
