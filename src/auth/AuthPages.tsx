import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { ArrowUpRight, Check, LoaderCircle, ShieldCheck } from 'lucide-react'
import { useAuth } from './useAuth'
import { ApiError, api, type OrganizationListResponse, type OrganizationMembership } from '../lib/api'

type AuthMode = 'login' | 'register' | 'forgot' | 'reset' | 'organization'

const workspaceForRole: Record<OrganizationMembership['role'], string> = {
  admin: 'admin', management: 'management', teacher: 'teacher', student: 'student',
  parent: 'parent', independent_educator: 'educator',
}

function AccountBrand({ light = false }: { light?: boolean }) {
  return <Link className={`brand ${light ? 'brand-light' : ''}`} to="/" aria-label="edTechLive home"><span className="brand-mark"><span /><span /><span /></span><span className="brand-name">edTech<span>Live</span></span></Link>
}

function AccountShell({ children }: { children: ReactNode }) {
  return <section className="auth-page"><div className="auth-art"><AccountBrand light /><div className="auth-art-content"><div className="auth-sun">✳</div><h2>Learning is a journey<br />we take <em>together.</em></h2><p>Your community, your courses, your next step—all in one thoughtful space.</p><span className="auth-art-orbit">✦</span></div><small>LEARN. TEACH. MANAGE. GROW.</small></div><div className="auth-panel"><div className="auth-mobile-brand"><AccountBrand /></div>{children}<Link className="auth-back" to="/">← Back to edTechLive</Link></div></section>
}

