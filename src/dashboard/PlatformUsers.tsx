import { useEffect, useState, type FormEvent } from 'react'
import { AlertCircle, LoaderCircle, RefreshCw, Search, Users } from 'lucide-react'
import { api } from '../lib/api'
import PlatformInvitationForm from './PlatformInvitationForm'
import PlatformInvitationAdmin from './PlatformInvitationAdmin'
import './platform-users.css'

type UserRow = {
  id: number
  name: string
  email: string
  email_verified_at: string | null
  created_at: string | null
  active_organizations: number
  platform_status: 'active' | 'suspended'
  status_change_allowed: boolean
}
type UserPage = { data: UserRow[]; meta: { current_page: number; last_page: number; total: number } }

export default function PlatformUsers({ initialRole = '' }: { initialRole?: string }) {
  const [rows, setRows] = useState<UserRow[]>([])
  const [draftSearch, setDraftSearch] = useState('')
  const [search, setSearch] = useState('')
  const [verified, setVerified] = useState('')
  const [role, setRole] = useState(initialRole)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [pendingChange, setPendingChange] = useState<{ user: UserRow; status: 'active' | 'suspended'; reason: string } | null>(null)
  const [saving, setSaving] = useState(false)
  const [refresh, setRefresh] = useState(0)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    const params = new URLSearchParams({ per_page: '20', page: String(page) })
    if (search) params.set('q', search)
    if (verified) params.set('email_verified', verified)
    if (role) params.set('role', role)
    if (statusFilter) params.set('status', statusFilter)
    api.get<UserPage>(`/api/v1/platform/users?${params.toString()}`)
      .then(result => {
        if (cancelled) return
        setRows(result.data)
        setLastPage(result.meta.last_page)
        setTotal(result.meta.total)
      })
      .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load platform users.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [page, refresh, role, search, statusFilter, verified])

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setPage(1)
    setSearch(draftSearch.trim())
  }

  async function saveStatus(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!pendingChange || pendingChange.reason.trim().length < 8) return
    setSaving(true)
    setError('')
    setMessage('')
    try {
      const result = await api.patch<{ data: { id: number; platform_status: 'active' | 'suspended' } }>(`/api/v1/platform/users/${pendingChange.user.id}`, {
        status: pendingChange.status,
        reason: pendingChange.reason.trim(),
      })
      setRows(current => current.map(user => user.id === result.data.id ? { ...user, platform_status: result.data.platform_status } : user))
      setMessage(`${pendingChange.user.name}'s account is now ${result.data.platform_status}.`)
      setPendingChange(null)
      setRefresh(value => value + 1)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not update this account.')
    } finally {
      setSaving(false)
    }
  }

  return <section className="dash-panel dash-masters-panel platform-org-directory">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">PLATFORM OPERATIONS</span><h2>Users</h2><p className="dash-record-subtitle">Review account access and record a reason when suspending or restoring an account. Passwords and private tokens are never returned.</p></div><button type="button" className="dash-icon-button" onClick={() => setRefresh(value => value + 1)} aria-label="Refresh users"><RefreshCw size={16} /></button></div>
    <PlatformInvitationForm onCreated={() => setRefresh(value => value + 1)} />
    <PlatformInvitationAdmin />
    <form className="platform-directory-filters" onSubmit={submitSearch}>
      <label><Search size={15} /><input aria-label="Search users" value={draftSearch} onChange={event => setDraftSearch(event.target.value)} placeholder="Search name or email" /></label>
      <select aria-label="Filter email verification" value={verified} onChange={event => { setVerified(event.target.value); setPage(1) }}><option value="">All email states</option><option value="true">Verified</option><option value="false">Not verified</option></select>
      <select aria-label="Filter organization role" value={role} onChange={event => { setRole(event.target.value); setPage(1) }}><option value="">All roles</option><option value="admin">Admin</option><option value="management">Management</option><option value="teacher">Teacher</option><option value="student">Student</option><option value="parent">Parent</option><option value="independent_educator">Independent educator</option></select>
      <select aria-label="Filter account status" value={statusFilter} onChange={event => { setStatusFilter(event.target.value); setPage(1) }}><option value="">All account statuses</option><option value="active">Active</option><option value="suspended">Suspended</option></select>
      <button className="master-action" type="submit">Search</button><span>{total} accounts</span>
    </form>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status">{message}</div>}
    {loading && rows.length === 0 ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading users…</p> : rows.length ? <div className="dash-table-scroll"><table><thead><tr><th>ACCOUNT</th><th>EMAIL</th><th>VERIFICATION</th><th>ACCOUNT STATUS</th><th>ACTIVE ORGANIZATIONS</th><th>CREATED</th><th>ACTION</th></tr></thead><tbody>{rows.map(user => <tr key={user.id}><td><b>{user.name}</b></td><td>{user.email}</td><td><span className={`table-status ${user.email_verified_at ? 'status-live' : ''}`}><i />{user.email_verified_at ? 'Verified' : 'Pending'}</span></td><td><span className={`table-status ${user.platform_status === 'active' ? 'status-live' : ''}`}><i />{user.platform_status === 'active' ? 'Active' : 'Suspended'}</span></td><td>{user.active_organizations}</td><td>{user.created_at ? new Date(user.created_at).toLocaleDateString() : '—'}</td><td>{user.status_change_allowed ? <button className="master-action" type="button" onClick={() => setPendingChange({ user, status: user.platform_status === 'active' ? 'suspended' : 'active', reason: '' })}>{user.platform_status === 'active' ? 'Suspend' : 'Restore'}</button> : <span className="platform-user-protected">Operator protected</span>}</td></tr>)}</tbody></table></div> : !error && <p className="master-empty"><Users size={18} />No accounts match these filters.</p>}
    {pendingChange ? <form className="platform-user-status-form" onSubmit={event => void saveStatus(event)}><div><b>{pendingChange.status === 'suspended' ? 'Suspend account' : 'Restore account'} · {pendingChange.user.name}</b><p>{pendingChange.status === 'suspended' ? 'The user will lose access to authenticated platform endpoints immediately.' : 'The user will regain account access.'} Allowlisted platform operator accounts are protected from this action.</p></div><label htmlFor="platform-user-reason">Required reason</label><textarea id="platform-user-reason" required minLength={8} maxLength={500} value={pendingChange.reason} onChange={event => setPendingChange(current => current ? { ...current, reason: event.target.value } : current)} placeholder="At least 8 characters" /><div><button type="button" className="master-action" disabled={saving} onClick={() => setPendingChange(null)}>Cancel</button><button type="submit" className="master-submit" disabled={saving || pendingChange.reason.trim().length < 8}>{saving ? 'Saving…' : 'Confirm status change'}</button></div></form> : null}
    <div className="platform-directory-pagination"><span>Page {page} of {lastPage}</span><button className="master-action" type="button" disabled={loading || page <= 1} onClick={() => setPage(value => value - 1)}>Previous</button><button className="master-action" type="button" disabled={loading || page >= lastPage} onClick={() => setPage(value => value + 1)}>Next</button></div>
  </section>
}
