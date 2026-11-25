import { useEffect, useState } from 'react'
import { AlertCircle, Check, CircleHelp, LoaderCircle, RefreshCw, ShieldCheck, TriangleAlert } from 'lucide-react'
import { api } from '../lib/api'
import './PlatformSystemReadiness.css'

type ReadinessStatus = 'configured' | 'setup_required' | 'optional' | 'local_only'
type ServiceStatus = { key: string; name: string; status: ReadinessStatus; detail: string }
type Readiness = { services: ServiceStatus[]; security_note: string }

const statusLabels: Record<ReadinessStatus, string> = {
  configured: 'Configured',
  setup_required: 'Setup required',
  optional: 'Optional · off',
  local_only: 'Local only',
}

const statusIcons = {
  configured: Check,
  setup_required: TriangleAlert,
  optional: CircleHelp,
  local_only: CircleHelp,
}

export default function PlatformSystemReadiness() {
  const [readiness, setReadiness] = useState<Readiness | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [refresh, setRefresh] = useState(0)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    api.get<{ data: Readiness }>('/api/v1/platform/system-readiness')
      .then((result) => { if (!cancelled) setReadiness(result.data) })
      .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load system readiness.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [refresh])

  return <section className="platform-readiness" aria-labelledby="platform-readiness-title">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    <div className="platform-readiness-heading">
      <div>
        <span className="dash-panel-kicker">PLATFORM OPERATIONS</span>
        <h2 id="platform-readiness-title">Integration readiness</h2>
        <p>Review provider configuration signals before enabling a service. This screen is read-only; credentials stay in the deployment environment.</p>
      </div>
      <button type="button" className="dash-icon-button" onClick={() => setRefresh((value) => value + 1)} aria-label="Refresh integration readiness" disabled={loading}>
        <RefreshCw size={16} />
      </button>
    </div>
    {loading && !readiness ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading service configuration…</p> : readiness && <>
      <div className="platform-readiness-grid" aria-live="polite">
        {readiness.services.map((service) => {
          const StatusIcon = statusIcons[service.status]
          return <article className="platform-readiness-card" key={service.key}>
            <div className="platform-readiness-card-top">
              <h3>{service.name}</h3>
              <span className={`platform-readiness-status readiness-${service.status}`}><StatusIcon size={13} />{statusLabels[service.status]}</span>
            </div>
            <p>{service.detail}</p>
          </article>
        })}
      </div>
      <p className="platform-readiness-note"><ShieldCheck size={15} />{readiness.security_note}</p>
      {error && <p className="platform-readiness-refresh-note" role="status">Showing the last successfully loaded configuration.</p>}
    </>}
  </section>
}
