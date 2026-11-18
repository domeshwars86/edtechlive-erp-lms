import { useEffect, useState } from 'react'
import { AlertCircle, BookOpen, LoaderCircle, UsersRound } from 'lucide-react'
import { api } from '../lib/api'

type Section = { section_id: number; class: string; section: string; active: boolean; capacity: number | null; active_students: number; active_course_enrollments: number }
type Session = { session_id: number; session: string; is_current: boolean; is_active: boolean; starts_on: string; ends_on: string; active_students: number; active_course_enrollments: number; sections: Section[] }
type Report = { summary: { active_students: number; unassigned_students: number; students_with_published_courses: number; active_course_enrollments: number }; sessions: Session[] }

export default function EnrollmentReport({ organizationId }: { organizationId: number }) {
  const [report, setReport] = useState<Report | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    setLoading(true); setError('')
    api.get<{ data: Report }>(`/api/v1/organizations/${organizationId}/reports/enrollment`)
      .then(result => { if (!cancelled) setReport(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load enrollment report.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [organizationId])

  return <section className="dash-panel dash-masters-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">ACADEMIC REPORTS</span><h2>Enrollment overview</h2><p className="dash-record-subtitle">Active learner placement by academic session and section, plus course enrollment totals.</p></div></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading enrollment report…</p> : report && <>
      <div className="report-stat-grid"><article><small>ACTIVE STUDENTS</small><b>{report.summary.active_students}</b></article><article><small>UNASSIGNED STUDENTS</small><b>{report.summary.unassigned_students}</b></article><article><small>STUDENTS IN PUBLISHED COURSES</small><b>{report.summary.students_with_published_courses}</b></article><article><small>ACTIVE COURSE ENROLLMENTS</small><b>{report.summary.active_course_enrollments}</b></article></div>
      {report.sessions.length ? <div className="enrollment-session-list">{report.sessions.map(session => <section className="enrollment-session" key={session.session_id}><header><div><h3>{session.session}{session.is_current && <span>Current</span>}</h3><small>{session.starts_on} – {session.ends_on}{!session.is_active ? ' · Inactive session' : ''}</small></div><div><b><UsersRound size={14} />{session.active_students}</b><small>active students</small></div><div><b><BookOpen size={14} />{session.active_course_enrollments}</b><small>course enrollments</small></div></header>{session.sections.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>CLASS</th><th>SECTION</th><th>ROSTER</th><th>COURSE ENROLLMENTS</th><th>CAPACITY</th></tr></thead><tbody>{session.sections.map(section => <tr key={section.section_id}><td><b>{section.class}</b></td><td>{section.section}{!section.active ? ' · Inactive' : ''}</td><td>{section.active_students}</td><td>{section.active_course_enrollments}</td><td>{section.capacity ?? '—'}</td></tr>)}</tbody></table></div> : <p className="master-empty">No sections are configured for this session.</p>}</section>)}</div> : <p className="master-empty">No academic sessions have been set up yet.</p>}
    </>}
  </section>
}
