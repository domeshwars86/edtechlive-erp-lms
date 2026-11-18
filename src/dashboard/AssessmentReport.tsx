import { useEffect, useState } from 'react'
import { AlertCircle, BarChart3, LoaderCircle } from 'lucide-react'
import { api } from '../lib/api'

type QuizResult = { quiz_id: number; course: string; quiz: string; submitted_attempts: number; in_progress_attempts: number; learners: number; average_score_percent: number | null }
type Report = { summary: { published_quizzes: number; submitted_attempts: number; in_progress_attempts: number; learner_quiz_participations: number; average_score_percent: number | null; quizzes_with_submissions: number }; quizzes: QuizResult[] }

export default function AssessmentReport({ organizationId }: { organizationId: number }) {
  const [report, setReport] = useState<Report | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    setLoading(true); setError('')
    api.get<{ data: Report }>(`/api/v1/organizations/${organizationId}/reports/assessments`)
      .then(result => { if (!cancelled) setReport(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load assessment results.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [organizationId])

  return <section className="dash-panel dash-masters-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">ASSESSMENT REPORTS</span><h2>Quiz results overview</h2><p className="dash-record-subtitle">Submitted score averages across published course quizzes. Student identities are not included.</p></div></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading assessment results…</p> : report && <>
      <div className="report-stat-grid"><article><small>PUBLISHED QUIZZES</small><b>{report.summary.published_quizzes}</b></article><article><small>SUBMITTED ATTEMPTS</small><b>{report.summary.submitted_attempts}</b></article><article><small>IN PROGRESS</small><b>{report.summary.in_progress_attempts}</b></article><article><small>LEARNER PARTICIPATIONS</small><b>{report.summary.learner_quiz_participations}</b></article><article><small>AVERAGE SCORE</small><b>{report.summary.average_score_percent === null ? '—' : `${report.summary.average_score_percent}%`}</b></article></div>
      {report.quizzes.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>COURSE</th><th>QUIZ</th><th>LEARNERS</th><th>SUBMITTED</th><th>IN PROGRESS</th><th>AVERAGE SCORE</th></tr></thead><tbody>{report.quizzes.map(quiz => <tr key={quiz.quiz_id}><td><b>{quiz.course}</b></td><td>{quiz.quiz}</td><td>{quiz.learners}</td><td>{quiz.submitted_attempts}</td><td>{quiz.in_progress_attempts}</td><td>{quiz.average_score_percent === null ? '—' : <span className="report-rate">{quiz.average_score_percent}%</span>}</td></tr>)}</tbody></table></div> : <p className="master-empty"><BarChart3 size={18} />No published course quizzes are available.</p>}
    </>}
  </section>
}
