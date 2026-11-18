import { useEffect, useState } from 'react'
import { BookOpen, CalendarDays, ChartNoAxesCombined, ClipboardCheck, GraduationCap, ListTodo, LoaderCircle, NotebookPen, Users } from 'lucide-react'
import { api } from '../lib/api'
import type { LucideIcon } from 'lucide-react'

type OrganizationSummary = {
  kind?: 'organization'
  date: string
  students: number
  teachers: number
  classes_today: number
  attendance_today: { records: number; present: number; late: number; absent: number; excused: number; rate: number | null }
}
type StudentSummary = { kind: 'student'; date: string; active_courses: number; lessons_completed_week: number; assignments_pending: number; attendance_recent: { records: number; rate: number | null } }
type Summary = OrganizationSummary | StudentSummary
type Stat = { label: string; value: string; note: string; icon: LucideIcon; tone: string }

export default function DashboardSummary({ organizationId, role }: { organizationId: number; role: 'admin' | 'management' | 'student' }) {
  const [summary, setSummary] = useState<Summary | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    api.get<{ data: Summary }>(`/api/v1/organizations/${organizationId}/dashboard/summary`)
      .then(response => { if (!cancelled) { setSummary(response.data); setError('') } })
      .catch(reason => { if (!cancelled) { setError(reason instanceof Error ? reason.message : 'Could not load organization summary.'); setSummary(null) } })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [organizationId, role])

  const stats: Stat[] = summary?.kind === 'student' ? [
    { label: 'Courses in progress', value: String(summary.active_courses), note: 'Active enrollments', icon: BookOpen, tone: 'blue' },
    { label: 'Lessons completed', value: String(summary.lessons_completed_week), note: 'Last 7 calendar days', icon: NotebookPen, tone: 'mint' },
    { label: 'Assignments to submit', value: String(summary.assignments_pending), note: 'Published and not submitted', icon: ListTodo, tone: 'lilac' },
    { label: 'Attendance', value: summary.attendance_recent.rate === null ? '—' : `${summary.attendance_recent.rate}%`, note: summary.attendance_recent.records ? `Last 30 days · ${summary.attendance_recent.records} marks` : 'No marks in the last 30 days', icon: ClipboardCheck, tone: 'peach' },
  ] : summary ? [
    { label: 'Active students', value: String(summary.students), note: 'Current organization roster', icon: Users, tone: 'blue' },
    { label: 'Active teachers', value: String(summary.teachers), note: 'Current organization roster', icon: GraduationCap, tone: 'mint' },
    { label: 'Classes today', value: String(summary.classes_today), note: 'Active timetable · today', icon: CalendarDays, tone: 'lilac' },
    { label: 'Attendance today', value: summary.attendance_today.rate === null ? '—' : `${summary.attendance_today.rate}%`, note: summary.attendance_today.records ? `${summary.attendance_today.records} marks recorded` : 'No marks recorded today', icon: ChartNoAxesCombined, tone: 'peach' },
  ] : []

  return <>
    {error && <div className="dashboard-summary-error" role="alert">Live overview could not be loaded: {error}</div>}
    {loading && <p className="dashboard-summary-loading" role="status"><LoaderCircle className="master-spin" size={15} />Loading {role === 'student' ? 'learning' : 'organization'} summary…</p>}
    {!loading && !error && <section className="dash-stat-grid" aria-label="Live organization summary" aria-live="polite">{stats.map(({ label, value, note, icon: Icon, tone }) => <article className="dash-stat-card" key={label}><div className={`dash-stat-icon ${tone}`}><Icon size={18} /></div><small>{label}</small><b>{value}</b><em>{note}</em></article>)}</section>}
  </>
}
