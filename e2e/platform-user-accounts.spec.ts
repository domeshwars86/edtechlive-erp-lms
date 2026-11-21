import { expect, test } from '@playwright/test'

test('platform user status changes require a reason and refresh the account listing', async ({ page }) => {
  let signedIn = false
  let status: 'active' | 'suspended' = 'active'
  let savedReason = ''
  const account = { id: 84, name: 'Platform Operator', email: 'operator@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: true }
  const user = { id: 110, name: 'Learner Example', email: 'learner@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', created_at: '2026-11-18T10:00:00.000Z', active_organizations: 1, status_change_allowed: true }

  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/me', route => route.fulfill({ status: signedIn ? 200 : 401, contentType: 'application/json', body: JSON.stringify(signedIn ? { data: account } : { message: 'Unauthenticated.' }) }))
  await page.route('**/api/v1/auth/login', route => {
    signedIn = true
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: account }) })
  })
  await page.route('**/api/v1/organizations?per_page=100', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], preferred_organization_id: null, meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }) }))
  await page.route('**/api/v1/platform/overview', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { organizations: { total: 1, active: 1, suspended: 0 }, users: { total: 2, verified: 2 }, educators: 1, open_enquiries: 0, recent_organizations: [] } }) }))
  await page.route('**/api/v1/platform/audit-events**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
    data: status === 'suspended' ? [{ id: 201, organization_id: null, organization_name: 'Platform accounts', action: 'user.status_changed', reason: savedReason, before: { user_id: user.id, email: user.email, status: 'active' }, after: { user_id: user.id, email: user.email, status: 'suspended' }, actor: { name: account.name, email: account.email }, created_at: '2026-11-19T12:30:00.000Z' }] : [],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: status === 'suspended' ? 1 : 0 },
  }) }))
  await page.route('**/api/v1/platform/users**', async route => {
    if (route.request().method() === 'PATCH') {
      const body = route.request().postDataJSON() as { status: 'active' | 'suspended'; reason: string }
      status = body.status
      savedReason = body.reason
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { id: user.id, platform_status: status } }) })
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      data: [{ ...user, platform_status: status }],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    }) })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: 'Users', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Users', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Suspend', exact: true }).click()
  await page.getByLabel('Required reason').fill('Confirmed policy violation after review.')
  await page.getByRole('button', { name: 'Confirm status change' }).click()

  await expect.poll(() => savedReason).toBe('Confirmed policy violation after review.')
  await expect(page.getByRole('status')).toContainText('is now suspended')
  await expect(page.getByRole('table').getByText('Suspended', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Audit logs', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Platform status audit' })).toBeVisible()
  await expect(page.getByRole('table').getByText(user.email)).toBeVisible()
  await expect(page.getByRole('table').getByText(savedReason)).toBeVisible()
})

