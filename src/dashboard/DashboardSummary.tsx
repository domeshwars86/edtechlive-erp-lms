import { useEffect, useState } from 'react'
import { CalendarDays, ChartNoAxesCombined, GraduationCap, LoaderCircle, Users } from 'lucide-react'
import { api } from '../lib/api'
import type { LucideIcon } from 'lucide-react'

type Summary = {
  date: string
  students: number
  teachers: number
  classes_today: number
  attendance_today: { records: number; present: number; late: number; absent: number; excused: number; rate: number | null }
}
type Stat = { label: string; value: string; note: string; icon: LucideIcon; tone: string }

export default function DashboardSummary({ organizationId }: { organizationId: number }) {
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
  }, [organizationId])

  const stats: Stat[] = summary ? [
    { label: 'Active students', value: String(summary.students), note: 'Current organization roster', icon: Users, tone: 'blue' },
    { label: 'Active teachers', value: String(summary.teachers), note: 'Current organization roster', icon: GraduationCap, tone: 'mint' },
    { label: 'Classes today', value: String(summary.classes_today), note: 'Active timetable · today', icon: CalendarDays, tone: 'lilac' },
    { label: 'Attendance today', value: summary.attendance_today.rate === null ? '—' : `${summary.attendance_today.rate}%`, note: summary.attendance_today.records ? `${summary.attendance_today.records} marks recorded` : 'No marks recorded today', icon: ChartNoAxesCombined, tone: 'peach' },
  ] : []

  return <>
    {error && <div className="dashboard-summary-error" role="alert">Live overview could not be loaded: {error}</div>}
    {loading && <p className="dashboard-summary-loading" role="status"><LoaderCircle className="master-spin" size={15} />Loading organization summary…</p>}
    {!loading && !error && <section className="dash-stat-grid" aria-label="Live organization summary" aria-live="polite">{stats.map(({ label, value, note, icon: Icon, tone }) => <article className="dash-stat-card" key={label}><div className={`dash-stat-icon ${tone}`}><Icon size={18} /></div><small>{label}</small><b>{value}</b><em>{note}</em></article>)}</section>}
  </>
}
