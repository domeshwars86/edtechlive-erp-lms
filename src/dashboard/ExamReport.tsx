import { useEffect, useState } from 'react'
import { AlertCircle, BarChart3, Download, LoaderCircle } from 'lucide-react'
import { api } from '../lib/api'
import { downloadCsv } from '../lib/csv'

type Row = { paper_id: number; exam: string; session: string; class_section: string; subject: string; exam_on: string; max_marks: string; result_count: number; graded_count: number; absent_count: number; excused_count: number; average_score: string | null; average_percent: number | null; paper_sections: { title: string; max_marks: string; graded_count: number; average_score: string; average_percent: number }[] }
type Session = { id: number; name: string }
const money = (value: string, maximumFractionDigits = 1) => new Intl.NumberFormat('en-IN', { maximumFractionDigits }).format(Number(value))

export default function ExamReport({ organizationId }: { organizationId: number }) {
  const base = `/api/v1/organizations/${organizationId}`
  const [sessions, setSessions] = useState<Session[]>([])
  const [sessionId, setSessionId] = useState('')
  const [rows, setRows] = useState<Row[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    api.get<{ data: Session[] }>(`${base}/academic-sessions`).then(result => { if (!cancelled) setSessions(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load academic sessions.') })
    return () => { cancelled = true }
  }, [base])

  useEffect(() => {
    let cancelled = false
    setLoading(true); setError('')
    const query = sessionId ? `?session_id=${encodeURIComponent(sessionId)}` : ''
    api.get<{ data: Row[] }>(`${base}/reports/exams${query}`).then(result => { if (!cancelled) setRows(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load examination reports.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base, sessionId])

  const graded = rows.reduce((total, row) => total + row.graded_count, 0)
  const absences = rows.reduce((total, row) => total + row.absent_count, 0)
  const excused = rows.reduce((total, row) => total + row.excused_count, 0)

  return <section className="dash-panel dash-masters-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">SCHOOL EXAMINATIONS</span><h2>Published exam performance</h2><p className="dash-record-subtitle">Aggregate outcomes by exam, class section and subject. No student names appear in this report.</p></div><button className="dash-secondary-button" type="button" disabled={loading || !rows.length} onClick={() => downloadCsv(`exam-results${sessionId ? `-session-${sessionId}` : ''}.csv`, ['Examination', 'Session', 'Class / section', 'Subject', 'Exam date', 'Maximum marks', 'Graded average', 'Average (%)', 'Graded results', 'Absent', 'Excused', 'Total results', 'Paper section averages'], rows.map(row => [row.exam, row.session, row.class_section, row.subject, row.exam_on, row.max_marks, row.average_score, row.average_percent, row.graded_count, row.absent_count, row.excused_count, row.result_count, row.paper_sections.map(part => `${part.title}: ${part.average_score}/${part.max_marks} (${part.average_percent}%)`).join('; ')]))}><Download size={14} />Export CSV</button></div>
    <div className="report-filters"><label>Academic session<select value={sessionId} onChange={event => setSessionId(event.target.value)}><option value="">All sessions</option>{sessions.map(session => <option key={session.id} value={session.id}>{session.name}</option>)}</select></label><span>{rows.length} published papers</span></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading examination report…</p> : rows.length ? <>
      <div className="report-stat-grid"><article><small>PUBLISHED PAPERS</small><b>{rows.length}</b></article><article><small>GRADED RESULTS</small><b>{graded}</b></article><article><small>ABSENT</small><b>{absences}</b></article><article><small>EXCUSED</small><b>{excused}</b></article></div>
      <div className="dash-table-scroll attendance-table"><table><thead><tr><th>EXAMINATION</th><th>SESSION · SECTION</th><th>SUBJECT</th><th>DATE</th><th>AVERAGE</th><th>PART AVERAGES</th><th>GRADE / ABSENT / EXCUSED</th><th>RESULTS</th></tr></thead><tbody>{rows.map(row => <tr key={row.paper_id}><td>{row.exam}</td><td>{row.session} · {row.class_section}</td><td>{row.subject}</td><td>{new Date(`${row.exam_on}T00:00:00`).toLocaleDateString()}</td><td>{row.average_score === null ? '—' : `${money(row.average_score)} / ${money(row.max_marks)} (${row.average_percent}%)`}</td><td>{row.paper_sections.length ? row.paper_sections.map(part => <div key={part.title}>{part.title}: {money(part.average_score)} / {money(part.max_marks)} ({part.average_percent}%) · {part.graded_count} graded</div>) : 'Whole paper'}</td><td>{row.graded_count} / {row.absent_count} / {row.excused_count}</td><td>{row.result_count}</td></tr>)}</tbody></table></div>
    </> : <p className="master-empty"><BarChart3 size={18} />No published examination results match this session.</p>}
  </section>
}
