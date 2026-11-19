import { useCallback, useEffect, useState } from 'react'
import { Bell, Check, LoaderCircle, Settings2 } from 'lucide-react'
import { api } from '../lib/api'
import NotificationPreferences from './NotificationPreferences'

type NotificationItem = { id: string; type: string; title: string; body: string; read_at: string | null; created_at: string }
type NotificationResponse = { data: NotificationItem[]; meta: { unread: number; total: number } }

export default function NotificationCenter({ organizationId }: { organizationId: number }) {
  const base = `/api/v1/organizations/${organizationId}/notifications`
  const [items, setItems] = useState<NotificationItem[]>([])
  const [unread, setUnread] = useState(0)
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showPreferences, setShowPreferences] = useState(false)

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

  return <div className="notification-center">
    <button className="dash-icon-button" aria-label={`Notifications, ${unread} unread`} aria-expanded={open} onClick={() => { setOpen(value => !value); if (!open) void refresh() }}><Bell size={17} />{unread > 0 && <i />}</button>
    {open && <section className="notification-popover" aria-label="Notifications">
      <header><div><b>Notifications</b><small>{unread} unread</small></div><div className="notification-actions"><button onClick={() => setShowPreferences(value => !value)} aria-label="Notification preferences" aria-expanded={showPreferences}><Settings2 size={16} /></button><button onClick={() => setOpen(false)} aria-label="Close notifications">×</button></div></header>
      {error && <p className="notification-error" role="alert">{error}</p>}
      {showPreferences ? <NotificationPreferences organizationId={organizationId} compact /> : loading ? <p className="notification-empty" role="status"><LoaderCircle className="master-spin" size={16} />Loading…</p> : items.length === 0 ? <p className="notification-empty">You’re all caught up.</p> : <ul>{items.map(item => <li key={item.id}><button className={item.read_at ? 'notification-read' : 'notification-unread'} onClick={() => void markRead(item)}><span><b>{item.title}</b><small>{item.body}</small><time>{new Date(item.created_at).toLocaleString()}</time></span>{item.read_at ? <Check size={14} /> : <i />}</button></li>)}</ul>}
    </section>}
  </div>
}
