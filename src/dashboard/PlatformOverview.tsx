import { useEffect, useState } from 'react'
import { AlertCircle, Building2, GraduationCap, LoaderCircle, MailPlus, RefreshCw, Users } from 'lucide-react'
import { api } from '../lib/api'

type OrganizationRow = { id: number; name: string; type: string; status: string; created_at: string }
type Overview = {
  organizations: { total: number; active: number; suspended: number }
  users: { total: number; verified: number }
  educators: number
  open_enquiries: number
  recent_organizations: OrganizationRow[]
}

export default function PlatformOverview() {
  const [overview, setOverview] = useState<Overview | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [refresh, setRefresh] = useState(0)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    api.get<{ data: Overview }>('/api/v1/platform/overview')
      .then((result) => { if (!cancelled) setOverview(result.data) })
      .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load the platform overview.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [refresh])

  return <div className="platform-overview">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {loading && !overview ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading platform metrics…</p> : overview && <>
      <section className="platform-stat-grid" aria-label="Platform totals">
        <article><span className="platform-stat-icon platform-blue"><Building2 size={17} /></span><small>ORGANIZATIONS</small><b>{overview.organizations.total}</b><em>{overview.organizations.active} active · {overview.organizations.suspended} suspended</em></article>
        <article><span className="platform-stat-icon platform-mint"><Users size={17} /></span><small>USER ACCOUNTS</small><b>{overview.users.total}</b><em>{overview.users.verified} email verified</em></article>
        <article><span className="platform-stat-icon platform-lilac"><GraduationCap size={17} /></span><small>ACTIVE EDUCATORS</small><b>{overview.educators}</b><em>Distinct active memberships</em></article>
        <article><span className="platform-stat-icon platform-peach"><MailPlus size={17} /></span><small>OPEN ENQUIRIES</small><b>{overview.open_enquiries}</b><em>New or in progress</em></article>
      </section>
      <section className="dash-panel dash-masters-panel platform-org-panel">
        <div className="dash-panel-heading"><div><span className="dash-panel-kicker">PLATFORM OPERATIONS</span><h2>Recently created organizations</h2><p className="dash-record-subtitle">Aggregate platform view. Organization activity and status are read from the live database.</p></div><button type="button" className="dash-icon-button" onClick={() => setRefresh((value) => value + 1)} aria-label="Refresh platform overview"><RefreshCw size={16} /></button></div>
        {overview.recent_organizations.length ? <div className="dash-table-scroll"><table><thead><tr><th>ORGANIZATION</th><th>TYPE</th><th>STATUS</th><th>CREATED</th></tr></thead><tbody>{overview.recent_organizations.map((organization) => <tr key={organization.id}><td><b>{organization.name}</b></td><td>{organization.type.replaceAll('_', ' ')}</td><td><span className={`table-status ${organization.status === 'active' ? 'status-live' : ''}`}><i />{organization.status}</span></td><td>{new Date(organization.created_at).toLocaleDateString()}</td></tr>)}</tbody></table></div> : <p className="master-empty">No organizations have been registered yet.</p>}
      </section>
    </>}
  </div>
}
