import { expect, test } from '@playwright/test'

test('real local registration, signed email verification, and browser sign-in work together', async ({ page }) => {
  test.setTimeout(120_000)
  const expectBackend = expect.configure({ timeout: 30_000 })
  const unique = `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
  const email = `browser-${unique}@example.test`
  const organization = `Browser Test School ${unique}`
  const password = 'LocalIntegrationPass123'

  await page.goto('/register')
  await page.getByRole('textbox', { name: 'Full name' }).fill('E2E Browser Tester')
  await page.getByRole('textbox', { name: 'Email address' }).fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('textbox', { name: 'Organization name' }).fill(organization)
  await page.getByLabel('Organization type').selectOption('school')
  await page.getByRole('textbox', { name: 'Confirm password' }).fill(password)
  await page.getByRole('button', { name: 'Create account' }).click()

  await expectBackend(page.getByRole('heading', { name: 'You’re all set.' })).toBeVisible()
  const verifyLink = page.getByRole('link', { name: /Verify email to unlock sign-in/ })
  await expectBackend(verifyLink).toBeVisible()
  await expectBackend(verifyLink).toHaveAttribute('href', /127\.0\.0\.1:4188\/api\/v1\/auth\/verify-email/)

  const verificationTab = page.context().waitForEvent('page')
  await verifyLink.click()
  const verifiedPage = await verificationTab
  await expectBackend(verifiedPage.locator('body')).toContainText('Email address verified. You can now sign in.')

  await page.getByRole('link', { name: 'Log in', exact: true }).click()
  await page.getByRole('textbox', { name: 'Email address' }).fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Log in' }).click()

  await expectBackend(page).toHaveURL(/\/select-organization$/)
  await expectBackend(page.getByRole('heading', { name: 'Choose your workspace' })).toBeVisible()
  await expectBackend(page.getByRole('button', { name: new RegExp(organization) })).toBeVisible()
})
