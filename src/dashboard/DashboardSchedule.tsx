import { useEffect, useState } from 'react'
import { AlertCircle, CalendarDays, ExternalLink, LoaderCircle } from 'lucide-react'
import { api } from '../lib/api'

type Meeting = {
  id: number
  kind: 'class' | 'management'
  title: string
  starts_at: string
  ends_at: string
  meeting_url: string
  status: 'scheduled' | 'cancelled'
  section: { name: string; class: string } | null
  course: { title: string } | null
  creator: { name: string } | null
}

export default function DashboardSchedule({ organizationId, role, onViewSchedule }: { organizationId: number; role: string; onViewSchedule: () => void }) {
  const [meetings, setMeetings] = useState<Meeting[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    api.get<{ data: Meeting[] }>(`/api/v1/organizations/${organizationId}/meetings?per_page=100`)
      .then(result => {
        if (!cancelled) setMeetings(result.data)
      })
      .catch(reason => {
        if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load your upcoming meetings.')
      })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [organizationId])

  const upcoming = meetings
    .filter(meeting => meeting.status === 'scheduled' && new Date(meeting.starts_at).getTime() >= Date.now())
    .sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime())
    .slice(0, 3)

  return <article className="dash-panel dash-schedule-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">YOUR SCHEDULE</span><h2>Upcoming meetings</h2></div><button onClick={onViewSchedule}>Full schedule <span aria-hidden="true">→</span></button></div>
    {loading ? <div className="dash-schedule-state"><LoaderCircle size={16} className="dash-spin" /> Loading meetings…</div>
      : error ? <div className="dash-schedule-state dash-schedule-error"><AlertCircle size={16} />{error}</div>
        : upcoming.length ? <div className="dash-schedule-list">{upcoming.map(meeting => {
          const start = new Date(meeting.starts_at)
          const end = new Date(meeting.ends_at)
          const canJoin = Date.now() >= start.getTime() && Date.now() <= end.getTime()
          const context = meeting.kind === 'management' ? 'Management meeting' : meeting.course?.title ?? ([meeting.section?.class, meeting.section?.name].filter(Boolean).join(' · ') || 'Class meeting')
          return <div className="dash-schedule-item" key={meeting.id}>
            <time dateTime={meeting.starts_at}>{new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(start)}<small>{new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(start)}</small></time>
            <i className="dash-schedule-line next" />
            <div><b>{meeting.title}</b><small>{context}{meeting.creator?.name ? ` · ${meeting.creator.name}` : ''}</small></div>
            {canJoin && <a className="dash-live-pill" href={meeting.meeting_url} target="_blank" rel="noreferrer">JOIN <ExternalLink size={10} /></a>}
          </div>
        })}</div>
          : <div className="dash-schedule-state"><CalendarDays size={16} /><span>{role === 'educator' ? 'No upcoming meetings are scheduled.' : 'No upcoming meetings are scheduled for you.'}</span></div>}
  </article>
}