export function AuthPage({ mode }: { mode: AuthMode }) {
  const navigate = useNavigate()
  const location = useLocation()
  const { user, loading, signIn, selectOrganization } = useAuth()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [organizations, setOrganizations] = useState<OrganizationMembership[]>([])
  const formRef = useRef<HTMLFormElement>(null)

  useEffect(() => {
    if (mode !== 'organization') return
    if (!loading && !user) navigate('/login', { replace: true })
    if (user) {
      api.get<OrganizationListResponse>('/api/v1/organizations')
        .then((response) => setOrganizations(response.data))
        .catch((reason: unknown) => setError(reason instanceof Error ? reason.message : 'Could not load your organizations.'))
    }
  }, [mode, loading, user, navigate])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = event.currentTarget
    if (!form.reportValidity()) return
    const values = new FormData(form)
    setBusy(true)
    setError('')
    setMessage('')

    try {
      if (mode === 'login') {
        await signIn(String(values.get('email')), String(values.get('password')), values.get('remember') === 'on')
        navigate('/select-organization')
      } else if (mode === 'register') {
        await api.post('/api/v1/auth/register', {
          name: values.get('name'), email: values.get('email'), password: values.get('password'),
          password_confirmation: values.get('password_confirmation'), organization_name: values.get('organization_name'),
          organization_type: values.get('organization_type'),
        })
        setMessage('If this address can be registered, check your inbox for a verification link to finish setup.')
        form.reset()
      } else if (mode === 'forgot') {
        await api.post('/api/v1/auth/forgot-password', { email: values.get('email') })
        setMessage('If an account exists for that email, password reset instructions have been sent.')
      } else if (mode === 'reset') {
        await api.post('/api/v1/auth/reset-password', {
          email: values.get('email'), token: new URLSearchParams(location.search).get('token'),
          password: values.get('password'), password_confirmation: values.get('password_confirmation'),
        })
        setMessage('Your password has been updated. You can now sign in.')
      }
    } catch (reason) {
      if (reason instanceof ApiError && reason.code === 'email_verification_required') {
        setError('Verify your email address before signing in. You can request a new link below.')
      } else {
        setError(reason instanceof Error ? reason.message : 'The request could not be completed.')
      }
    } finally {
      setBusy(false)
    }
  }

  async function resendVerification() {
    const email = formRef.current ? new FormData(formRef.current).get('email') : null
    if (typeof email !== 'string') return
    setBusy(true)
    setError('')
    try {
      await api.post('/api/v1/auth/email/verification-notification', { email })
      setMessage('If that address has an unverified account, a new verification link has been sent.')
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not send a verification link.')
    } finally {
      setBusy(false)
    }
  }

  function pickOrganization(membership: OrganizationMembership) {
    selectOrganization(membership)
    navigate(`/workspace/${workspaceForRole[membership.role]}`)
  }

  if (mode === 'organization') {
    return <AccountShell><div className="auth-form"><div className="eyebrow"><span />YOUR ACCOUNT</div><h1>Choose your workspace</h1><p>Select an organization where you have an active membership.</p>
      {loading && <div className="auth-feedback" role="status"><LoaderCircle className="spin" />Checking your session…</div>}
      {error && <div className="auth-error" role="alert">{error}</div>}
      {!loading && user && organizations.map((membership) => <button className="organization-choice" key={membership.membership_id} onClick={() => pickOrganization(membership)}><span className="organization-initial">{membership.organization.name.slice(0, 1)}</span><span><b>{membership.organization.name}</b><small>{membership.role.replace('_', ' ')} · {membership.organization.type.replaceAll('_', ' ')}</small></span><ArrowUpRight size={17} /></button>)}
      {!loading && user && organizations.length === 0 && !error && <div className="auth-feedback">No active organization memberships are available for this account.</div>}
      <div className="auth-links"><button type="button" onClick={() => navigate('/login')}>Use another account</button></div>
      <small className="auth-demo-note"><ShieldCheck size={13} />Organization choices are checked by the API.</small>
    </div></AccountShell>
  }

  const title = { login: 'Welcome back', register: 'Make room to learn', forgot: 'Find your way back', reset: 'Choose a new password' }[mode]
  const intro = { login: 'A familiar place for your learning day.', register: 'Create an account and set up your learning workspace.', forgot: 'Enter your email and we’ll help you reset your password.', reset: 'Use a strong password you have not used elsewhere.' }[mode]

  return <AccountShell><form ref={formRef} className="auth-form" onSubmit={submit}>
    <div className="eyebrow"><span />EDTECHLIVE ACCOUNT</div>
    <h1>{message ? 'You’re all set.' : title}</h1>
    <p>{message || intro}</p>
    {!message && <>
      {mode === 'register' && <label>Full name<input name="name" autoComplete="name" required minLength={2} maxLength={120} placeholder="Your name" /></label>}
      <label>Email address<input name="email" autoComplete="email" type="email" required maxLength={254} defaultValue={mode === 'reset' ? new URLSearchParams(location.search).get('email') ?? '' : ''} placeholder="you@example.com" /></label>
      {(mode === 'login' || mode === 'register') && <label>Password<input name="password" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} type="password" required minLength={mode === 'register' ? 12 : 1} placeholder={mode === 'register' ? 'At least 12 characters, with letters and numbers' : 'Your password'} /></label>}
      {(mode === 'register' || mode === 'reset') && <label>{mode === 'register' ? 'Organization name' : 'New password'}<input name={mode === 'register' ? 'organization_name' : 'password'} autoComplete={mode === 'register' ? 'organization' : 'new-password'} type={mode === 'register' ? 'text' : 'password'} required minLength={mode === 'register' ? 2 : 12} maxLength={160} placeholder={mode === 'register' ? 'Your school, studio or learning space' : 'At least 12 characters, with letters and numbers'} /></label>}
      {mode === 'register' && <label>Organization type<select name="organization_type" required defaultValue="school"><option value="school">School</option><option value="college">College</option><option value="coaching_institute">Coaching institute</option><option value="academy">Academy</option><option value="independent_educator">Independent educator</option></select></label>}
      {(mode === 'register' || mode === 'reset') && <label>Confirm password<input name="password_confirmation" autoComplete="new-password" type="password" required minLength={12} placeholder="Enter the password again" /></label>}
      {mode === 'login' && <label className="auth-remember"><input name="remember" type="checkbox" /> Keep me signed in</label>}
      {error && <div className="auth-error" role="alert">{error}{mode === 'login' && error.includes('Verify your email') && <button type="button" disabled={busy} onClick={resendVerification}>Send a new verification link</button>}</div>}
      <button className="button button-primary" type="submit" disabled={busy}>{busy ? <><LoaderCircle className="spin" /> Working…</> : <>{ { login: 'Log in', register: 'Create account', forgot: 'Send reset link', reset: 'Reset password' }[mode]} <ArrowUpRight size={16} /></>}</button>
    </>}
    {message && <div className="auth-success"><Check />{mode === 'register' ? 'Email verification is required before you can sign in.' : 'The request was sent securely.'}</div>}
    {error && !message && mode !== 'login' && <div className="auth-error" role="alert">{error}</div>}
    <div className="auth-links">{mode === 'login' && <><Link to="/forgot-password">Forgot password?</Link><span>New here? <Link to="/register">Create an account</Link></span></>}{mode === 'register' && <span>Already have an account? <Link to="/login">Log in</Link></span>}{mode === 'forgot' && <Link to="/login">Back to log in</Link>}{mode === 'reset' && <Link to="/login">Back to log in</Link>}</div>
    <small className="auth-demo-note"><ShieldCheck size={13} />Your account requests are sent to the edTechLive API.</small>
  </form></AccountShell>
}
