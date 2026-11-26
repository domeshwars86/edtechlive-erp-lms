import { useEffect, useState } from 'react'
import { AlertCircle, LoaderCircle, RefreshCw, ShieldCheck, UsersRound } from 'lucide-react'
import { api } from '../lib/api'
import './PlatformRoleCatalog.css'

type PlatformRole = { key: string; name: string; scope: string; description: string }
type OrganizationRole = PlatformRole & { active_memberships: number; suspended_memberships: number }
type RoleCatalog = {
  platform_role: PlatformRole
  organization_roles: OrganizationRole[]
  access_model: { editable_here: false; summary: string }
}

export default function PlatformRoleCatalog() {
  const [catalog, setCatalog] = useState<RoleCatalog | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [refresh, setRefresh] = useState(0)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    api.get<{ data: RoleCatalog }>('/api/v1/platform/roles')
      .then((result) => { if (!cancelled) setCatalog(result.data) })
      .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load the role catalog.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [refresh])

  const activeCount = catalog?.organization_roles.reduce((sum, role) => sum + role.active_memberships, 0) ?? 0
  const suspendedCount = catalog?.organization_roles.reduce((sum, role) => sum + role.suspended_memberships, 0) ?? 0

  return <section className="platform-role-catalog" aria-labelledby="platform-role-catalog-title">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    <div className="platform-role-heading">
      <div>
        <span className="dash-panel-kicker">PLATFORM OPERATIONS</span>
        <h2 id="platform-role-catalog-title">Built-in roles and access scope</h2>
        <p>Review the fixed platform and organization roles with live membership totals. Individual learner records are not included.</p>
      </div>
      <button type="button" className="dash-icon-button" onClick={() => setRefresh((value) => value + 1)} aria-label="Refresh role catalog" disabled={loading}>
        <RefreshCw size={16} />
      </button>
    </div>
    {loading && !catalog ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading built-in roles…</p> : catalog && <>
      <article className="platform-role-card platform-role-platform">
        <div className="platform-role-icon"><ShieldCheck size={18} /></div>
        <div><div className="platform-role-card-title"><h3>{catalog.platform_role.name}</h3><span>{catalog.platform_role.scope}</span></div><p>{catalog.platform_role.description}</p></div>
      </article>
      <div className="platform-role-summary" aria-label="Organization membership totals">
        <span><UsersRound size={15} /> {activeCount} active memberships</span>
        <span>{suspendedCount} suspended memberships</span>
      </div>
      <div className="platform-role-grid">
        {catalog.organization_roles.map((role) => <article className="platform-role-card" key={role.key}>
          <div className="platform-role-card-title"><h3>{role.name}</h3><span>{role.active_memberships} active</span></div>
          <p>{role.description}</p>
          <small>{role.suspended_memberships} suspended · {role.scope}</small>
        </article>)}
      </div>
      <p className="platform-role-boundary"><ShieldCheck size={15} />{catalog.access_model.summary}</p>
      {error && <p className="platform-role-refresh-note" role="status">Showing the last successfully loaded role catalog.</p>}
    </>}
  </section>
}
