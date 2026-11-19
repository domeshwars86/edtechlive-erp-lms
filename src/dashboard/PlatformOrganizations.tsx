import { Fragment, useEffect, useState, type FormEvent } from 'react'
import { AlertCircle, Building2, History, LoaderCircle, RefreshCw, Search } from 'lucide-react'
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
type AuditRow = { id: number; organization_id: number | null; organization_name: string; action: string; reason: string; before: { status?: string } | null; after: { status?: string } | null; actor: { name: string; email: string } | null; created_at: string | null }
type AuditPage = { data: AuditRow[]; meta: { current_page: number; last_page: number; total: number } }

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
  const [pendingChange, setPendingChange] = useState<{ id: number; status: 'active' | 'suspended'; name: string } | null>(null)
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)
  const [auditRows, setAuditRows] = useState<AuditRow[]>([])
  const [auditError, setAuditError] = useState('')

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

  useEffect(() => {
    let cancelled = false
    setAuditError('')
    api.get<AuditPage>('/api/v1/platform/audit-events?per_page=10')
      .then(result => { if (!cancelled) setAuditRows(result.data) })
      .catch(reason => { if (!cancelled) setAuditError(reason instanceof Error ? reason.message : 'Could not load platform history.') })
    return () => { cancelled = true }
  }, [refresh])

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setPage(1)
    setSearch(draftSearch.trim())
  }

  async function submitStatusChange(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!pendingChange || saving) return
    setSaving(true)
    setError('')
    try {
      await api.patch(`/api/v1/platform/organizations/${pendingChange.id}`, { status: pendingChange.status, reason })
      setPendingChange(null)
      setReason('')
      setRefresh(value => value + 1)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not update organization status.')
    } finally {
      setSaving(false)
    }
  }

  return <>
  <section className="dash-panel dash-masters-panel platform-org-directory">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">PLATFORM OPERATIONS</span><h2>Organizations</h2><p className="dash-record-subtitle">Review tenant status and record a reason for each suspension or reactivation.</p></div><button type="button" className="dash-icon-button" onClick={() => setRefresh((value) => value + 1)} aria-label="Refresh organizations"><RefreshCw size={16} /></button></div>
    <form className="platform-directory-filters" onSubmit={submitSearch}><label><Search size={15} /><input aria-label="Search organizations" value={draftSearch} onChange={(event) => setDraftSearch(event.target.value)} placeholder="Search organization name or slug" /></label><select aria-label="Filter organization status" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option></select><button className="master-action" type="submit">Search</button><span>{total} organizations</span></form>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {loading && rows.length === 0 ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading organizations…</p> : rows.length ? <div className="dash-table-scroll"><table><thead><tr><th>ORGANIZATION</th><th>TYPE</th><th>STATUS</th><th>MEMBERS</th><th>STUDENTS</th><th>TEACHERS</th><th>CREATED</th><th>ACTION</th></tr></thead><tbody>{rows.map((organization) => <Fragment key={organization.id}>
      <tr><td><b>{organization.name}</b><small className="platform-org-slug">{organization.slug}</small></td><td>{organization.type.replaceAll('_', ' ')}</td><td><span className={`table-status ${organization.status === 'active' ? 'status-live' : ''}`}><i />{organization.status}</span></td><td>{organization.active_members}</td><td>{organization.active_students}</td><td>{organization.active_teachers}</td><td>{organization.created_at ? new Date(organization.created_at).toLocaleDateString() : '—'}</td><td><button type="button" className="master-action" onClick={() => { setPendingChange({ id: organization.id, name: organization.name, status: organization.status === 'active' ? 'suspended' : 'active' }); setReason(''); setError('') }}>{organization.status === 'active' ? 'Suspend' : 'Reactivate'}</button></td></tr>
      {pendingChange?.id === organization.id && <tr key={`${organization.id}-status-form`}><td colSpan={8}><form className="platform-directory-filters" onSubmit={submitStatusChange}><label htmlFor={`status-reason-${organization.id}`}>{pendingChange.status === 'suspended' ? `Reason to suspend ${organization.name}` : `Reason to reactivate ${organization.name}`}</label><input id={`status-reason-${organization.id}`} required minLength={8} maxLength={500} value={reason} onChange={event => setReason(event.target.value)} placeholder="Add a clear operational reason" /><button className="master-action" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Confirm'}</button><button className="master-action" type="button" disabled={saving} onClick={() => { setPendingChange(null); setReason('') }}>Cancel</button></form></td></tr>}
    </Fragment>)}</tbody></table></div> : !error && <p className="master-empty"><Building2 size={18} />No organizations match these filters.</p>}
    <div className="platform-directory-pagination"><span>Page {page} of {lastPage}</span><button className="master-action" type="button" disabled={loading || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</button><button className="master-action" type="button" disabled={loading || page >= lastPage} onClick={() => setPage((value) => value + 1)}>Next</button></div>
  </section>
  <section className="dash-panel dash-masters-panel platform-org-directory">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">PLATFORM OPERATIONS</span><h2><History size={17} />Recent organization actions</h2><p className="dash-record-subtitle">Latest status changes include who made the change and the required operational reason.</p></div></div>
    {auditError && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{auditError}</div>}
    {auditRows.length ? <div className="dash-table-scroll"><table><thead><tr><th>WHEN</th><th>ORGANIZATION</th><th>CHANGE</th><th>OPERATOR</th><th>REASON</th></tr></thead><tbody>{auditRows.map(event => <tr key={event.id}><td>{event.created_at ? new Date(event.created_at).toLocaleString() : '—'}</td><td><b>{event.organization_name}</b></td><td>{event.before?.status ?? '—'} → {event.after?.status ?? event.action}</td><td>{event.actor ? <><b>{event.actor.name}</b><small className="platform-org-slug">{event.actor.email}</small></> : 'Deleted account'}</td><td>{event.reason}</td></tr>)}</tbody></table></div> : !auditError && <p className="master-empty"><History size={18} />No platform status changes have been recorded.</p>}
  </section>
  </>
}
