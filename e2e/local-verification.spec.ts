import { expect, test } from '@playwright/test'

test('local signup verifies in place and then allows sign-in', async ({ page }) => {
  test.setTimeout(120_000)
  const expectBackend = expect.configure({ timeout: 60_000 })
  const suffix = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
  const email = `verify-${suffix}@example.test`
  const password = 'LocalVerificationPass123'
  const organization = `Verification School ${suffix}`

  await page.goto('/register')
  await page.getByRole('textbox', { name: 'Full name' }).fill('Local Verification Tester')
  await page.getByRole('textbox', { name: 'Email address' }).fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('textbox', { name: 'Organization name' }).fill(organization)
  await page.getByLabel('Organization type').selectOption('school')
  await page.getByRole('textbox', { name: 'Confirm password' }).fill(password)
  await page.getByRole('button', { name: 'Create account' }).click()

  await expect(page.getByRole('heading', { name: 'You’re all set.' })).toBeVisible()
  await expect(page.getByText('Your local account is ready. Use the button below to verify it—no email inbox is needed.')).toBeVisible()
  await page.getByRole('button', { name: /Verify email to unlock sign-in/ }).click()
  await expect(page.getByRole('status')).toContainText('Email verified. You can log in now.')

  await page.getByRole('link', { name: 'Log in', exact: true }).click()
  await page.getByRole('textbox', { name: 'Email address' }).fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Log in' }).click()
  await expectBackend(page).toHaveURL(/\/select-organization$/)
  await expectBackend(page.getByRole('button', { name: new RegExp(organization) })).toBeVisible()
})
