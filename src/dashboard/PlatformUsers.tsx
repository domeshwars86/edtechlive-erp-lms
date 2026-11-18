import { useEffect, useState, type FormEvent } from 'react'
import { AlertCircle, LoaderCircle, RefreshCw, Search, Users } from 'lucide-react'
import { api } from '../lib/api'

type UserRow = {
  id: number
  name: string
  email: string
  email_verified_at: string | null
  created_at: string | null
  active_organizations: number
}
type UserPage = { data: UserRow[]; meta: { current_page: number; last_page: number; total: number } }

export default function PlatformUsers() {
  const [rows, setRows] = useState<UserRow[]>([])
  const [draftSearch, setDraftSearch] = useState('')
  const [search, setSearch] = useState('')
  const [verified, setVerified] = useState('')
  const [role, setRole] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [refresh, setRefresh] = useState(0)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    const params = new URLSearchParams({ per_page: '20', page: String(page) })
    if (search) params.set('q', search)
    if (verified) params.set('email_verified', verified)
    if (role) params.set('role', role)
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
  }, [page, refresh, role, search, verified])

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setPage(1)
    setSearch(draftSearch.trim())
  }

  return <section className="dash-panel dash-masters-panel platform-org-directory">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">PLATFORM OPERATIONS</span><h2>Users</h2><p className="dash-record-subtitle">Read-only account directory. Passwords and private account tokens are never returned.</p></div><button type="button" className="dash-icon-button" onClick={() => setRefresh(value => value + 1)} aria-label="Refresh users"><RefreshCw size={16} /></button></div>
    <form className="platform-directory-filters" onSubmit={submitSearch}>
      <label><Search size={15} /><input aria-label="Search users" value={draftSearch} onChange={event => setDraftSearch(event.target.value)} placeholder="Search name or email" /></label>
      <select aria-label="Filter email verification" value={verified} onChange={event => { setVerified(event.target.value); setPage(1) }}><option value="">All email states</option><option value="true">Verified</option><option value="false">Not verified</option></select>
      <select aria-label="Filter organization role" value={role} onChange={event => { setRole(event.target.value); setPage(1) }}><option value="">All roles</option><option value="admin">Admin</option><option value="management">Management</option><option value="teacher">Teacher</option><option value="student">Student</option><option value="parent">Parent</option><option value="independent_educator">Independent educator</option></select>
      <button className="master-action" type="submit">Search</button><span>{total} accounts</span>
    </form>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {loading && rows.length === 0 ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading users…</p> : rows.length ? <div className="dash-table-scroll"><table><thead><tr><th>ACCOUNT</th><th>EMAIL</th><th>VERIFICATION</th><th>ACTIVE ORGANIZATIONS</th><th>CREATED</th></tr></thead><tbody>{rows.map(user => <tr key={user.id}><td><b>{user.name}</b></td><td>{user.email}</td><td><span className={`table-status ${user.email_verified_at ? 'status-live' : ''}`}><i />{user.email_verified_at ? 'Verified' : 'Pending'}</span></td><td>{user.active_organizations}</td><td>{user.created_at ? new Date(user.created_at).toLocaleDateString() : '—'}</td></tr>)}</tbody></table></div> : !error && <p className="master-empty"><Users size={18} />No accounts match these filters.</p>}
    <div className="platform-directory-pagination"><span>Page {page} of {lastPage}</span><button className="master-action" type="button" disabled={loading || page <= 1} onClick={() => setPage(value => value - 1)}>Previous</button><button className="master-action" type="button" disabled={loading || page >= lastPage} onClick={() => setPage(value => value + 1)}>Next</button></div>
  </section>
}
