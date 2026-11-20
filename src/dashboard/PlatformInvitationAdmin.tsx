import { useEffect, useState, type FormEvent } from 'react'
import { AlertCircle, LoaderCircle, MailCheck, RefreshCw, Search, ShieldX } from 'lucide-react'
import { api } from '../lib/api'

type InvitationStatus = 'pending' | 'expired' | 'accepted' | 'revoked'
type Invitation = {
  id: number
  organization_id: number
  organization: string
  email: string
  role: string
  status: InvitationStatus
  expires_at: string
  created_at: string | null
  revocation_reason: string | null
}
type InvitationPage = { data: Invitation[]; meta: { current_page: number; last_page: number; total: number } }

export default function PlatformInvitationAdmin() {
  const [rows, setRows] = useState<Invitation[]>([])
  const [status, setStatus] = useState<InvitationStatus>('pending')
  const [draftSearch, setDraftSearch] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [refresh, setRefresh] = useState(0)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [revoking, setRevoking] = useState<{ id: number; reason: string } | null>(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    const params = new URLSearchParams({ status, per_page: '20', page: String(page) })
    if (search) params.set('q', search)
    api.get<InvitationPage>(`/api/v1/platform/invitations?${params.toString()}`)
      .then(result => {
        if (cancelled) return
        setRows(result.data)
        setLastPage(result.meta.last_page)
        setTotal(result.meta.total)
      })
      .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load staff invitations.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [page, refresh, search, status])

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setPage(1)
    setSearch(draftSearch.trim())
  }

  async function revoke(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!revoking || revoking.reason.trim().length < 8 || saving) return
    setSaving(true)
    setError('')
    setMessage('')
    try {
      await api.delete<{ message: string }>(`/api/v1/platform/invitations/${revoking.id}`, { reason: revoking.reason.trim() })
      setMessage('Invitation revoked. The saved invitation record and audit reason remain available.')
      setRevoking(null)
      setRefresh(value => value + 1)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not revoke this invitation.')
    } finally {
      setSaving(false)
    }
  }

  return <section className="dash-panel platform-invitation-admin">
    <div className="dash-panel-heading">
      <div><span className="dash-panel-kicker">INVITATION OVERSIGHT</span><h2>Staff invitations</h2><p className="dash-record-subtitle">Review invitations across organizations. Revoke an unaccepted invitation with an audited reason.</p></div>
      <button type="button" className="dash-icon-button" onClick={() => setRefresh(value => value + 1)} aria-label="Refresh staff invitations"><RefreshCw size={16} /></button>
    </div>
    <form className="platform-invitation-filters" onSubmit={submitSearch}>
      <label><Search size={15} /><input aria-label="Search staff invitations" value={draftSearch} onChange={event => setDraftSearch(event.target.value)} placeholder="Search email or organization" /></label>
      <select aria-label="Filter invitation status" value={status} onChange={event => { setStatus(event.target.value as InvitationStatus); setPage(1) }}>
        <option value="pending">Pending</option><option value="expired">Expired</option><option value="accepted">Accepted</option><option value="revoked">Revoked</option>
      </select>
      <button className="master-action" type="submit">Search</button><span>{total} invitations</span>
    </form>
    {error ? <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div> : null}
    {message ? <div className="master-feedback master-success" role="status">{message}</div> : null}
    {loading && rows.length === 0 ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading invitations…</p> : rows.length ? <div className="dash-table-scroll platform-invitation-table"><table><thead><tr><th>ORGANIZATION</th><th>EMAIL</th><th>ROLE</th><th>STATUS</th><th>EXPIRES</th><th>ACTION</th></tr></thead><tbody>{rows.map(invitation => <tr key={invitation.id}>
      <td><b>{invitation.organization}</b></td><td>{invitation.email}</td><td>{invitation.role.replaceAll('_', ' ')}</td><td><span className={`table-status ${invitation.status === 'pending' ? 'status-live' : ''}`}><i />{invitation.status}</span></td><td>{new Date(invitation.expires_at).toLocaleDateString()}</td><td>{invitation.status === 'pending' || invitation.status === 'expired' ? <button type="button" className="master-action" onClick={() => { setMessage(''); setRevoking({ id: invitation.id, reason: '' }) }}>Revoke</button> : <span className="platform-user-protected">No action</span>}</td>
    </tr>)}</tbody></table></div> : !error ? <p className="master-empty"><MailCheck size={18} />No {status} staff invitations match this view.</p> : null}
    {revoking ? <form className="platform-invitation-revoke" onSubmit={event => void revoke(event)}>
      <div><b>Revoke invitation</b><p>The recipient will no longer be able to accept this invitation. The invitation record is retained.</p></div>
      <label htmlFor="platform-invitation-reason">Required audit reason</label>
      <textarea id="platform-invitation-reason" required minLength={8} maxLength={500} value={revoking.reason} onChange={event => setRevoking(current => current ? { ...current, reason: event.target.value } : current)} placeholder="At least 8 characters" />
      <div><button type="button" className="master-action" disabled={saving} onClick={() => setRevoking(null)}>Cancel</button><button type="submit" className="master-submit" disabled={saving || revoking.reason.trim().length < 8}>{saving ? 'Revoking…' : <><ShieldX size={14} /> Confirm revoke</>}</button></div>
    </form> : null}
    <div className="platform-directory-pagination"><span>Page {page} of {lastPage}</span><button className="master-action" type="button" disabled={loading || page <= 1} onClick={() => setPage(value => value - 1)}>Previous</button><button className="master-action" type="button" disabled={loading || page >= lastPage} onClick={() => setPage(value => value + 1)}>Next</button></div>
  </section>
}
