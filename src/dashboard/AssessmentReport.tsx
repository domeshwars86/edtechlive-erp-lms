import { useEffect, useState } from 'react'
import { AlertCircle, BarChart3, Download, LoaderCircle } from 'lucide-react'
import { api } from '../lib/api'
import { downloadCsv } from '../lib/csv'

type QuizResult = { quiz_id: number; course: string; quiz: string; submitted_attempts: number; in_progress_attempts: number; learners: number; average_score_percent: number | null }
type AssignmentResult = { assignment_id: number; course: string; assignment: string; learners: number; submissions: number; graded_submissions: number; awaiting_review: number; average_score_percent: number | null }
type Report = { summary: { published_quizzes: number; submitted_attempts: number; in_progress_attempts: number; learner_quiz_participations: number; average_score_percent: number | null; quizzes_with_submissions: number }; quizzes: QuizResult[]; assignment_summary: { published_assignments: number; submissions: number; graded_submissions: number; awaiting_review: number; learner_submissions: number; average_score_percent: number | null }; assignments: AssignmentResult[] }

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
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">ASSESSMENT REPORTS</span><h2>Quiz results overview</h2><p className="dash-record-subtitle">Submitted score averages across published course quizzes. Student identities are not included.</p></div><button className="dash-secondary-button" type="button" disabled={loading || !report} onClick={() => report && downloadCsv('assessment-outcomes.csv', ['Record type', 'Course', 'Assessment', 'Learners', 'Submissions', 'In progress', 'Awaiting review', 'Average score (%)'], [...report.quizzes.map(quiz => ['Quiz', quiz.course, quiz.quiz, quiz.learners, quiz.submitted_attempts, quiz.in_progress_attempts, '', quiz.average_score_percent]), ...report.assignments.map(assignment => ['Assignment', assignment.course, assignment.assignment, assignment.learners, assignment.submissions, '', assignment.awaiting_review, assignment.average_score_percent])])}><Download size={14} />Export CSV</button></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading assessment results…</p> : report && <>
      <div className="report-stat-grid"><article><small>PUBLISHED QUIZZES</small><b>{report.summary.published_quizzes}</b></article><article><small>SUBMITTED ATTEMPTS</small><b>{report.summary.submitted_attempts}</b></article><article><small>IN PROGRESS</small><b>{report.summary.in_progress_attempts}</b></article><article><small>LEARNER PARTICIPATIONS</small><b>{report.summary.learner_quiz_participations}</b></article><article><small>AVERAGE SCORE</small><b>{report.summary.average_score_percent === null ? '—' : `${report.summary.average_score_percent}%`}</b></article></div>
      {report.quizzes.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>COURSE</th><th>QUIZ</th><th>LEARNERS</th><th>SUBMITTED</th><th>IN PROGRESS</th><th>AVERAGE SCORE</th></tr></thead><tbody>{report.quizzes.map(quiz => <tr key={quiz.quiz_id}><td><b>{quiz.course}</b></td><td>{quiz.quiz}</td><td>{quiz.learners}</td><td>{quiz.submitted_attempts}</td><td>{quiz.in_progress_attempts}</td><td>{quiz.average_score_percent === null ? '—' : <span className="report-rate">{quiz.average_score_percent}%</span>}</td></tr>)}</tbody></table></div> : <p className="master-empty"><BarChart3 size={18} />No published course quizzes are available.</p>}
      <div className="dash-panel-heading assessment-subheading"><div><span className="dash-panel-kicker">ASSIGNMENT REVIEW</span><h2>Assignment results</h2><p className="dash-record-subtitle">Submission and grading progress for published course assignments.</p></div></div>
      <div className="report-stat-grid"><article><small>PUBLISHED ASSIGNMENTS</small><b>{report.assignment_summary.published_assignments}</b></article><article><small>SUBMISSIONS</small><b>{report.assignment_summary.submissions}</b></article><article><small>GRADED</small><b>{report.assignment_summary.graded_submissions}</b></article><article><small>AWAITING REVIEW</small><b>{report.assignment_summary.awaiting_review}</b></article><article><small>AVERAGE GRADED SCORE</small><b>{report.assignment_summary.average_score_percent === null ? '—' : `${report.assignment_summary.average_score_percent}%`}</b></article></div>
      {report.assignments.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>COURSE</th><th>ASSIGNMENT</th><th>LEARNERS</th><th>SUBMISSIONS</th><th>AWAITING REVIEW</th><th>AVERAGE SCORE</th></tr></thead><tbody>{report.assignments.map(assignment => <tr key={assignment.assignment_id}><td><b>{assignment.course}</b></td><td>{assignment.assignment}</td><td>{assignment.learners}</td><td>{assignment.submissions}</td><td>{assignment.awaiting_review}</td><td>{assignment.average_score_percent === null ? '—' : <span className="report-rate">{assignment.average_score_percent}%</span>}</td></tr>)}</tbody></table></div> : <p className="master-empty"><BarChart3 size={18} />No published assignments are available.</p>}
    </>}
  </section>
}
