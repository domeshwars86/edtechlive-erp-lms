import { useEffect, useState, type FormEvent } from 'react'
import { api } from '../lib/api'

type OrganizationOption = { id: number; name: string; status: string }
type OrganizationPage = { data: OrganizationOption[] }
type InvitationResponse = { data: { id: number; organization: string; email: string; role: string; expires_at: string }; message: string; invitation_url?: string }

export default function PlatformInvitationForm({ onCreated }: { onCreated: () => void }) {
  const [open, setOpen] = useState(false)
  const [organizationQuery, setOrganizationQuery] = useState('')
  const [organizations, setOrganizations] = useState<OrganizationOption[]>([])
  const [organizationId, setOrganizationId] = useState('')
  const [email, setEmail] = useState('')
  const [role, setRole] = useState('teacher')
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [invitationUrl, setInvitationUrl] = useState('')

  useEffect(() => {
    if (!open) return
    let active = true
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({ status: 'active', per_page: '100' })
      if (organizationQuery.trim()) params.set('q', organizationQuery.trim())
      api.get<OrganizationPage>(`/api/v1/platform/organizations?${params.toString()}`)
        .then(result => {
          if (!active) return
          setOrganizations(result.data.filter(item => item.status === 'active'))
          setOrganizationId(current => result.data.some(item => String(item.id) === current && item.status === 'active') ? current : '')
        })
        .catch(reason => { if (active) setError(reason instanceof Error ? reason.message : 'Could not load active organizations.') })
    }, 180)
    return () => { active = false; window.clearTimeout(timer) }
  }, [open, organizationQuery])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (saving || !organizationId || reason.trim().length < 8) return
    setSaving(true)
    setError('')
    setSuccess('')
    setInvitationUrl('')
    try {
      const response = await api.post<InvitationResponse>('/api/v1/platform/invitations', {
        organization_id: Number(organizationId),
        email: email.trim(),
        role,
        reason: reason.trim(),
      })
      setSuccess(`${response.data.email} was invited to ${response.data.organization} as ${response.data.role}.`)
      setInvitationUrl(response.invitation_url ?? '')
      setEmail('')
      setReason('')
      onCreated()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not send this invitation.')
    } finally {
      setSaving(false)
    }
  }

  return <div className="platform-invite-wrap">
    <button className="master-action" type="button" onClick={() => { setOpen(value => !value); setError('') }} aria-expanded={open} aria-controls="platform-invite-form">
      {open ? 'Close staff invitation' : 'Invite staff account'}
    </button>
    {open ? <form id="platform-invite-form" className="platform-invite-form" onSubmit={event => void submit(event)}>
      <div className="platform-invite-intro"><b>Invite a staff account</b><p>The recipient creates their account, verifies the invitation email, and then joins this organization. Role access starts after acceptance.</p></div>
      {error ? <p className="platform-invite-error" role="alert">{error}</p> : null}
      {success ? <div className="platform-invite-success" role="status"><p>{success}</p>{invitationUrl ? <a href={invitationUrl} target="_blank" rel="noreferrer">Open local invitation link</a> : null}</div> : null}
      <label htmlFor="platform-invite-organization-search">Organization</label>
      <input id="platform-invite-organization-search" value={organizationQuery} onChange={event => setOrganizationQuery(event.target.value)} placeholder="Search active organizations" autoComplete="off" />
      <select aria-label="Invitation organization" required value={organizationId} onChange={event => setOrganizationId(event.target.value)}>
        <option value="">Choose an active organization</option>
        {organizations.map(item => <option value={item.id} key={item.id}>{item.name}</option>)}
      </select>
      {!organizations.length ? <small className="platform-invite-help">Search by organization name if it is not listed.</small> : null}
      <label htmlFor="platform-invite-email">Email address</label>
      <input id="platform-invite-email" type="email" required maxLength={254} value={email} onChange={event => setEmail(event.target.value)} placeholder="name@example.com" autoComplete="email" />
      <label htmlFor="platform-invite-role">Organization role</label>
      <select id="platform-invite-role" value={role} onChange={event => setRole(event.target.value)}>
        <option value="admin">Admin</option><option value="management">Management</option><option value="teacher">Teacher</option><option value="independent_educator">Independent educator</option>
      </select>
      <label htmlFor="platform-invite-reason">Required audit reason</label>
      <textarea id="platform-invite-reason" required minLength={8} maxLength={500} value={reason} onChange={event => setReason(event.target.value)} placeholder="At least 8 characters" />
      <div className="platform-invite-actions"><button type="submit" className="master-submit" disabled={saving || !organizationId || reason.trim().length < 8}>{saving ? 'Sending invitation…' : 'Send invitation'}</button></div>
    </form> : null}
  </div>
}
