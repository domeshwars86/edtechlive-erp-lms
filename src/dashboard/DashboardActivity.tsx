import { useEffect, useState } from 'react'
import { ArrowRight, BarChart3, LoaderCircle } from 'lucide-react'
import { api } from '../lib/api'

type ActivityDay = { date: string; label: string; lessons_completed: number; assignments_submitted: number }
type ActivityResponse = { from: string; to: string; days: ActivityDay[] }
type ActivityRole = 'admin' | 'management' | 'teacher' | 'student' | 'parent' | 'educator'

const previewDays: ActivityDay[] = [
  { date: '1', label: 'Mon', lessons_completed: 4, assignments_submitted: 2 },
  { date: '2', label: 'Tue', lessons_completed: 6, assignments_submitted: 3 },
  { date: '3', label: 'Wed', lessons_completed: 5, assignments_submitted: 4 },
  { date: '4', label: 'Thu', lessons_completed: 7, assignments_submitted: 4 },
  { date: '5', label: 'Fri', lessons_completed: 6, assignments_submitted: 5 },
  { date: '6', label: 'Sat', lessons_completed: 3, assignments_submitted: 2 },
  { date: '7', label: 'Sun', lessons_completed: 2, assignments_submitted: 1 },
]

export default function DashboardActivity({ accountId, organizationId, role, onViewDetails }: {
  accountId?: number
  organizationId?: number
  role?: ActivityRole
  onViewDetails: () => void
}) {
  const [requestState, setRequestState] = useState<{ accountId: number; organizationId: number; role: ActivityRole; data?: ActivityResponse; error?: string } | null>(null)
  const currentRequest = requestState && requestState.accountId === accountId && requestState.organizationId === organizationId && requestState.role === role ? requestState : null
  const activity = currentRequest?.data ?? null
  const error = currentRequest?.error ?? ''

  useEffect(() => {
    if (!accountId || !organizationId || !role) return
    let cancelled = false
    api.get<{ data: ActivityResponse }>(`/api/v1/organizations/${organizationId}/dashboard/activity`)
      .then(response => { if (!cancelled) setRequestState({ accountId, organizationId, role, data: response.data }) })
      .catch(reason => { if (!cancelled) setRequestState({ accountId, organizationId, role, error: reason instanceof Error ? reason.message : 'Could not load learning activity.' }) })
    return () => { cancelled = true }
  }, [accountId, organizationId, role])

  const live = Boolean(accountId && organizationId && role)
  const days = activity?.days ?? (live ? [] : previewDays)
  const maxValue = Math.max(0, ...days.flatMap(day => [day.lessons_completed, day.assignments_submitted]))
  const axisStep = Math.max(1, Math.ceil(maxValue / 4))
  const axisMax = axisStep * 4
  const hasActivity = maxValue > 0

  return <article className="dash-panel dash-activity-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">A QUICK LOOK</span><h2>Learning activity</h2></div><button onClick={onViewDetails}>View details <ArrowRight size={14} /></button></div>
    <div className="dash-chart-legend"><span><i /> Lessons completed</span><span><i /> Assignments submitted</span><small>{live ? activity ? 'Last 7 days' : 'Loading activity' : 'Sample preview'}</small></div>
    {error ? <p className="dash-chart-message" role="status">Live activity is unavailable: {error}</p> : live && !activity ? <p className="dash-chart-message" role="status"><LoaderCircle className="master-spin" size={16} />Loading your activity…</p> : <>
      <div className="dash-chart" role="img" aria-label={live ? `Lessons completed and assignments submitted over the seven days from ${activity?.from} to ${activity?.to}.` : 'Sample learning activity preview.'}>
        <div className="dash-chart-y" aria-hidden="true">{[4, 3, 2, 1, 0].map(step => <span key={step}>{axisStep * step}</span>)}</div>
        <div className="dash-chart-plot"><div className="chart-grid-lines"><i /><i /><i /><i /><i /></div><div className="chart-bars">{days.map(day => <div className="chart-day" key={day.date}><div className="chart-pair"><i title={`${day.lessons_completed} lessons completed`} style={{ height: `${day.lessons_completed / axisMax * 100}%` }} /><i title={`${day.assignments_submitted} assignments submitted`} style={{ height: `${day.assignments_submitted / axisMax * 100}%` }} /></div><small>{day.label}</small></div>)}</div></div>
      </div>
      {live && !hasActivity ? <p className="dash-chart-message"><BarChart3 size={15} />No lesson completions or assignment submissions in the last seven days.</p> : null}
      <span className="sr-only">{live && activity ? `Activity dates: ${activity.from} through ${activity.to}.` : ''}</span>
    </>}
  </article>
}
