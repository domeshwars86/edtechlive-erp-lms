import { useEffect, useState } from 'react'
import { AlertCircle, BarChart3, LoaderCircle } from 'lucide-react'
import { api } from '../lib/api'

type CourseProgressRow = { course_id: number; title: string; lessons: number; learners: number; average_percent: number; completed_learners: number }
type CourseProgressReport = { data: CourseProgressRow[]; summary: { courses: number; learners: number; average_percent: number } }

export default function CourseProgressReports({ organizationId }: { organizationId: number }) {
  const [report, setReport] = useState<CourseProgressReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    api.get<CourseProgressReport>(`/api/v1/organizations/${organizationId}/reports/course-progress`)
      .then(result => { if (!cancelled) { setReport(result); setError('') } })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load the course progress report.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [organizationId])

  return <section className="dash-panel dash-masters-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">ACADEMIC REPORTS</span><h2>Course progress</h2><p className="dash-record-subtitle">Completion across published courses and active learners. Student names and individual records are not included.</p></div></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading course progress…</p> : report && <>
      <div className="report-stat-grid"><article><small>PUBLISHED COURSES</small><b>{report.summary.courses}</b></article><article><small>ACTIVE ENROLLMENTS</small><b>{report.summary.learners}</b></article><article><small>AVERAGE COMPLETION</small><b>{report.summary.average_percent}%</b></article></div>
      {report.data.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>COURSE</th><th>LESSONS</th><th>LEARNERS</th><th>AVERAGE COMPLETION</th><th>COMPLETE</th></tr></thead><tbody>{report.data.map(course => <tr key={course.course_id}><td><b>{course.title}</b></td><td>{course.lessons}</td><td>{course.learners}</td><td><span className="report-rate">{course.average_percent}%</span></td><td>{course.completed_learners}</td></tr>)}</tbody></table></div> : <p className="master-empty"><BarChart3 size={18} />No published courses found.</p>}
    </>}
  </section>
}
