import { useEffect, useMemo, useState } from 'react'
import { AlertCircle, Bell, CalendarDays, LoaderCircle } from 'lucide-react'
import { api } from '../lib/api'

type TimetableEntry = {
  id: number
  weekday: number
  starts_at: string
  ends_at: string
  room: string | null
  is_active: boolean
  academic_section: { name: string; academic_class: { name: string } }
  subject: { name: string }
  teacher: { name: string } | null
}
type Notification = { id: string; title: string; body: string; created_at: string; read_at: string | null }
type Role = 'admin' | 'management' | 'teacher' | 'student' | 'parent' | 'educator'

const weekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

export default function DashboardAgenda({ organizationId, role, onViewSchedule }: { organizationId: number; role: Role; onViewSchedule: () => void }) {
  const isFamily = role === 'student' || role === 'parent'
  const [entries, setEntries] = useState<TimetableEntry[]>([])
  const [notifications, setNotifications] = useState<Notification[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    const path = isFamily
      ? `/api/v1/organizations/${organizationId}/notifications?per_page=3`
      : `/api/v1/organizations/${organizationId}/timetable?per_page=100`
    const request = isFamily
      ? api.get<{ data: Notification[] }>(path).then(result => { if (!cancelled) setNotifications(result.data) })
      : api.get<{ data: TimetableEntry[] }>(path).then(result => { if (!cancelled) setEntries(result.data) })
    request.catch(reason => {
      if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load your dashboard updates.')
    }).finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [organizationId, isFamily])

  const upcomingSlots = useMemo(() => {
    const today = (new Date().getDay() + 6) % 7
    const now = new Date().toTimeString().slice(0, 5)
    return entries.filter(entry => entry.is_active && (entry.weekday > today || (entry.weekday === today && entry.starts_at.slice(0, 5) >= now)))
      .sort((a, b) => a.weekday - b.weekday || a.starts_at.localeCompare(b.starts_at)).slice(0, 3)
  }, [entries])

  return <section className="dash-panel dash-table-panel dash-agenda-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">{isFamily ? 'FROM YOUR WORKSPACE' : 'THIS WEEK'}</span><h2>{isFamily ? 'Recent updates' : 'Upcoming classes'}</h2></div>{!isFamily && <button onClick={onViewSchedule}>View timetable <span aria-hidden="true">→</span></button>}</div>
    {loading ? <div className="dash-agenda-state"><LoaderCircle className="dash-spin" size={16} />Loading {isFamily ? 'updates' : 'timetable'}…</div>
      : error ? <div className="dash-agenda-state dash-agenda-error" role="alert"><AlertCircle size={16} />{error}</div>
        : isFamily ? notifications.length ? <div className="dash-agenda-list">{notifications.map(item => <article className="dash-agenda-item" key={item.id}><span className={`dash-agenda-icon ${item.read_at ? '' : 'unread'}`}><Bell size={14} /></span><div><b>{item.title}</b><small>{item.body}</small></div><time dateTime={item.created_at}>{new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(new Date(item.created_at))}</time></article>)}</div>
          : <div className="dash-agenda-state"><Bell size={16} />No new updates for this workspace yet.</div>
        : upcomingSlots.length ? <div className="dash-agenda-list">{upcomingSlots.map(entry => <article className="dash-agenda-item" key={entry.id}><span className="dash-agenda-icon"><CalendarDays size={14} /></span><div><b>{entry.academic_section.academic_class.name} · {entry.academic_section.name} — {entry.subject.name}</b><small>{weekDays[entry.weekday]} · {entry.teacher?.name ?? 'Teaching team'}{entry.room ? ` · ${entry.room}` : ''}</small></div><time>{entry.starts_at.slice(0, 5)}–{entry.ends_at.slice(0, 5)}</time></article>)}</div>
          : <div className="dash-agenda-state"><CalendarDays size={16} />No upcoming timetable classes are scheduled this week.</div>}
  </section>
}
