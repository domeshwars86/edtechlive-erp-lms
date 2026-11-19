import { useEffect, useState, type FormEvent } from 'react'
import { ArrowUpRight, Check, LoaderCircle, Save, ShieldCheck } from 'lucide-react'
import { ApiError, api, type ApiUser } from '../lib/api'

type ProfileResponse = { data: ApiUser; email_changed: boolean; verification_url?: string }

export default function AccountProfile({ user, onUpdated }: { user: ApiUser | null; onUpdated: (user: ApiUser) => void }) {
  const [name, setName] = useState(user?.name ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [currentPassword, setCurrentPassword] = useState('')
  const [verificationUrl, setVerificationUrl] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    setName(user?.name ?? '')
    setEmail(user?.email ?? '')
  }, [user?.id, user?.name, user?.email])

  async function saveProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setError('')
    setMessage('')
    setVerificationUrl('')
    try {
      const response = await api.patch<ProfileResponse>('/api/v1/me/profile', {
        name: name.trim(), email: email.trim(), current_password: currentPassword,
      })
      onUpdated(response.data)
      setName(response.data.name)
      setEmail(response.data.email)
      setCurrentPassword('')
      setVerificationUrl(response.verification_url ?? '')
      setMessage(response.email_changed
        ? response.verification_url
          ? 'Your email was updated. Verify the new address with the link below before signing in again.'
          : 'Your email was updated. Check the new address for a verification link before signing in again.'
        : 'Your account details were saved.')
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : reason instanceof Error ? reason.message : 'Could not save your account details.')
    } finally {
      setSaving(false)
    }
  }

  if (!user) return <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">ACCOUNT SECURITY</span><h2>Account profile</h2><p className="dash-record-subtitle">Sign in to manage your account details.</p></div></div></section>

  return <section className="dash-panel dash-masters-panel account-profile">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">ACCOUNT SECURITY</span><h2>Account profile</h2><p className="dash-record-subtitle">Update the name and email used across your edTechLive account.</p></div><span className={`account-verification-state ${user.email_verified_at ? 'is-verified' : ''}`}><ShieldCheck size={14} />{user.email_verified_at ? 'Email verified' : 'Email verification required'}</span></div>
    {message && <div className="account-profile-message" role="status"><Check size={16} />{message}</div>}
    {error && <div className="auth-error account-profile-error" role="alert">{error}</div>}
    <form className="organization-settings-form account-profile-form" onSubmit={event => void saveProfile(event)}>
      <label>Full name<input autoComplete="name" value={name} onChange={event => setName(event.target.value)} minLength={2} maxLength={120} required /></label>
      <label>Email address<input autoComplete="email" type="email" value={email} onChange={event => setEmail(event.target.value)} maxLength={254} required /></label>
      <label className="account-current-password">Current password<input autoComplete="current-password" type="password" value={currentPassword} onChange={event => setCurrentPassword(event.target.value)} required /></label>
      <p>Enter your current password to confirm changes. Changing your email requires verifying the new address. Password changes are available through <a href="/forgot-password">password recovery</a>.</p>
      <button className="master-submit" type="submit" disabled={saving || !name.trim() || !email.trim() || !currentPassword}>{saving ? <><LoaderCircle className="master-spin" size={16} />Saving…</> : <><Save size={15} />Save account details</>}</button>
    </form>
    {verificationUrl && <div className="local-verification-link account-verification-link"><p>Verify the new email address to keep using your account.</p><a href={verificationUrl} target="_blank" rel="noreferrer">Verify email address <ArrowUpRight size={16} /></a><small>The development link expires in 60 minutes. Return here and reload after it confirms.</small></div>}
  </section>
}
