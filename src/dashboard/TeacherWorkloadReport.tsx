import { useEffect, useState } from 'react'
import { AlertCircle, Clock3, Download, LoaderCircle, UsersRound } from 'lucide-react'
import { api } from '../lib/api'
import { downloadCsv } from '../lib/csv'

type TeacherWorkload = { teacher_id: number; teacher: string; section_assignments: number; active_learners: number; weekly_timetable_slots: number; weekly_scheduled_hours: number }
type Report = { as_of: string; sessions: string[]; summary: { active_teachers: number; teachers_with_assignments: number; section_assignments: number; teacher_learner_assignments: number; weekly_timetable_slots: number; weekly_scheduled_hours: number }; teachers: TeacherWorkload[] }

export default function TeacherWorkloadReport({ organizationId }: { organizationId: number }) {
  const [report, setReport] = useState<Report | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    setLoading(true); setError('')
    api.get<{ data: Report }>(`/api/v1/organizations/${organizationId}/reports/workload`)
      .then(result => { if (!cancelled) setReport(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load teacher workload.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [organizationId])

  return <section className="dash-panel dash-masters-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">ACADEMIC REPORTS</span><h2>Teacher workload</h2><p className="dash-record-subtitle">Active teachers, assigned class sections, roster reach, and scheduled weekly time{report ? ` · As of ${report.as_of}` : ''}{report?.sessions.length ? ` · ${report.sessions.join(', ')}` : ''}.</p></div><button className="dash-secondary-button" type="button" disabled={loading || !report} onClick={() => report && downloadCsv('teacher-workload.csv', ['Teacher', 'Section assignments', 'Active learners', 'Weekly timetable slots', 'Weekly scheduled hours'], report.teachers.map(teacher => [teacher.teacher, teacher.section_assignments, teacher.active_learners, teacher.weekly_timetable_slots, teacher.weekly_scheduled_hours]))}><Download size={14} />Export CSV</button></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading teacher workload…</p> : report && <>
      <div className="report-stat-grid"><article><small>ACTIVE TEACHERS</small><b>{report.summary.active_teachers}</b></article><article><small>WITH ASSIGNMENTS</small><b>{report.summary.teachers_with_assignments}</b></article><article><small>SECTION ASSIGNMENTS</small><b>{report.summary.section_assignments}</b></article><article><small>TEACHER-LEARNER LINKS</small><b>{report.summary.teacher_learner_assignments}</b></article><article><small>WEEKLY SCHEDULE</small><b>{report.summary.weekly_scheduled_hours} h</b></article></div>
      {report.teachers.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>TEACHER</th><th>SECTIONS</th><th>ACTIVE LEARNERS</th><th>WEEKLY SLOTS</th><th>SCHEDULED HOURS</th></tr></thead><tbody>{report.teachers.map(teacher => <tr key={teacher.teacher_id}><td><b>{teacher.teacher}</b></td><td>{teacher.section_assignments}</td><td><span className="report-rate"><UsersRound size={14} /> {teacher.active_learners}</span></td><td>{teacher.weekly_timetable_slots}</td><td><span className="report-rate"><Clock3 size={14} /> {teacher.weekly_scheduled_hours} h</span></td></tr>)}</tbody></table></div> : <p className="master-empty">No active teachers are listed.</p>}
    </>}
  </section>
}