test('platform operators can send a reasoned staff invitation and receive the local acceptance link', async ({ page }) => {
  let signedIn = false
  let invitation: { organization_id: number; email: string; role: string; reason: string } | null = null
  let invitationStatus: 'pending' | 'revoked' = 'pending'
  let revocationReason = ''
  let resendReason = ''
  const account = { id: 84, name: 'Platform Operator', email: 'operator@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: true }
  const invitationUrl = 'http://127.0.0.1:4188/invitations/accept?token=local-invitation-token'

  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/me', route => route.fulfill({ status: signedIn ? 200 : 401, contentType: 'application/json', body: JSON.stringify(signedIn ? { data: account } : { message: 'Unauthenticated.' }) }))
  await page.route('**/api/v1/auth/login', route => {
    signedIn = true
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: account }) })
  })
  await page.route('**/api/v1/organizations?per_page=100', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], preferred_organization_id: null, meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }) }))
  await page.route('**/api/v1/platform/overview', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { organizations: { total: 1, active: 1, suspended: 0 }, users: { total: 2, verified: 2 }, educators: 1, open_enquiries: 0, recent_organizations: [] } }) }))
  await page.route('**/api/v1/platform/organizations**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
    data: [{ id: 31, name: 'Northstar Academy', status: 'active' }],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
  }) }))
  await page.route('**/api/v1/platform/users**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }) }))
  await page.route('**/api/v1/platform/audit-events**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }) }))
  await page.route('**/api/v1/platform/invitations**', async route => {
    const invitationPath = new URL(route.request().url()).pathname
    if (route.request().method() === 'POST' && invitationPath.endsWith('/resend')) {
      resendReason = (route.request().postDataJSON() as { reason: string }).reason
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { id: 72, status: 'pending', expires_at: '2026-11-27T12:00:00.000Z' }, message: 'Invitation renewed and email sent.', invitation_url: invitationUrl }) })
    }
    if (route.request().method() === 'POST') {
      invitation = route.request().postDataJSON() as { organization_id: number; email: string; role: string; reason: string }
      return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({
        data: { id: 72, organization_id: 31, organization: 'Northstar Academy', email: invitation.email, role: invitation.role, expires_at: '2026-11-27T12:00:00.000Z' },
        message: 'Invitation sent.',
        invitation_url: invitationUrl,
      }) })
    }
    if (route.request().method() === 'DELETE') {
      revocationReason = (route.request().postDataJSON() as { reason: string }).reason
      invitationStatus = 'revoked'
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { id: 72, status: 'revoked' }, message: 'Invitation revoked.' }) })
    }
    const requestedStatus = new URL(route.request().url()).searchParams.get('status')
    const includeInvitation = invitation !== null && requestedStatus === invitationStatus
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      data: includeInvitation ? [{ id: 72, organization_id: 31, organization: 'Northstar Academy', email: 'new.teacher@example.test', role: 'teacher', status: invitationStatus, expires_at: '2026-11-27T12:00:00.000Z', created_at: '2026-11-20T12:00:00.000Z', revocation_reason: invitationStatus === 'revoked' ? revocationReason : null }] : [],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: includeInvitation ? 1 : 0 },
    }) })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: 'Users', exact: true }).click()
  await page.getByRole('button', { name: 'Invite staff account' }).click()
  await page.getByLabel('Invitation organization').selectOption('31')
  await page.getByLabel('Email address', { exact: true }).fill('new.teacher@example.test')
  await page.getByLabel('Organization role', { exact: true }).selectOption('teacher')
  await page.getByLabel('Required audit reason').fill('Approved staffing request for the new term.')
  await page.getByRole('button', { name: 'Send invitation' }).click()

  await expect.poll(() => invitation).toEqual({
    organization_id: 31,
    email: 'new.teacher@example.test',
    role: 'teacher',
    reason: 'Approved staffing request for the new term.',
  })
  await expect(page.getByRole('status')).toContainText('was invited to Northstar Academy as teacher')
  await expect(page.getByRole('link', { name: 'Open local invitation link' })).toHaveAttribute('href', invitationUrl)
  await page.getByRole('button', { name: 'Refresh staff invitations' }).click()
  await expect(page.getByRole('table').getByText('new.teacher@example.test')).toBeVisible()
  await page.getByRole('button', { name: 'Resend', exact: true }).click()
  await page.getByLabel('Required audit reason').last().fill('Recipient requested a fresh link.')
  await page.getByRole('button', { name: 'Confirm resend' }).click()
  await expect.poll(() => resendReason).toBe('Recipient requested a fresh link.')
  await expect(page.getByRole('status').filter({ hasText: 'Invitation renewed and email sent.' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Open renewed local invitation link' })).toHaveAttribute('href', invitationUrl)
  await page.getByRole('button', { name: 'Revoke', exact: true }).click()
  await page.getByLabel('Required audit reason').last().fill('Staffing request was withdrawn.')
  await page.getByRole('button', { name: 'Confirm revoke' }).click()
  await expect.poll(() => revocationReason).toBe('Staffing request was withdrawn.')
  await expect(page.getByRole('status').filter({ hasText: 'Invitation revoked.' })).toBeVisible()
  await page.getByLabel('Filter invitation status').selectOption('revoked')
  await expect(page.getByRole('table').getByText('revoked', { exact: true })).toBeVisible()
})

test('platform operators can edit account identity with an audited reason and email re-verification', async ({ page }) => {
  let signedIn = false
  let savedProfile: { name: string; email: string; reason: string } | null = null
  let resetReason = ''
  const account = { id: 84, name: 'Platform Operator', email: 'operator@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: true }
  const verificationUrl = 'http://127.0.0.1:4188/api/v1/auth/verify-email/110/abc123?expires=1795170000&signature=valid'
  const resetUrl = 'http://127.0.0.1:4188/reset-password?token=local-reset-token&email=updated.learner%40example.test'

  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/me', route => route.fulfill({ status: signedIn ? 200 : 401, contentType: 'application/json', body: JSON.stringify(signedIn ? { data: account } : { message: 'Unauthenticated.' }) }))
  await page.route('**/api/v1/auth/login', route => {
    signedIn = true
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: account }) })
  })
  await page.route('**/api/v1/organizations?per_page=100', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], preferred_organization_id: null, meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }) }))
  await page.route('**/api/v1/platform/overview', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { organizations: { total: 1, active: 1, suspended: 0 }, users: { total: 2, verified: 2 }, educators: 1, open_enquiries: 0, recent_organizations: [] } }) }))
  await page.route('**/api/v1/platform/audit-events**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }) }))
  await page.route('**/api/v1/platform/invitations**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }) }))
  await page.route('**/api/v1/platform/users**', async route => {
    if (route.request().method() === 'POST' && new URL(route.request().url()).pathname.endsWith('/password-reset')) {
      resetReason = (route.request().postDataJSON() as { reason: string }).reason
      return route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ message: 'If the account can receive a password reset, instructions have been sent.', reset_url: resetUrl }) })
    }
    if (route.request().method() === 'PATCH') {
      savedProfile = route.request().postDataJSON() as { name: string; email: string; reason: string }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: { id: 110, name: savedProfile.name, email: savedProfile.email, email_verified_at: null },
        email_changed: true,
        verification_url: verificationUrl,
      }) })
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      data: [{ id: 110, name: savedProfile?.name ?? 'Learner Example', email: savedProfile?.email ?? 'learner@example.test', email_verified_at: savedProfile ? null : '2026-11-18T10:00:00.000Z', created_at: '2026-11-18T10:00:00.000Z', active_organizations: 1, platform_status: 'active', status_change_allowed: true }],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    }) })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: 'Users', exact: true }).click()
  await page.getByRole('button', { name: 'Edit identity' }).click()
  await page.getByLabel('Account name').fill('Updated Learner')
  await page.getByLabel('Email address', { exact: true }).last().fill('updated.learner@example.test')
  await page.getByLabel('Required audit reason').fill('Corrected identity after support review.')
  await page.getByRole('button', { name: 'Save identity' }).click()

  await expect.poll(() => savedProfile).toEqual({ name: 'Updated Learner', email: 'updated.learner@example.test', reason: 'Corrected identity after support review.' })
  await expect(page.getByRole('table').getByText('updated.learner@example.test')).toBeVisible()
  await expect(page.getByRole('table').getByText('Pending', { exact: true })).toBeVisible()
  await expect(page.getByRole('status')).toContainText('must be verified before sign-in')
  await expect(page.getByRole('link', { name: 'Open local verification link' })).toHaveAttribute('href', verificationUrl)
  await page.getByRole('button', { name: 'Reset password', exact: true }).click()
  await page.getByLabel('Required audit reason').fill('Account owner requested recovery.')
  await page.getByRole('button', { name: 'Send reset link' }).click()
  await expect.poll(() => resetReason).toBe('Account owner requested recovery.')
  await expect(page.getByRole('link', { name: 'Open local password-reset link' })).toHaveAttribute('href', resetUrl)
})

