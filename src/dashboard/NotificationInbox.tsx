import { useCallback, useEffect, useState } from 'react'
import { AlertCircle, Bell, Check, LoaderCircle } from 'lucide-react'
import { api } from '../lib/api'
import './NotificationInbox.css'

type NotificationItem = { id: string; type: string; title: string; body: string; resource_type: string | null; resource_id: number | null; read_at: string | null; created_at: string }
type NotificationResponse = { data: NotificationItem[]; meta: { current_page: number; last_page: number; total: number; unread: number } }

export default function NotificationInbox({ organizationId, onOpenLearning }: { organizationId: number; onOpenLearning?: () => void }) {
  const [items, setItems] = useState<NotificationItem[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [unread, setUnread] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [savingId, setSavingId] = useState<string | null>(null)

  const load = useCallback(async (requestedPage: number) => {
    setLoading(true)
    setError('')
    try {
      const result = await api.get<NotificationResponse>(`/api/v1/organizations/${organizationId}/notifications?per_page=25&page=${requestedPage}`)
      setItems(result.data)
      setPage(result.meta.current_page)
      setLastPage(result.meta.last_page)
      setTotal(result.meta.total)
      setUnread(result.meta.unread)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not load notices.')
    } finally {
      setLoading(false)
    }
  }, [organizationId])

  useEffect(() => { void load(page) }, [load, page])

  async function markRead(item: NotificationItem) {
    if (item.read_at || savingId) return
    setSavingId(item.id)
    setError('')
    try {
      const result = await api.post<{ data: { read_at: string | null } }>(`/api/v1/organizations/${organizationId}/notifications/${item.id}/read`)
      setItems(current => current.map(notification => notification.id === item.id ? { ...notification, read_at: result.data.read_at } : notification))
      setUnread(current => Math.max(0, current - 1))
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not mark this notice as read.')
    } finally {
      setSavingId(null)
    }
  }

  async function openLearning(item: NotificationItem) {
    if (!item.read_at) await markRead(item)
    onOpenLearning?.()
  }

  return <section className="dash-panel dash-masters-panel notification-inbox" aria-labelledby="notification-inbox-title">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">ORGANIZATION INBOX</span><h2 id="notification-inbox-title">Notices</h2><p className="dash-record-subtitle">Updates sent to your account for this organization. {unread} unread.</p></div></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading notices…</p> : items.length ? <>
      <ul className="notification-inbox-list">{items.map(item => <li key={item.id} className={item.read_at ? 'notification-inbox-read' : 'notification-inbox-unread'}>
        <div className="notification-inbox-icon" aria-hidden="true">{item.read_at ? <Check size={17} /> : <Bell size={17} />}</div>
        <div className="notification-inbox-copy"><div><h3>{item.title}</h3><span>{item.type.replaceAll('_', ' ')}</span></div><p>{item.body}</p><time dateTime={item.created_at}>{new Date(item.created_at).toLocaleString()}</time></div>
        {item.type.startsWith('learning_') && item.resource_type && item.resource_id !== null && onOpenLearning && <button className="master-action notification-mark-read" type="button" disabled={savingId !== null} onClick={() => void openLearning(item)}>Open learning</button>}
        {!item.read_at && <button className="master-action notification-mark-read" type="button" disabled={savingId !== null} onClick={() => void markRead(item)}>{savingId === item.id ? 'Saving…' : 'Mark read'}</button>}
      </li>)}</ul>
      <div className="platform-directory-pagination"><span>{total} notice{total === 1 ? '' : 's'} · Page {page} of {lastPage}</span><button className="master-action" type="button" disabled={page <= 1 || loading} onClick={() => setPage(current => current - 1)}>Previous</button><button className="master-action" type="button" disabled={page >= lastPage || loading} onClick={() => setPage(current => current + 1)}>Next</button></div>
    </> : <p className="master-empty"><Bell size={18} />You’re all caught up. New organization updates will appear here.</p>}
  </section>
}
