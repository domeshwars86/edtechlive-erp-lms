import { useCallback, useEffect, useMemo, useState } from 'react'
import { AlertCircle, CalendarDays, ClipboardCheck, LoaderCircle, UsersRound } from 'lucide-react'
import { api } from '../lib/api'

type StudentProfile = { id: number; name: string; admission_number: string; academic_class?: { id: number; name: string } | null; academic_section?: { id: number; name: string } | null; academic_session?: { id: number; name: string } | null; relationship?: string; is_primary?: boolean }
type AttendanceEntry = { id: number; date: string; status: 'present' | 'absent' | 'late' | 'excused'; note: string | null; section: { class: string; name: string }; session: { name: string } }
type ListResult<T> = { data: T[]; meta?: { current_page: number; last_page: number; total: number } }
type Results = { exams: { id: number; exam: string; session: string; class_section: string; subject: string; exam_on: string; score: string | null; max_marks: string; outcome: string; remarks: string | null }[]; quizzes: { id: number; title: string; course: string; score: string | null; max_score: string | null; attempt_number: number; review_status: 'not_required' | 'pending' | 'partial' | 'graded'; completed_at: string | null }[]; assignments: { id: number; title: string; course: string; score: string | null; max_score: string | null; feedback: string | null; completed_at: string | null }[] }
type Progress = { data: { course_id: number; title: string; lessons: number; completed_lessons: number; completion_percent: number }[]; summary: { courses: number; lessons: number; completed_lessons: number; completion_percent: number } }
type HomeworkEntry = { id: number; title: string; course: string; due_at: string | null; max_points: string; submitted_at: string | null; score: string | null; feedback: string | null; status: 'pending' | 'overdue' | 'submitted' | 'graded' }

