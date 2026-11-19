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