test('platform operators can change a membership role with an audited reason', async ({ page }) => {
  test.setTimeout(60_000)
  let signedIn = false
  let savedChange: { role: string; reason: string } | null = null
  let membership = { id: 504, organization_id: 42, organization: 'Harbor Academy', organization_status: 'active', role: 'teacher', status: 'active' }
  const account = { id: 84, name: 'Platform Operator', email: 'operator@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', is_platform_admin: true }
  const user = { id: 110, name: 'Learner Example', email: 'learner@example.test', email_verified_at: '2026-11-19T12:00:00.000Z', created_at: '2026-11-18T10:00:00.000Z', active_organizations: 1, platform_status: 'active', status_change_allowed: true }

  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }))
  await page.route('**/api/v1/me', route => route.fulfill({ status: signedIn ? 200 : 401, contentType: 'application/json', body: JSON.stringify(signedIn ? { data: account } : { message: 'Unauthenticated.' }) }))
  await page.route('**/api/v1/auth/login', route => {
    signedIn = true
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: account }) })
  })
  await page.route('**/api/v1/organizations?per_page=100', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], preferred_organization_id: null, meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }) }))
  await page.route('**/api/v1/platform/overview', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { organizations: { total: 1, active: 1, suspended: 0 }, users: { total: 2, verified: 2 }, educators: 1, open_enquiries: 0, recent_organizations: [] } }) }))
  await page.route('**/api/v1/platform/users**', async route => {
    const pathname = new URL(route.request().url()).pathname
    if (pathname.endsWith('/memberships') && route.request().method() === 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [membership] }) })
    }
    if (pathname.includes('/memberships/') && route.request().method() === 'PATCH') {
      savedChange = route.request().postDataJSON() as { role: string; reason: string }
      membership = { ...membership, role: savedChange.role }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: membership }) })
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [user], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } }) })
  })

  await page.goto('/login')
  await page.getByRole('textbox', { name: 'Email address' }).fill(account.email)
  await page.getByLabel('Password', { exact: true }).fill('LocalBrowserPass123')
  await page.getByRole('button', { name: 'Log in' }).click()
  await page.getByRole('button', { name: 'Users', exact: true }).click()
  await page.getByRole('button', { name: 'Manage roles', exact: true }).click()
  const membershipPanel = page.getByRole('region', { name: 'Organization roles for Learner Example' })
  await expect(membershipPanel).toContainText('Harbor Academy')
  await page.setViewportSize({ width: 320, height: 780 })
  await expect(membershipPanel).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320)
  await page.getByRole('button', { name: 'Change role', exact: true }).click()
  await page.getByLabel('Organization role', { exact: true }).selectOption('student')
  await page.getByLabel('Required audit reason').last().fill('Corrected role after membership review.')
  await page.getByRole('button', { name: 'Save role' }).click()

  await expect.poll(() => savedChange).toEqual({ role: 'student', reason: 'Corrected role after membership review.' })
  await expect(page.getByRole('status')).toContainText('role at Harbor Academy is now student')
  await expect(membershipPanel).toContainText('student')
})
