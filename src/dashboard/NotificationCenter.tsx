import { useCallback, useEffect, useState } from 'react'
import { Bell, Check, LoaderCircle, Settings2 } from 'lucide-react'
import { api } from '../lib/api'

type NotificationItem = { id: string; type: string; title: string; body: string; read_at: string | null; created_at: string }
type NotificationResponse = { data: NotificationItem[]; meta: { unread: number; total: number } }
type Category = 'announcements' | 'meetings' | 'fees'
type Preferences = { announcements: boolean; meetings: boolean; fees: boolean; email: Record<Category, boolean> }
type Delivery = { id: number; title: string; status: 'queued' | 'sent' | 'failed' | 'cancelled'; attempts: number; queued_at: string; sent_at: string | null; failed_at: string | null; cancelled_at: string | null }
const preferenceLabels: Record<Category, string> = { announcements: 'Announcements', meetings: 'Scheduled meetings', fees: 'Fee updates' }
const defaultPreferences: Preferences = { announcements: true, meetings: true, fees: true, email: { announcements: false, meetings: false, fees: false } }

export default function NotificationCenter({ organizationId }: { organizationId: number }) {
  const base = `/api/v1/organizations/${organizationId}/notifications`
  const [items, setItems] = useState<NotificationItem[]>([])
  const [unread, setUnread] = useState(0)
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showPreferences, setShowPreferences] = useState(false)
  const [preferences, setPreferences] = useState(defaultPreferences)
  const [emailAvailable, setEmailAvailable] = useState(false)
  const [deliveries, setDeliveries] = useState<Delivery[]>([])
  const [loadingDeliveries, setLoadingDeliveries] = useState(false)
  const [savingPreferences, setSavingPreferences] = useState(false)

  const refresh = useCallback(async () => {
    try {
      const response = await api.get<NotificationResponse>(`${base}?per_page=10`)
      setItems(response.data); setUnread(response.meta.unread); setError('')
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not load notifications.')
    } finally { setLoading(false) }
  }, [base])

  useEffect(() => { void refresh() }, [refresh])

  async function markRead(item: NotificationItem) {
    if (item.read_at) return
    try {
      const response = await api.post<{ data: { read_at: string | null } }>(`${base}/${item.id}/read`)
      setItems(current => current.map(notification => notification.id === item.id ? { ...notification, read_at: response.data.read_at } : notification))
      setUnread(current => Math.max(0, current - 1))
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not mark the notification as read.') }
  }

  async function togglePreferences() {
    const next = !showPreferences
    setShowPreferences(next)
    if (next) {
      setLoadingDeliveries(true)
      try {
        const [response, deliveryResponse] = await Promise.all([
          api.get<{ data: Preferences; email_available: boolean }>('/api/v1/me/notification-preferences'),
          api.get<{ data: Delivery[] }>(`/api/v1/organizations/${organizationId}/email-deliveries?per_page=10`),
        ])
        setPreferences(response.data); setEmailAvailable(response.email_available); setDeliveries(deliveryResponse.data)
      } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not load preferences.') }
      finally { setLoadingDeliveries(false) }
    }
  }

  async function savePreferences(next: Preferences) {
    setPreferences(next)
    setSavingPreferences(true)
    setError('')
    try {
      const response = await api.patch<{ data: Preferences }>('/api/v1/me/notification-preferences', next)
      setPreferences(response.data)
    } catch (reason) {
      setPreferences(preferences)
      setError(reason instanceof Error ? reason.message : 'Could not save preferences.')
    } finally { setSavingPreferences(false) }
  }

  function updateInAppPreference(key: Category, checked: boolean) {
    void savePreferences({ ...preferences, [key]: checked })
  }

  function updateEmailPreference(key: Category, checked: boolean) {
    void savePreferences({ ...preferences, email: { ...preferences.email, [key]: checked } })
  }

  return <div className="notification-center">
    <button className="dash-icon-button" aria-label={`Notifications, ${unread} unread`} aria-expanded={open} onClick={() => { setOpen(value => !value); if (!open) void refresh() }}><Bell size={17} />{unread > 0 && <i />}</button>
    {open && <section className="notification-popover" aria-label="Notifications">
      <header><div><b>Notifications</b><small>{unread} unread</small></div><div className="notification-actions"><button onClick={() => void togglePreferences()} aria-label="Notification preferences" aria-expanded={showPreferences}><Settings2 size={16} /></button><button onClick={() => setOpen(false)} aria-label="Close notifications">×</button></div></header>
      {error && <p className="notification-error" role="alert">{error}</p>}
      {showPreferences ? <div className="notification-preferences">
        <p>In-app notices</p>
        {(Object.entries(preferenceLabels) as [Category, string][]).map(([key, label]) => <label key={`in-app-${key}`}><span>{label}</span><input type="checkbox" checked={preferences[key]} disabled={savingPreferences} onChange={event => updateInAppPreference(key, event.target.checked)} /></label>)}
        <p>Email alerts</p>
        {emailAvailable ? (Object.entries(preferenceLabels) as [Category, string][]).map(([key, label]) => <label key={`email-${key}`}><span>{label}</span><input type="checkbox" checked={preferences.email[key]} disabled={savingPreferences} onChange={event => updateEmailPreference(key, event.target.checked)} /></label>) : <p>Email delivery is disabled until an administrator configures an approved mail transport.</p>}
        <p>Recent email delivery</p>
        {loadingDeliveries ? <p>Loading delivery status…</p> : deliveries.length ? <ul>{deliveries.map(delivery => <li key={delivery.id}><span><b>{delivery.title}</b><small>{delivery.status}{delivery.status === 'failed' ? ` after ${delivery.attempts} attempts` : ''}</small></span><time>{new Date(delivery.sent_at ?? delivery.failed_at ?? delivery.cancelled_at ?? delivery.queued_at).toLocaleString()}</time></li>)}</ul> : <p>No email notifications have been queued.</p>}
      </div> : loading ? <p className="notification-empty"><LoaderCircle className="master-spin" size={16} />Loading…</p> : items.length === 0 ? <p className="notification-empty">You’re all caught up.</p> : <ul>{items.map(item => <li key={item.id}><button className={item.read_at ? 'notification-read' : 'notification-unread'} onClick={() => void markRead(item)}><span><b>{item.title}</b><small>{item.body}</small><time>{new Date(item.created_at).toLocaleString()}</time></span>{item.read_at ? <Check size={14} /> : <i />}</button></li>)}</ul>}
    </section>}
  </div>
}