export default function FamilyWorkspace({ organizationId, role, active }: { organizationId: number; role: 'student' | 'parent'; active: string }) {
  const base = `/api/v1/organizations/${organizationId}/my`
  const [students, setStudents] = useState<StudentProfile[]>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [entries, setEntries] = useState<AttendanceEntry[]>([])
  const [results, setResults] = useState<Results>({ exams: [], quizzes: [], assignments: [] })
  const [progress, setProgress] = useState<Progress | null>(null)
  const [homework, setHomework] = useState<HomeworkEntry[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    const request = role === 'student'
      ? api.get<{ data: StudentProfile }>(`${base}/student`).then(result => ({ data: [result.data] }))
      : api.get<ListResult<StudentProfile>>(`${base}/children?per_page=100`)
    request.then(result => {
      if (cancelled) return
      setStudents(result.data)
      setSelectedId(current => current ?? result.data[0]?.id ?? null)
    }).catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load linked student profiles.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base, role])

  const selected = students.find(student => student.id === selectedId) ?? null
  const loadAttendance = useCallback(async () => {
    if (!selected) { setEntries([]); setTotal(0); return }
    setError('')
    try {
      const path = role === 'student' ? `${base}/student/attendance?per_page=100` : `${base}/children/${selected.id}/attendance?per_page=100`
      const result = await api.get<ListResult<AttendanceEntry>>(path)
      setEntries(result.data); setTotal(result.meta?.total ?? result.data.length)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not load attendance history.') }
  }, [base, role, selected])

  useEffect(() => { if (active === 'Attendance') void loadAttendance() }, [active, loadAttendance])

  const showResults = active === 'Results' || active === 'Examination results'
  const showProgress = active === 'My progress' || active === 'Academic progress'
  const showHomework = active === 'Homework'
  useEffect(() => {
    if (!showResults || !selected) { setResults({ exams: [], quizzes: [], assignments: [] }); return }
    let cancelled = false
    setError('')
    const path = role === 'student' ? `${base}/student/results` : `${base}/children/${selected.id}/results`
    api.get<{ data: Results }>(path).then(response => { if (!cancelled) setResults(response.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load assessment results.') })
    return () => { cancelled = true }
  }, [base, role, selected, showResults])

  useEffect(() => {
    if (!showProgress || !selected) { setProgress(null); return }
    let cancelled = false
    setError('')
    const path = role === 'student' ? `${base}/student/progress` : `${base}/children/${selected.id}/progress`
    api.get<Progress>(path).then(response => { if (!cancelled) setProgress(response) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load course progress.') })
    return () => { cancelled = true }
  }, [base, role, selected, showProgress])

  useEffect(() => {
    if (!showHomework || !selected) { setHomework([]); return }
    let cancelled = false
    setError('')
    const path = role === 'student' ? `${base}/student/homework` : `${base}/children/${selected.id}/homework`
    api.get<ListResult<HomeworkEntry>>(path).then(response => { if (!cancelled) setHomework(response.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load homework.') })
    return () => { cancelled = true }
  }, [base, role, selected, showHomework])

  const counts = useMemo(() => entries.reduce((totals, entry) => { totals[entry.status] += 1; return totals }, { present: 0, absent: 0, late: 0, excused: 0 }), [entries])
  const showAttendance = active === 'Attendance'

  return <div className="family-workspace">
    <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">{role === 'student' ? 'MY LEARNING RECORD' : 'FAMILY WORKSPACE'}</span><h2>{showAttendance ? 'Attendance history' : showResults ? 'Assessment results' : showProgress ? 'Academic progress' : showHomework ? 'Homework' : role === 'student' ? 'My student profile' : 'My children'}</h2><p className="dash-record-subtitle">{role === 'student' ? 'This profile is linked to your signed-in student account.' : 'Only student profiles connected through a parent invitation appear here.'}</p></div></div>
      {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
      {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading linked records…</p> : students.length === 0 ? <p className="master-empty"><UsersRound size={18} />No linked student profile is available for this account yet.</p> : <div className="family-student-list">{students.map(student => <button key={student.id} className={`family-student-card ${student.id === selectedId ? 'family-student-selected' : ''}`} onClick={() => setSelectedId(student.id)}><span><UsersRound size={17} /></span><b>{student.name}</b><small>{student.academic_class?.name ? `${student.academic_class.name} · Section ${student.academic_section?.name ?? '—'}` : `Admission ${student.admission_number}`}</small>{student.academic_session?.name && <small>{student.academic_session.name}</small>}{student.relationship && <small>{student.relationship}{student.is_primary ? ' · Primary contact' : ''}</small>}</button>)}</div>}
    </section>
    {showAttendance && selected && <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">{selected.name.toUpperCase()}</span><h2>Daily records</h2><p className="dash-record-subtitle">Attendance records shared with this student or family account.</p></div></div>
      <div className="attendance-summary"><span><b>{total}</b> records</span><span>Present <b>{counts.present}</b></span><span>Absent <b>{counts.absent}</b></span><span>Late <b>{counts.late}</b></span><span>Excused <b>{counts.excused}</b></span></div>
      {entries.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>DATE</th><th>CLASS</th><th>SESSION</th><th>STATUS</th><th>NOTE</th></tr></thead><tbody>{entries.map(entry => <tr key={entry.id}><td><span className="family-date"><CalendarDays size={14} />{new Date(`${entry.date}T00:00:00`).toLocaleDateString()}</span></td><td>{entry.section.class} · {entry.section.name}</td><td>{entry.session.name}</td><td><span className={`family-attendance-status ${entry.status}`}>{entry.status}</span></td><td>{entry.note || '—'}</td></tr>)}</tbody></table></div> : !error && <p className="master-empty"><ClipboardCheck size={18} />No attendance records have been shared yet.</p>}
    </section>}
    {showResults && selected && <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">{selected.name.toUpperCase()}</span><h2>Completed assessments</h2><p className="dash-record-subtitle">Quiz scores and graded assignment feedback for this linked student.</p></div></div>
      <p className="dash-record-subtitle">Published school exam results, course quiz scores, and graded assignment feedback for this linked student.</p>
      <h3 className="family-results-heading">School examinations</h3>
      {results.exams.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>EXAMINATION</th><th>SESSION · CLASS</th><th>SUBJECT</th><th>RESULT</th><th>EXAM DATE</th><th>REMARKS</th></tr></thead><tbody>{results.exams.map(result => <tr key={result.id}><td>{result.exam}</td><td>{result.session} · {result.class_section}</td><td>{result.subject}</td><td><b>{result.outcome === 'graded' ? `${result.score} / ${result.max_marks}` : result.outcome}</b></td><td>{new Date(`${result.exam_on}T00:00:00`).toLocaleDateString()}</td><td>{result.remarks || '—'}</td></tr>)}</tbody></table></div> : <p className="master-empty"><ClipboardCheck size={18} />No school examination results have been published.</p>}
      <h3 className="family-results-heading">Tests &amp; quizzes</h3>
      {results.quizzes.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>ASSESSMENT</th><th>COURSE</th><th>SCORE</th><th>REVIEW</th><th>ATTEMPT</th><th>COMPLETED</th></tr></thead><tbody>{results.quizzes.map(result => <tr key={result.id}><td>{result.title}</td><td>{result.course}</td><td><b>{result.score ?? '—'}</b> / {result.max_score ?? '—'}</td><td>{result.review_status === 'pending' || result.review_status === 'partial' ? 'Awaiting teacher review' : result.review_status === 'graded' ? 'Reviewed' : 'Complete'}</td><td>{result.attempt_number}</td><td>{result.completed_at ? new Date(result.completed_at).toLocaleDateString() : '—'}</td></tr>)}</tbody></table></div> : <p className="master-empty"><ClipboardCheck size={18} />No completed quizzes are available yet.</p>}
      <h3 className="family-results-heading">Graded assignments</h3>
      {results.assignments.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>ASSIGNMENT</th><th>COURSE</th><th>SCORE</th><th>FEEDBACK</th><th>GRADED</th></tr></thead><tbody>{results.assignments.map(result => <tr key={result.id}><td>{result.title}</td><td>{result.course}</td><td><b>{result.score ?? '—'}</b> / {result.max_score ?? '—'}</td><td>{result.feedback || '—'}</td><td>{result.completed_at ? new Date(result.completed_at).toLocaleDateString() : '—'}</td></tr>)}</tbody></table></div> : <p className="master-empty"><ClipboardCheck size={18} />No graded assignments are available yet.</p>}
    </section>}
    {showProgress && selected && <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">{selected.name.toUpperCase()}</span><h2>Published course progress</h2><p className="dash-record-subtitle">Completion is based on published lessons in courses actively assigned to this learner.</p></div></div>
      {progress ? <><div className="attendance-summary"><span><b>{progress.summary.courses}</b> courses</span><span><b>{progress.summary.completed_lessons}</b> / {progress.summary.lessons} lessons complete</span><span><b>{progress.summary.completion_percent}%</b> overall</span></div>{progress.data.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>COURSE</th><th>COMPLETED</th><th>PROGRESS</th></tr></thead><tbody>{progress.data.map(course => <tr key={course.course_id}><td>{course.title}</td><td>{course.completed_lessons} / {course.lessons}</td><td><div className="family-progress-track" role="progressbar" aria-label={`${course.title} completion`} aria-valuenow={course.completion_percent} aria-valuemin={0} aria-valuemax={100}><span style={{ width: `${course.completion_percent}%` }} /></div><small>{course.completion_percent}%</small></td></tr>)}</tbody></table></div> : <p className="master-empty"><ClipboardCheck size={18} />No published courses are actively assigned to this learner yet.</p>}</> : !error && <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading course progress…</p>}
    </section>}
    {showHomework && selected && <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">{selected.name.toUpperCase()}</span><h2>Published assignments</h2><p className="dash-record-subtitle">Due dates, submission status, and teacher feedback for this linked student.</p></div></div>
      {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading homework…</p> : homework.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>ASSIGNMENT</th><th>COURSE</th><th>DUE</th><th>STATUS</th><th>SCORE</th><th>FEEDBACK</th></tr></thead><tbody>{homework.map(item => <tr key={item.id}><td>{item.title}</td><td>{item.course}</td><td>{item.due_at ? new Date(item.due_at).toLocaleDateString() : '—'}</td><td><span className={`family-attendance-status ${item.status === 'graded' ? 'present' : item.status === 'overdue' ? 'absent' : item.status === 'submitted' ? 'late' : 'excused'}`}>{item.status}</span></td><td>{item.score ?? '—'} / {item.max_points}</td><td>{item.feedback || '—'}</td></tr>)}</tbody></table></div> : !error && <p className="master-empty"><ClipboardCheck size={18} />No published assignments are currently linked to this learner.</p>}
    </section>}
  </div>
}
