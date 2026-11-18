import { useEffect, useState } from 'react'
import { AlertCircle, BarChart3, CalendarDays, LoaderCircle } from 'lucide-react'
import { api } from '../lib/api'
import CourseProgressReports from './CourseProgressReports'
import EnrollmentReport from './EnrollmentReport'
import TeacherWorkloadReport from './TeacherWorkloadReport'
import AssessmentReport from './AssessmentReport'
import ExamReport from './ExamReport'

type SectionSummary = { section_id: number; class: string; section: string; records: number; present: number; late: number; absent: number; excused: number; attendance_rate: number }
type Report = { from: string; to: string; totals: Omit<SectionSummary, 'section_id' | 'class' | 'section'>; sections: SectionSummary[] }

function dateValue(date: Date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}` }

export default function AttendanceReports({ organizationId }: { organizationId: number }) {
  const today = new Date()
  const prior = new Date(today)
  prior.setDate(prior.getDate() - 29)
  const [from, setFrom] = useState(dateValue(prior))
  const [to, setTo] = useState(dateValue(today))
  const [report, setReport] = useState<Report | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [activeReport, setActiveReport] = useState<'attendance' | 'courses' | 'enrollment' | 'workload' | 'assessments' | 'exams'>('attendance')

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    api.get<{ data: Report }>(`/api/v1/organizations/${organizationId}/reports/attendance?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)
      .then(result => { if (!cancelled) setReport(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load attendance report.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [organizationId, from, to])

  return <div className="reports-workspace">
    <nav className="report-tabs" aria-label="Academic reports"><button aria-pressed={activeReport === 'attendance'} className={activeReport === 'attendance' ? 'report-tab-active' : ''} onClick={() => setActiveReport('attendance')}>Attendance</button><button aria-pressed={activeReport === 'courses'} className={activeReport === 'courses' ? 'report-tab-active' : ''} onClick={() => setActiveReport('courses')}>Course progress</button><button aria-pressed={activeReport === 'enrollment'} className={activeReport === 'enrollment' ? 'report-tab-active' : ''} onClick={() => setActiveReport('enrollment')}>Enrollment</button><button aria-pressed={activeReport === 'workload'} className={activeReport === 'workload' ? 'report-tab-active' : ''} onClick={() => setActiveReport('workload')}>Teacher workload</button><button aria-pressed={activeReport === 'assessments'} className={activeReport === 'assessments' ? 'report-tab-active' : ''} onClick={() => setActiveReport('assessments')}>Assessments</button><button aria-pressed={activeReport === 'exams'} className={activeReport === 'exams' ? 'report-tab-active' : ''} onClick={() => setActiveReport('exams')}>School exams</button></nav>
    {activeReport === 'courses' ? <CourseProgressReports organizationId={organizationId} /> : activeReport === 'enrollment' ? <EnrollmentReport organizationId={organizationId} /> : activeReport === 'workload' ? <TeacherWorkloadReport organizationId={organizationId} /> : activeReport === 'assessments' ? <AssessmentReport organizationId={organizationId} /> : activeReport === 'exams' ? <ExamReport organizationId={organizationId} /> : <section className="dash-panel dash-masters-panel">
      <div className="dash-panel-heading"><div><span className="dash-panel-kicker">ACADEMIC REPORTS</span><h2>Attendance summary</h2><p className="dash-record-subtitle">Recorded attendance by class and section for the selected date range. Late marks count toward attendance.</p></div></div>
      <div className="report-filters"><label>From<input type="date" value={from} max={to} onChange={event => setFrom(event.target.value)} /></label><label>To<input type="date" value={to} min={from} onChange={event => setTo(event.target.value)} /></label><span><CalendarDays size={16} /> {from} – {to}</span></div>
      {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
      {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading attendance summary…</p> : report && <>
        <div className="report-stat-grid"><article><small>ATTENDANCE RATE</small><b>{report.totals.attendance_rate}%</b></article><article><small>MARKED PRESENT</small><b>{report.totals.present}</b></article><article><small>LATE</small><b>{report.totals.late}</b></article><article><small>ABSENT</small><b>{report.totals.absent}</b></article><article><small>TOTAL RECORDS</small><b>{report.totals.records}</b></article></div>
        {report.sections.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>CLASS / SECTION</th><th>RATE</th><th>PRESENT</th><th>LATE</th><th>ABSENT</th><th>EXCUSED</th><th>MARKS</th></tr></thead><tbody>{report.sections.map(section => <tr key={section.section_id}><td><b>{section.class} · {section.section}</b></td><td><span className="report-rate">{section.attendance_rate}%</span></td><td>{section.present}</td><td>{section.late}</td><td>{section.absent}</td><td>{section.excused}</td><td>{section.records}</td></tr>)}</tbody></table></div> : <p className="master-empty"><BarChart3 size={18} />No attendance has been recorded in this date range.</p>}
      </>}
    </section>}
  </div>
}
