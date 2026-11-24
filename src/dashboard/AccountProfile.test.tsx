import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import type { ApiUser } from '../lib/api'
import AccountProfile from './AccountProfile'

vi.mock('../lib/api', async (importOriginal) => {
  const original = await importOriginal<typeof import('../lib/api')>()
  return { ...original, api: { ...original.api, post: vi.fn(), patch: vi.fn() } }
})

const user: ApiUser = {
  id: 52,
  name: 'Learner',
  email: 'learner@example.test',
  email_verified_at: null,
  is_platform_admin: false,
}

describe('account email verification recovery', () => {
  beforeEach(() => {
    vi.mocked(api.post).mockResolvedValue({ verification_url: 'http://localhost/api/v1/auth/verify-email/52/hash?signature=local' })
  })

  it('requests a fresh verification link for the signed-in account and shows the local action', async () => {
    const onUpdated = vi.fn()
    const actions = userEvent.setup()
    render(<AccountProfile user={user} onUpdated={onUpdated} />)

    await actions.click(screen.getByRole('button', { name: 'Resend verification link' }))

    expect(await screen.findByRole('status')).toHaveTextContent('A local verification link is ready below.')
    expect(screen.getByRole('link', { name: 'Verify email address' })).toHaveAttribute('href', 'http://localhost/api/v1/auth/verify-email/52/hash?signature=local')
    expect(api.post).toHaveBeenCalledWith('/api/v1/auth/email/verification-notification', { email: user.email })
  })
})
