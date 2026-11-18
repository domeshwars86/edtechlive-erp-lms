import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, ClipboardList, LoaderCircle, Play, Plus, Save } from 'lucide-react'
import { api } from '../lib/api'

type Course = { id: number; title: string }
type Question = { id: number; prompt: string; options: string[]; points: number; correct_option?: number }
type AttemptResult = { id: number; attempt_number: number; score: string | null; max_score: string | null; submitted_at: string | null; student?: { name: string; admission_number: string } | null }
type Quiz = { id: number; course_id: number; title: string; instructions: string | null; time_limit_minutes: number | null; max_attempts: number; status: 'draft' | 'published' | 'archived'; questions: Question[]; attempts_used?: number; latest_result?: AttemptResult | null }
type ActiveAttempt = { id: number; attempt_number: number }

export default function QuizWorkspace({ organizationId, mode }: { organizationId: number; mode: 'manage' | 'student' }) {
  const base = `/api/v1/organizations/${organizationId}`
  const [courses, setCourses] = useState<Course[]>([])
  const [courseId, setCourseId] = useState<number | null>(null)
  const [quizzes, setQuizzes] = useState<Quiz[]>([])
  const [quizId, setQuizId] = useState<number | null>(null)
  const [results, setResults] = useState<AttemptResult[]>([])
  const [attempt, setAttempt] = useState<ActiveAttempt | null>(null)
  const [answers, setAnswers] = useState<Record<number, number>>({})
  const [title, setTitle] = useState('')
  const [instructions, setInstructions] = useState('')
  const [timeLimit, setTimeLimit] = useState('20')
  const [maxAttempts, setMaxAttempts] = useState('1')
  const [prompt, setPrompt] = useState('')
  const [optionsText, setOptionsText] = useState('Option one\nOption two\nOption three\nOption four')
  const [correctOption, setCorrectOption] = useState('0')
  const [points, setPoints] = useState('1')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  useEffect(() => {
    let cancelled = false
    api.get<{ data: Course[] }>(`${base}/courses?per_page=100`)
      .then(result => { if (!cancelled) { setCourses(result.data); setCourseId(result.data[0]?.id ?? null) } })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load courses.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base])

  const course = courses.find(item => item.id === courseId) ?? null
  const loadQuizzes = useCallback(async () => {
    if (!course) { setQuizzes([]); setQuizId(null); return }
    const result = await api.get<{ data: Quiz[] }>(`${base}/courses/${course.id}/quizzes`)
    setQuizzes(result.data)
    setQuizId(current => result.data.some(item => item.id === current) ? current : result.data[0]?.id ?? null)
  }, [base, course])

  useEffect(() => {
    let cancelled = false
    if (!course) { setQuizzes([]); return }
    api.get<{ data: Quiz[] }>(`${base}/courses/${course.id}/quizzes`)
      .then(result => { if (!cancelled) { setQuizzes(result.data); setQuizId(result.data[0]?.id ?? null); setAttempt(null) } })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load quizzes.') })
    return () => { cancelled = true }
  }, [base, course])

  const quiz = quizzes.find(item => item.id === quizId) ?? null
  useEffect(() => {
    let cancelled = false
    if (!quiz || mode !== 'manage') { setResults([]); return }
    api.get<{ data: AttemptResult[] }>(`${base}/quizzes/${quiz.id}/attempts`)
      .then(result => { if (!cancelled) setResults(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load quiz results.') })
    return () => { cancelled = true }
  }, [base, mode, quiz])

  async function createQuiz(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!course) return
    setSaving(true); setError(''); setMessage('')
    try {
      const created = await api.post<{ data: Quiz }>(`${base}/courses/${course.id}/quizzes`, { title, instructions: instructions || null, time_limit_minutes: timeLimit ? Number(timeLimit) : null, max_attempts: Number(maxAttempts) })
      await loadQuizzes(); setQuizId(created.data.id); setTitle(''); setInstructions(''); setMessage('Quiz draft created.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not create quiz.') } finally { setSaving(false) }
  }

  async function addQuestion(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!quiz) return
    const options = optionsText.split('\n').map(option => option.trim()).filter(Boolean)
    setSaving(true); setError(''); setMessage('')
    try {
      await api.post(`${base}/quizzes/${quiz.id}/questions`, { prompt, options, correct_option: Number(correctOption), points: Number(points), sort_order: quiz.questions.length })
      await loadQuizzes(); setPrompt(''); setMessage('Question saved to the draft quiz.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not save question.') } finally { setSaving(false) }
  }

  async function publishQuiz() {
    if (!quiz) return
    setSaving(true); setError(''); setMessage('')
    try {
      await api.patch(`${base}/quizzes/${quiz.id}`, { status: 'published' })
      await loadQuizzes(); setMessage('Quiz published. Students can now start an attempt.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not publish quiz.') } finally { setSaving(false) }
  }

  async function startAttempt() {
    if (!quiz) return
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.post<{ data: { attempt: ActiveAttempt } }>(`${base}/quizzes/${quiz.id}/attempts`)
      setAttempt(result.data.attempt); setAnswers({}); setMessage('Attempt started. Your answers are scored when submitted.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not start quiz.') } finally { setSaving(false) }
  }

  async function submitAttempt(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!quiz || !attempt) return
    setSaving(true); setError(''); setMessage('')
    try {
      const payload = Object.entries(answers).map(([question_id, selected_option]) => ({ question_id: Number(question_id), selected_option }))
      const result = await api.patch<{ data: AttemptResult }>(`${base}/quizzes/${quiz.id}/attempts/${attempt.id}`, { answers: payload })
      setAttempt(null); setAnswers({}); await loadQuizzes(); setMessage(`Attempt submitted. Score: ${result.data.score} / ${result.data.max_score}.`)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not submit quiz attempt.') } finally { setSaving(false) }
  }

  return <div className="quiz-workspace">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    {loading ? <section className="dash-panel dash-masters-panel"><p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading courses…</p></section> : courses.length === 0 ? <section className="dash-panel dash-masters-panel"><p className="master-empty"><ClipboardList size={18} />{mode === 'student' ? 'Enroll in a published course to see its quizzes.' : 'Create and publish a course before adding quizzes.'}</p></section> : <section className="dash-panel dash-masters-panel">
      <div className="dash-panel-heading"><div><span className="dash-panel-kicker">{mode === 'student' ? 'KNOWLEDGE CHECKS' : 'ASSESSMENT BUILDER'}</span><h2>{mode === 'student' ? 'Tests and quizzes' : 'Course quizzes'}</h2><p className="dash-record-subtitle">{mode === 'student' ? 'Start a quiz attempt and check your latest score.' : 'Build multiple-choice quizzes with server-scored attempts.'}</p></div></div>
      <div className="assignment-course-select"><label>Course<select value={courseId ?? ''} onChange={event => { setCourseId(Number(event.target.value)); setAttempt(null) }}>{courses.map(item => <option key={item.id} value={item.id}>{item.title}</option>)}</select></label>{quizzes.length > 0 && <label>Quiz<select value={quizId ?? ''} onChange={event => { setQuizId(Number(event.target.value)); setAttempt(null) }}>{quizzes.map(item => <option key={item.id} value={item.id}>{item.title}{item.status === 'draft' ? ' · Draft' : ''}</option>)}</select></label>}</div>
      {mode === 'manage' && course && <form className="course-form quiz-create" onSubmit={event => void createQuiz(event)}><h3>Create a quiz draft</h3><label>Title<input required minLength={2} maxLength={160} value={title} onChange={event => setTitle(event.target.value)} /></label><label>Instructions<textarea rows={2} maxLength={5000} value={instructions} onChange={event => setInstructions(event.target.value)} /></label><div className="assignment-form-row"><label>Time limit in minutes<input type="number" min="1" max="240" value={timeLimit} onChange={event => setTimeLimit(event.target.value)} /></label><label>Allowed attempts<input type="number" min="1" max="5" value={maxAttempts} onChange={event => setMaxAttempts(event.target.value)} required /></label></div><button className="master-submit" disabled={saving}><Plus size={15} />Create draft quiz</button></form>}
      {quiz && <div className="quiz-detail"><div className="course-detail-heading"><div><span className={`course-status ${quiz.status}`}>{quiz.status}</span><h3>{quiz.title}</h3><p>{quiz.instructions || 'No extra instructions.'}{quiz.time_limit_minutes ? ` · ${quiz.time_limit_minutes} minute time limit` : ''} · {quiz.max_attempts} attempt{quiz.max_attempts === 1 ? '' : 's'}</p></div>{mode === 'manage' && quiz.status === 'draft' && <button className="master-submit" onClick={() => void publishQuiz()} disabled={saving || quiz.questions.length === 0}>Publish quiz</button>}</div>
        {mode === 'manage' && quiz.status === 'draft' && <form className="course-form quiz-question-form" onSubmit={event => void addQuestion(event)}><h4>Add a multiple-choice question</h4><label>Prompt<input required minLength={3} maxLength={2000} value={prompt} onChange={event => setPrompt(event.target.value)} /></label><label>Answer choices, one per line<textarea rows={4} value={optionsText} onChange={event => setOptionsText(event.target.value)} /></label><div className="assignment-form-row"><label>Correct answer number<input type="number" min="1" max="6" value={Number(correctOption) + 1} onChange={event => setCorrectOption(String(Number(event.target.value) - 1))} /></label><label>Points<input type="number" min="1" max="1000" value={points} onChange={event => setPoints(event.target.value)} /></label></div><button className="master-submit" disabled={saving}><Plus size={15} />Add question</button></form>}
        {mode === 'manage' && quiz.questions.length > 0 && <div className="quiz-question-list"><h4>{quiz.questions.length} question{quiz.questions.length === 1 ? '' : 's'}</h4>{quiz.questions.map((question, index) => <article key={question.id}><b>{index + 1}. {question.prompt}</b><ol>{question.options.map((option, optionIndex) => <li key={`${question.id}-${optionIndex}`}>{option}{optionIndex === question.correct_option && <strong> · Answer</strong>}</li>)}</ol></article>)}</div>}
        {mode === 'student' && !attempt && <div className="quiz-start"><p>{quiz.latest_result?.submitted_at ? `Latest result: ${quiz.latest_result.score} / ${quiz.latest_result.max_score}` : `Attempts used: ${quiz.attempts_used ?? 0} / ${quiz.max_attempts}`}</p><button className="master-submit" onClick={() => void startAttempt()} disabled={saving || (quiz.attempts_used ?? 0) >= quiz.max_attempts}><Play size={15} />Start attempt</button></div>}
        {mode === 'student' && attempt && <form className="quiz-attempt-form" onSubmit={event => void submitAttempt(event)}><p>Attempt {attempt.attempt_number}{quiz.time_limit_minutes ? ` · Time limit ${quiz.time_limit_minutes} minutes` : ''}</p>{quiz.questions.map((question, index) => <fieldset key={question.id}><legend>{index + 1}. {question.prompt} <small>({question.points} pts)</small></legend>{question.options.map((option, optionIndex) => <label key={`${question.id}-${optionIndex}`}><input type="radio" name={`question-${question.id}`} checked={answers[question.id] === optionIndex} onChange={() => setAnswers(current => ({ ...current, [question.id]: optionIndex }))} />{option}</label>)}</fieldset>)}<button className="master-submit" disabled={saving}><Save size={15} />Submit answers</button></form>}
        {mode === 'manage' && quiz.status === 'published' && <div className="quiz-results"><h4>Submitted results</h4>{results.length ? <div className="dash-table-scroll"><table><thead><tr><th>STUDENT</th><th>ATTEMPT</th><th>SCORE</th><th>SUBMITTED</th></tr></thead><tbody>{results.map(result => <tr key={result.id}><td>{result.student?.name} · {result.student?.admission_number}</td><td>{result.attempt_number}</td><td>{result.score} / {result.max_score}</td><td>{result.submitted_at ? new Date(result.submitted_at).toLocaleString() : '—'}</td></tr>)}</tbody></table></div> : <p className="master-empty">No quiz attempts have been submitted.</p>}</div>}
      </div>}
    </section>}
  </div>
}
