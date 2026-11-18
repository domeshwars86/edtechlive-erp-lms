import { useEffect, useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { ArrowUpRight, LoaderCircle, ShieldCheck } from 'lucide-react'
import { useAuth } from './useAuth'
import { api } from '../lib/api'

type Invitation = { email: string; role: string; organization_id: number; organization: string; expires_at: string }

export default function InvitationAccept() {
  const location = useLocation()
  const navigate = useNavigate()
  const { user, loading, refresh } = useAuth()
  const token = new URLSearchParams(location.search).get('token') ?? ''
  const [invitation, setInvitation] = useState<Invitation | null>(null)
  const accountMatches = !user || !invitation || user.email.toLowerCase() === invitation.email.toLowerCase()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState(false)

  useEffect(() => {
    if (!token) { setError('This invitation link is missing its token.'); return }
    api.get<{ data: Invitation }>(`/api/v1/auth/invitations/${token}`)
      .then(response => setInvitation(response.data))
      .catch(reason => setError(reason instanceof Error ? reason.message : 'This invitation is invalid or has expired.'))
  }, [token])

  async function accept(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true); setError('')
    try {
      if (user && invitation) {
        if (user.email.toLowerCase() !== invitation.email.toLowerCase()) throw new Error(`Sign in as ${invitation.email} to accept this invitation.`)
        await api.post(`/api/v1/organizations/${invitation.organization_id}/invitations/${token}/accept`)
        setSuccess(true)
        await refresh()
        navigate('/select-organization', { replace: true })
        return
      }
      const values = new FormData(event.currentTarget)
      await api.post('/api/v1/auth/invitations/register', {
        token, name: values.get('name'), password: values.get('password'), password_confirmation: values.get('password_confirmation'),
      })
      setSuccess(true)
      const currentUser = await refresh()
      navigate(currentUser ? '/select-organization' : '/login', { replace: true })
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'The invitation could not be accepted.') }
    finally { setBusy(false) }
  }

  return <section className="auth-page"><div className="auth-art"><Link className="brand brand-light" to="/" aria-label="edTechLive home"><span className="brand-mark"><span /><span /><span /></span><span className="brand-name">edTech<span>Live</span></span></Link><div className="auth-art-content"><div className="auth-sun">✳</div><h2>Learning is a journey<br />we take <em>together.</em></h2><p>Your community, your courses, your next step—all in one thoughtful space.</p></div><small>LEARN. TEACH. MANAGE. GROW.</small></div><div className="auth-panel"><div className="auth-mobile-brand"><Link className="brand" to="/"><span className="brand-mark"><span /><span /><span /></span><span className="brand-name">edTech<span>Live</span></span></Link></div>
    <form className="auth-form" onSubmit={accept}><div className="eyebrow"><span />ORGANIZATION INVITATION</div><h1>{success ? 'Invitation accepted' : invitation ? `Join ${invitation.organization}` : 'Review your invitation'}</h1>
      {invitation && <p>You’ve been invited as <b>{invitation.role.replaceAll('_', ' ')}</b> at <b>{invitation.email}</b>.</p>}
      {!invitation && !error && <div className="auth-feedback" role="status"><LoaderCircle className="spin" />Checking invitation…</div>}
      {error && <div className="auth-error" role="alert">{error}</div>}
      {invitation && !user && <><label>Full name<input name="name" autoComplete="name" required minLength={2} maxLength={120} placeholder="Your name" /></label><label>Password<input name="password" autoComplete="new-password" type="password" required minLength={12} placeholder="At least 12 characters, with letters and numbers" /></label><label>Confirm password<input name="password_confirmation" autoComplete="new-password" type="password" required minLength={12} placeholder="Enter the password again" /></label></>}
      {invitation && user && accountMatches && <p>This invitation will be accepted using your signed-in account.</p>}
      {invitation && user && !accountMatches && <p>This link was sent to {invitation.email}. <Link to="/login">Switch accounts</Link>, then reopen the invitation link.</p>}
      {invitation && accountMatches && <button className="button button-primary" type="submit" disabled={busy || loading}>{busy ? <><LoaderCircle className="spin" />Working…</> : <>Accept invitation <ArrowUpRight size={16} /></>}</button>}
      {!user && invitation && <p className="auth-links">Already have an account? <Link to="/login">Sign in as {invitation.email}</Link>, then reopen this invitation link.</p>}
      <small className="auth-demo-note"><ShieldCheck size={13} />The invitation token expires after seven days and can be used once.</small>
      <p className="auth-back"><Link to="/">← Back to edTechLive</Link></p>
    </form></div></section>
}
