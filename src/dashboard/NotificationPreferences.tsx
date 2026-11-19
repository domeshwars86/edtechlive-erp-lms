import { useEffect, useState } from 'react'
import { Bell, CircleHelp, LoaderCircle, Mail, ShieldCheck } from 'lucide-react'
import { ApiError, api } from '../lib/api'
import './NotificationPreferences.css'

type Category = 'announcements' | 'meetings' | 'fees'
type Preferences = { announcements: boolean; meetings: boolean; fees: boolean; email: Record<Category, boolean> }
type Delivery = { id: number; title: string; status: 'queued' | 'sent' | 'failed' | 'cancelled'; attempts: number; queued_at: string; sent_at: string | null; failed_at: string | null; cancelled_at: string | null }

const categories: { key: Category; label: string; description: string }[] = [
  { key: 'announcements', label: 'Announcements', description: 'Updates shared by your organization.' },
  { key: 'meetings', label: 'Scheduled meetings', description: 'Changes to classes and meeting schedules.' },
  { key: 'fees', label: 'Fee updates', description: 'New fee records and receipt confirmations.' },
]
const defaults: Preferences = { announcements: true, meetings: true, fees: true, email: { announcements: false, meetings: false, fees: false } }

export default function NotificationPreferences({ organizationId, connected = true, compact = false }: { organizationId?: number; connected?: boolean; compact?: boolean }) {
  const [preferences, setPreferences] = useState(defaults)
  const [emailAvailable, setEmailAvailable] = useState(false)
  const [deliveries, setDeliveries] = useState<Delivery[]>([])
  const [loading, setLoading] = useState(connected)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    let active = true
    if (!connected) { setLoading(false); return () => { active = false } }
    setLoading(true)
    const deliveryRequest = organizationId
      ? api.get<{ data: Delivery[] }>(`/api/v1/organizations/${organizationId}/email-deliveries?per_page=10`)
      : Promise.resolve({ data: [] as Delivery[] })
    Promise.all([
      api.get<{ data: Preferences; email_available: boolean }>('/api/v1/me/notification-preferences'),
      deliveryRequest,
    ]).then(([preferenceResponse, deliveryResponse]) => {
      if (!active) return
      setPreferences(preferenceResponse.data)
      setEmailAvailable(preferenceResponse.email_available)
      setDeliveries(deliveryResponse.data)
      setError('')
    }).catch((reason: unknown) => {
      if (active) setError(reason instanceof ApiError ? reason.message : 'Could not load notification settings.')
    }).finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [connected, organizationId])

  async function save(next: Preferences) {
    if (saving || !connected) return
    const previous = preferences
    const payload = emailAvailable ? next : { ...next, email: { ...defaults.email } }
    setPreferences(payload)
    setSaving(true)
    setError('')
    setSaved(false)
    try {
      const response = await api.patch<{ data: Preferences }>('/api/v1/me/notification-preferences', payload)
      setPreferences(response.data)
      setSaved(true)
    } catch (reason) {
      setPreferences(previous)
      setError(reason instanceof Error ? reason.message : 'Could not save your notification settings.')
    } finally { setSaving(false) }
  }

  function setInApp(key: Category, enabled: boolean) {
    void save({ ...preferences, [key]: enabled })
  }

  function setEmail(key: Category, enabled: boolean) {
    void save({ ...preferences, email: { ...preferences.email, [key]: enabled } })
  }

  if (loading) return <div className="notification-preferences" role="status"><LoaderCircle className="master-spin" size={18} />Loading notification settings…</div>
  if (!connected) return <div className="notification-preferences-state"><Bell size={21} /><h2>Sign in to manage preferences</h2><p>Notification choices are saved to your account. Sign in and choose a workspace to update them.</p></div>

  return <div className={`notification-preferences ${compact ? 'notification-preferences--compact' : ''}`} aria-busy={saving}>
    {!compact && <header className="notification-preferences-heading"><span className="notification-preferences-icon"><Bell size={19} /></span><div><h2>Notification preferences</h2><p>Choose which account notifications you want to receive. Changes are saved to your account.</p></div></header>}
    {error && <p className="notification-error" role="alert"><CircleHelp size={15} />{error}</p>}
    {saved && <p className="notification-preferences-saved" role="status"><ShieldCheck size={15} />Preferences saved.</p>}
    <h3>In-app notices</h3>
    <p className="notification-preferences-help">Choose which updates appear in your notification inbox.</p>
    {categories.map(({ key, label, description }) => <label className="notification-preference-row" key={`in-app-${key}`}><span><b>{label}</b><small>{description}</small></span><input type="checkbox" checked={preferences[key]} disabled={saving} onChange={event => setInApp(key, event.target.checked)} /></label>)}
    <h3><Mail size={15} />Email alerts</h3>
    {emailAvailable ? <>
      <p className="notification-preferences-help">Email is sent only for categories you enable. You can change these choices at any time.</p>
      {categories.map(({ key, label, description }) => <label className="notification-preference-row" key={`email-${key}`}><span><b>{label}</b><small>{description}</small></span><input type="checkbox" checked={preferences.email[key]} disabled={saving} onChange={event => setEmail(key, event.target.checked)} /></label>)}
    </> : <div className="notification-email-unavailable"><CircleHelp size={16} /><p>Email delivery is disabled until an administrator configures an approved mail transport and queue worker. In-app notifications continue to work.</p></div>}
    {organizationId && <>
      <h3>Email delivery history</h3>
      <p className="notification-preferences-help">Recent delivery status for this workspace. Message content and recipient details are not shown here.</p>
      {deliveries.length ? <ul className="notification-delivery-list">{deliveries.map(delivery => <li key={delivery.id}><span><b>{delivery.title}</b><small>{delivery.status}{delivery.status === 'failed' ? ` after ${delivery.attempts} attempts` : ''}</small></span><time>{new Date(delivery.sent_at ?? delivery.failed_at ?? delivery.cancelled_at ?? delivery.queued_at).toLocaleString()}</time></li>)}</ul> : <p className="notification-preferences-empty">No email notifications have been queued for this workspace.</p>}
    </>}
  </div>
}
