import { useEffect, useState } from 'react'
import { AlertCircle, BarChart3, Download, LoaderCircle, RefreshCw } from 'lucide-react'
import { api } from '../lib/api'
import { downloadCsv } from '../lib/csv'
import './PlatformReports.css'

type OrganizationRow = { id: number; name: string; type: string; status: string; created_at: string }
type Snapshot = {
  organizations: { total: number; active: number; suspended: number }
  users: { total: number; verified: number }
  educators: number
  open_enquiries: number
  recent_organizations: OrganizationRow[]
}

export default function PlatformReports() {
  const [snapshot, setSnapshot] = useState<Snapshot | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [refresh, setRefresh] = useState(0)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    api.get<{ data: Snapshot }>('/api/v1/platform/overview')
      .then(result => { if (!cancelled) setSnapshot(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load the platform report.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [refresh])

  function exportSnapshot() {
    if (!snapshot) return
    const rows: (string | number)[][] = [
      ['Current snapshot', 'Organizations', snapshot.organizations.total, 'Current total', ''],
      ['Current snapshot', 'Active organizations', snapshot.organizations.active, 'Current status count', ''],
      ['Current snapshot', 'Suspended organizations', snapshot.organizations.suspended, 'Current status count', ''],
      ['Current snapshot', 'User accounts', snapshot.users.total, 'Current total', ''],
      ['Current snapshot', 'Verified accounts', snapshot.users.verified, 'Email verified', ''],
      ['Current snapshot', 'Active educators', snapshot.educators, 'Distinct active teacher/independent educator accounts', ''],
      ['Current snapshot', 'Open enquiries', snapshot.open_enquiries, 'New or in progress', ''],
      ...snapshot.recent_organizations.map(item => ['Recent organization', item.name, item.type, item.status, new Date(item.created_at).toISOString()]),
    ]
    downloadCsv(`edtechlive-platform-snapshot-${new Date().toISOString().slice(0, 10)}.csv`, ['Section', 'Metric / organization', 'Value / type', 'Notes / status', 'Created'], rows)
  }

  return <div className="platform-reports">
    <section className="dash-panel dash-masters-panel">
      <div className="dash-panel-heading"><div><span className="dash-panel-kicker">PLATFORM REPORTS</span><h2><BarChart3 size={17} />Current platform snapshot</h2><p className="dash-record-subtitle">Live aggregate counts from the platform database. This report is a current snapshot; historical trends and event analytics are not available.</p></div><div className="platform-report-actions"><button type="button" className="master-action" onClick={exportSnapshot} disabled={!snapshot || loading}><Download size={14} />Export CSV</button><button type="button" className="dash-icon-button" onClick={() => setRefresh(value => value + 1)} disabled={loading} aria-label="Refresh platform report"><RefreshCw size={16} /></button></div></div>
      {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
      {loading && !snapshot ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading platform snapshot…</p> : snapshot && <>
        <div className="platform-stat-grid" aria-label="Current aggregate metrics">
          <article><small>ORGANIZATIONS</small><b>{snapshot.organizations.total}</b><em>{snapshot.organizations.active} active · {snapshot.organizations.suspended} suspended</em></article>
          <article><small>USER ACCOUNTS</small><b>{snapshot.users.total}</b><em>{snapshot.users.verified} email verified</em></article>
          <article><small>ACTIVE EDUCATORS</small><b>{snapshot.educators}</b><em>Distinct active memberships</em></article>
          <article><small>OPEN ENQUIRIES</small><b>{snapshot.open_enquiries}</b><em>New or in progress</em></article>
        </div>
        <p className="platform-report-footnote">Snapshot refreshed {new Date().toLocaleString()}. The export contains aggregate totals and the eight most recently created organizations; it excludes student-level records.</p>
        <div className="dash-table-scroll"><table><thead><tr><th>RECENT ORGANIZATION</th><th>TYPE</th><th>STATUS</th><th>CREATED</th></tr></thead><tbody>{snapshot.recent_organizations.map(item => <tr key={item.id}><td><b>{item.name}</b></td><td>{item.type.replaceAll('_', ' ')}</td><td><span className={`table-status ${item.status === 'active' ? 'status-live' : ''}`}><i />{item.status}</span></td><td>{new Date(item.created_at).toLocaleDateString()}</td></tr>)}</tbody></table></div>
      </>}
    </section>
  </div>
}
