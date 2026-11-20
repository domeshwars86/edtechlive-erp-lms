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
  await page.getByRole('button', { name: 'Revoke', exact: true }).click()
  await page.getByLabel('Required audit reason').last().fill('Staffing request was withdrawn.')
  await page.getByRole('button', { name: 'Confirm revoke' }).click()
  await expect.poll(() => revocationReason).toBe('Staffing request was withdrawn.')
  await expect(page.getByRole('status').filter({ hasText: 'Invitation revoked.' })).toBeVisible()
  await page.getByLabel('Filter invitation status').selectOption('revoked')
  await expect(page.getByRole('table').getByText('revoked', { exact: true })).toBeVisible()
})
