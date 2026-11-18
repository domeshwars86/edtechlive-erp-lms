import { useEffect, useState, type FormEvent } from 'react'
import { AlertCircle, Building2, LoaderCircle, RefreshCw, Search } from 'lucide-react'
import { api } from '../lib/api'

type OrganizationRow = {
  id: number
  name: string
  slug: string
  type: string
  status: string
  timezone: string
  created_at: string | null
  active_members: number
  active_students: number
  active_teachers: number
}
type OrganizationPage = { data: OrganizationRow[]; meta: { current_page: number; last_page: number; total: number } }

export default function PlatformOrganizations() {
  const [rows, setRows] = useState<OrganizationRow[]>([])
  const [draftSearch, setDraftSearch] = useState('')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
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
    if (status) params.set('status', status)
    api.get<OrganizationPage>(`/api/v1/platform/organizations?${params.toString()}`)
      .then((result) => {
        if (cancelled) return
        setRows(result.data)
        setLastPage(result.meta.last_page)
        setTotal(result.meta.total)
      })
      .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load organizations.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [page, refresh, search, status])

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setPage(1)
    setSearch(draftSearch.trim())
  }

  return <section className="dash-panel dash-masters-panel platform-org-directory">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">PLATFORM OPERATIONS</span><h2>Organizations</h2><p className="dash-record-subtitle">Read-only directory with active roster totals. Status changes are not enabled here.</p></div><button type="button" className="dash-icon-button" onClick={() => setRefresh((value) => value + 1)} aria-label="Refresh organizations"><RefreshCw size={16} /></button></div>
    <form className="platform-directory-filters" onSubmit={submitSearch}><label><Search size={15} /><input aria-label="Search organizations" value={draftSearch} onChange={(event) => setDraftSearch(event.target.value)} placeholder="Search organization name or slug" /></label><select aria-label="Filter organization status" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option></select><button className="master-action" type="submit">Search</button><span>{total} organizations</span></form>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {loading && rows.length === 0 ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading organizations…</p> : rows.length ? <div className="dash-table-scroll"><table><thead><tr><th>ORGANIZATION</th><th>TYPE</th><th>STATUS</th><th>MEMBERS</th><th>STUDENTS</th><th>TEACHERS</th><th>CREATED</th></tr></thead><tbody>{rows.map((organization) => <tr key={organization.id}><td><b>{organization.name}</b><small className="platform-org-slug">{organization.slug}</small></td><td>{organization.type.replaceAll('_', ' ')}</td><td><span className={`table-status ${organization.status === 'active' ? 'status-live' : ''}`}><i />{organization.status}</span></td><td>{organization.active_members}</td><td>{organization.active_students}</td><td>{organization.active_teachers}</td><td>{organization.created_at ? new Date(organization.created_at).toLocaleDateString() : '—'}</td></tr>)}</tbody></table></div> : !error && <p className="master-empty"><Building2 size={18} />No organizations match these filters.</p>}
    <div className="platform-directory-pagination"><span>Page {page} of {lastPage}</span><button className="master-action" type="button" disabled={loading || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</button><button className="master-action" type="button" disabled={loading || page >= lastPage} onClick={() => setPage((value) => value + 1)}>Next</button></div>
  </section>
}
