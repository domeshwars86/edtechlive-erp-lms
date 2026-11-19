import { useCallback, useEffect, useState } from 'react'
import { AlertCircle, History, LoaderCircle, RefreshCw } from 'lucide-react'
import { api } from '../lib/api'

type AuditEvent = {
  id: number
  organization_name: string
  action: string
  reason: string
  before: { status?: string } | null
  after: { status?: string } | null
  actor: { name: string; email: string } | null
  created_at: string | null
}
type AuditPage = { data: AuditEvent[]; meta: { current_page: number; last_page: number; total: number } }

export default function PlatformAuditLogs() {
  const [events, setEvents] = useState<AuditEvent[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState('')

  const load = useCallback(async (requestedPage: number) => {
    setError('')
    setLoading(true)
    try {
      const result = await api.get<AuditPage>(`/api/v1/platform/audit-events?per_page=25&page=${requestedPage}`)
      setEvents(result.data)
      setPage(result.meta.current_page)
      setLastPage(result.meta.last_page)
      setTotal(result.meta.total)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not load organization status history.')
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }, [])

  useEffect(() => { void load(page) }, [load, page])

  return <section className="dash-panel dash-masters-panel platform-audit-workspace" aria-labelledby="platform-audit-title">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">PLATFORM OPERATIONS</span><h2 id="platform-audit-title"><History size={17} />Organization status audit</h2><p className="dash-record-subtitle">This log currently covers organization suspensions and reactivations, including the operator and recorded reason.</p></div><button className="dash-icon-button" type="button" onClick={() => { setRefreshing(true); void load(page) }} disabled={refreshing || loading} aria-label="Refresh audit history"><RefreshCw size={16} /></button></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {loading && events.length === 0 ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading organization status history…</p> : events.length ? <>
      <div className="dash-table-scroll"><table><thead><tr><th>WHEN</th><th>ORGANIZATION</th><th>CHANGE</th><th>OPERATOR</th><th>REASON</th></tr></thead><tbody>{events.map(event => <tr key={event.id}><td>{event.created_at ? new Date(event.created_at).toLocaleString() : '—'}</td><td><b>{event.organization_name}</b></td><td>{event.before?.status ?? '—'} → {event.after?.status ?? event.action}</td><td>{event.actor ? <><b>{event.actor.name}</b><small className="platform-org-slug">{event.actor.email}</small></> : 'Deleted account'}</td><td>{event.reason}</td></tr>)}</tbody></table></div>
      <div className="platform-directory-pagination"><span>{total} status change{total === 1 ? '' : 's'} · Page {page} of {lastPage}</span><button className="master-action" type="button" disabled={loading || page <= 1} onClick={() => setPage(current => current - 1)}>Previous</button><button className="master-action" type="button" disabled={loading || page >= lastPage} onClick={() => setPage(current => current + 1)}>Next</button></div>
    </> : !error && <p className="master-empty"><History size={18} />No organization status changes have been recorded.</p>}
  </section>
}
