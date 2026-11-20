import { expect, test } from '@playwright/test'

test('family fee checkout uses test mode and updates the ledger only after server confirmation', async ({ page }) => {
  let signedIn = false
  let paid = false
  let orderKey = ''
  let verifiedPayload: Record<string, string> | null = null
  const account = { id: 84, name: 'Parent Example', email: 'parent@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: false }
  const membership = {
    membership_id: 92,
    role: 'parent',
    organization: { id: 18, name: 'Browser Test School', slug: 'browser-test-school', type: 'school', status: 'active', timezone: 'Asia/Kolkata' },
  }

  await page.addInitScript(() => {
    Object.defineProperty(window, 'Razorpay', {
      configurable: true,
      value: class {
        private readonly options: { handler: (response: { razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }) => Promise<void> }
        constructor(options: { handler: (response: { razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }) => Promise<void> }) {
          this.options = options
        }
        open() {
          void this.options.handler({
            razorpay_order_id: 'order_browser_test_001',
            razorpay_payment_id: 'pay_browser_test_001',
            razorpay_signature: 'a'.repeat(64),
          })
        }
      },
    })
  })

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
    if (pathname.endsWith('/payments/razorpay/config')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        enabled: true, key_id: 'rzp_test_browser', brand_name: 'edTechLive', currency: 'INR', mode: 'test',
      } }) })
    }
    if (pathname.endsWith('/dashboard/summary')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        kind: 'parent', date: '2026-11-20', children: 1, assignments_pending: 0, attendance_recent: { records: 0, rate: null }, upcoming_meetings: 0,
      } }) })
    }
    if (pathname.endsWith('/dashboard/activity')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { days: [] } }) })
    }
    if (pathname.endsWith('/fees') && request.method() === 'GET') {
      const fee = {
        id: 7,
        student: { id: 17, name: 'Learner Example', admission_number: 'ST-100' },
        title: 'Term tuition', category: null, amount: '120.00', paid_amount: paid ? '120.00' : '0.00',
        balance: paid ? '0.00' : '120.00', due_on: '2026-12-01', status: paid ? 'paid' : 'unpaid',
        payments: paid ? [{ id: 44, amount: '120.00', method: 'razorpay', reference: 'pay_browser_test_001', received_at: '2026-11-20T12:00:00.000Z' }] : [],
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [fee], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }) })
    }
    if (pathname.endsWith('/fees/7/razorpay/checkout')) {
      orderKey = request.headers()['idempotency-key'] ?? ''
      return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: {
        order_id: 'order_browser_test_001', amount: 12000, currency: 'INR', key_id: 'rzp_test_browser', brand_name: 'edTechLive', status: 'created',
      } }) })
    }
    if (pathname.endsWith('/fees/7/razorpay/verify')) {
      verifiedPayload = request.postDataJSON() as Record<string, string>
      paid = true
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { status: 'captured', message: 'Test payment confirmed and fee balance updated.' } }) })
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }) })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: /Browser Test School/ }).click()
  await page.getByRole('button', { name: 'Fee information', exact: true }).click()

  await expect(page.getByText(/Razorpay Test Mode only/)).toBeVisible()
  await page.getByRole('button', { name: 'Pay online · Test Mode' }).click()
  await expect(page.getByText('Test payment confirmed. The fee balance has been updated.')).toBeVisible()
  await expect(page.getByText('paid', { exact: true })).toBeVisible()
  expect(orderKey).toHaveLength(36)
  expect(verifiedPayload).toEqual({
    razorpay_order_id: 'order_browser_test_001',
    razorpay_payment_id: 'pay_browser_test_001',
    razorpay_signature: 'a'.repeat(64),
  })
})
