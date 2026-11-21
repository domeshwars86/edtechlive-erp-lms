import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, type ApiUser } from '../lib/api'
import { AuthPage } from './AuthPages'
import { useAuth } from './useAuth'

vi.mock('./useAuth', () => ({ useAuth: vi.fn() }))
vi.mock('../lib/api', async (importOriginal) => {
  const original = await importOriginal<typeof import('../lib/api')>()

  return {
    ...original,
    api: {
      ...original.api,
      get: vi.fn(),
      post: vi.fn(),
    },
  }
})

function RoutePath() {
  return <div data-testid="current-route">{useLocation().pathname}</div>
}

function renderLogin() {
  return render(
    <MemoryRouter initialEntries={['/login']}>
      <Routes>
        <Route path="/login" element={<AuthPage mode="login" />} />
        <Route path="/select-organization" element={<h1>Choose your workspace</h1>} />
      </Routes>
      <RoutePath />
    </MemoryRouter>,
  )
}

function renderRegister() {
  return render(
    <MemoryRouter initialEntries={['/register']}>
      <Routes>
        <Route path="/register" element={<AuthPage mode="register" />} />
        <Route path="/login" element={<AuthPage mode="login" />} />
      </Routes>
      <RoutePath />
    </MemoryRouter>,
  )
}

function mockAccountSession(signIn: (email: string, password: string, remember?: boolean) => Promise<ApiUser>) {
  vi.mocked(useAuth).mockReturnValue({
    user: null,
    loading: false,
    activeMembership: null,
    refresh: vi.fn().mockResolvedValue(null),
    updateUser: vi.fn(),
    signIn,
    signOut: vi.fn().mockResolvedValue(undefined),
    selectOrganization: vi.fn().mockResolvedValue(undefined),
  })
}

describe('account verification and sign-in', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockResolvedValue({ status: 'verified' })
    vi.mocked(api.post).mockResolvedValue({})
  })

  it('keeps a login path available after an unverified sign-in and verifies a resent local link in place', async () => {
    const user = userEvent.setup()
    const account: ApiUser = {
      id: 42,
      name: 'Asha Teacher',
      email: 'asha@example.test',
      email_verified_at: new Date().toISOString(),
      is_platform_admin: false,
    }
    const signIn = vi.fn<(email: string, password: string, remember?: boolean) => Promise<ApiUser>>()
      .mockRejectedValueOnce(new ApiError('Verify your email address before signing in.', 403, 'email_verification_required'))
      .mockResolvedValueOnce(account)
    mockAccountSession(signIn)
    const verificationUrl = 'http://127.0.0.1:8001/api/v1/auth/verify-email/42/hash?expires=1795190000&signature=local'

    renderLogin()

    await user.type(screen.getByRole('textbox', { name: 'Email address' }), account.email)
    await user.type(screen.getByLabelText('Password'), 'CorrectHorseBattery1')
    await user.click(screen.getByRole('button', { name: /Log in/ }))

    const verificationAlert = await screen.findByRole('alert')
    expect(verificationAlert).toHaveTextContent(/Verify your email address/)
    expect(within(verificationAlert).getByRole('button', { name: 'Resend verification link' })).toBeVisible()

    vi.mocked(api.post).mockResolvedValueOnce({ verification_url: verificationUrl })
    await user.click(within(verificationAlert).getByRole('button', { name: 'Resend verification link' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/api/v1/auth/email/verification-notification', { email: account.email }))
    const verifyButton = await screen.findByRole('button', { name: 'Verify email to unlock sign-in' })
    await user.click(verifyButton)

    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/api/v1/auth/verify-email/42/hash?expires=1795190000&signature=local'))
    expect(await screen.findByRole('status')).toHaveTextContent('Email verified. You can log in now.')
    expect(screen.getByRole('button', { name: /Log in/ })).toBeVisible()

    await user.click(screen.getByRole('button', { name: /Log in/ }))
    await waitFor(() => expect(screen.getByRole('heading', { name: 'Choose your workspace' })).toBeVisible())
    expect(screen.getByTestId('current-route')).toHaveTextContent('/select-organization')
  })

  it('shows a local verification action after account creation and returns to login after confirmation', async () => {
    const user = userEvent.setup()
    mockAccountSession(vi.fn())
    const verificationUrl = 'http://127.0.0.1:8001/api/v1/auth/verify-email/43/hash?expires=1795190000&signature=local'
    vi.mocked(api.post).mockResolvedValueOnce({ verification_url: verificationUrl })

    renderRegister()

    await user.type(screen.getByRole('textbox', { name: 'Full name' }), 'Asha Teacher')
    await user.type(screen.getByRole('textbox', { name: 'Email address' }), 'asha@example.test')
    await user.type(screen.getByLabelText('Password'), 'CorrectHorseBattery1')
    await user.type(screen.getByLabelText('Organization name'), 'Asha Learning')
    await user.type(screen.getByLabelText('Confirm password'), 'CorrectHorseBattery1')
    await user.click(screen.getByRole('button', { name: /Create account/ }))

    expect(await screen.findByText(/Your local account is ready/)).toBeVisible()
    expect(await screen.findByText(/No inbox is needed/)).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Verify email to unlock sign-in' }))

    expect(await screen.findByRole('status')).toHaveTextContent('Email verified. You can log in now.')
    await user.click(screen.getByRole('link', { name: 'Log in' }))
    expect(await screen.findByRole('heading', { name: 'Welcome back' })).toBeVisible()
    expect(screen.getByTestId('current-route')).toHaveTextContent('/login')
  })
})
