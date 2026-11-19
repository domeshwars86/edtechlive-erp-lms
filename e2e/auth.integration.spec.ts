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

  await page.goto('/forgot-password')
  await page.getByRole('textbox', { name: 'Email address' }).fill(email)
  await page.getByRole('button', { name: 'Send reset link' }).click()
  await expectBackend(page.getByText(/Your local password reset link is ready/)).toBeVisible()
  const resetLink = page.getByRole('link', { name: 'Reset your password' })
  await expectBackend(resetLink).toHaveAttribute('href', new RegExp(`127\\.0\\.0\\.1:4188/reset-password\\?.*email=${encodeURIComponent(email)}`))
  await resetLink.click()
  await expectBackend(page).toHaveURL(/\/reset-password\?/)
  await expectBackend(page.getByRole('textbox', { name: 'Email address' })).toHaveValue(email)
  await page.getByLabel('New password', { exact: true }).fill('ResetIntegrationPass456')
  await page.getByRole('textbox', { name: 'Confirm password' }).fill('ResetIntegrationPass456')
  await page.getByRole('button', { name: 'Reset password' }).click()
  await expectBackend(page.getByText('Your password has been updated. You can now sign in.')).toBeVisible()

  await page.getByRole('link', { name: 'Back to log in' }).click()
  await page.getByRole('textbox', { name: 'Email address' }).fill(email)
  await page.getByLabel('Password', { exact: true }).fill('ResetIntegrationPass456')
  await page.getByRole('button', { name: 'Log in' }).click()
  await expectBackend(page).toHaveURL(/\/select-organization$/)
  const workspaceChoice = page.getByRole('button', { name: new RegExp(organization) })
  await expectBackend(workspaceChoice).toBeVisible()
  await workspaceChoice.click()
  await expectBackend(page).toHaveURL(/\/workspace\/admin$/)
  await expectBackend(page.getByText('CONNECTED ACCOUNT · WORKSPACE DATA')).toBeVisible()

  await page.getByRole('button', { name: 'Preferences', exact: true }).click()
  await expectBackend(page.getByRole('heading', { name: 'Preferences', exact: true })).toBeVisible()
  await expectBackend(page.getByRole('heading', { name: 'Notification preferences' })).toBeVisible()
  await expectBackend(page.getByText(/Email delivery is disabled until/)).toBeVisible()
  const announcements = page.locator('.notification-preference-row').first().locator('input')
  await expectBackend(announcements).toBeChecked()
  await announcements.uncheck()
  await expectBackend(page.getByText('Preferences saved.')).toBeVisible()
  await page.getByRole('button', { name: 'Overview', exact: true }).click()
  await page.getByRole('button', { name: 'Preferences', exact: true }).click()
  await expectBackend(page.locator('.notification-preference-row').first().locator('input')).not.toBeChecked()
})
