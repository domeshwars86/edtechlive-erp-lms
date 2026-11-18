import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, ClipboardList, LoaderCircle, Save } from 'lucide-react'
import { api } from '../lib/api'

type Course = { id: number; title: string; status: string }
type Submission = { id: number; answer: string; awarded_points: string | null; feedback: string | null; graded_at: string | null; submitted_at: string; student?: { id: number; name: string; admission_number: string } }
type Assignment = { id: number; course_id: number; title: string; instructions: string | null; due_at: string | null; max_points: string; status: 'draft' | 'published'; submission: Submission | null }

export default function AssignmentWorkspace({ organizationId, mode }: { organizationId: number; mode: 'manage' | 'student' }) {
  const base = `/api/v1/organizations/${organizationId}`
  const [courses, setCourses] = useState<Course[]>([])
  const [courseId, setCourseId] = useState<number | null>(null)
  const [assignments, setAssignments] = useState<Assignment[]>([])
  const [submissions, setSubmissions] = useState<Submission[]>([])
  const [assignmentId, setAssignmentId] = useState<number | null>(null)
  const [title, setTitle] = useState('')
  const [instructions, setInstructions] = useState('')
  const [dueAt, setDueAt] = useState('')
  const [maxPoints, setMaxPoints] = useState('100')
  const [answer, setAnswer] = useState('')
  const [scores, setScores] = useState<Record<number, string>>({})
  const [feedback, setFeedback] = useState<Record<number, string>>({})
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
  const loadAssignments = useCallback(async () => {
    if (!course) { setAssignments([]); setAssignmentId(null); return }
    const result = await api.get<{ data: Assignment[] }>(`${base}/courses/${course.id}/assignments`)
    setAssignments(result.data)
    setAssignmentId(current => result.data.some(item => item.id === current) ? current : result.data[0]?.id ?? null)
  }, [base, course])

  useEffect(() => {
    let cancelled = false
    if (!course) { setAssignments([]); return }
    api.get<{ data: Assignment[] }>(`${base}/courses/${course.id}/assignments`)
      .then(result => { if (!cancelled) { setAssignments(result.data); setAssignmentId(result.data[0]?.id ?? null) } })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load assignments.') })
    return () => { cancelled = true }
  }, [base, course])

  const selected = assignments.find(item => item.id === assignmentId) ?? null
  useEffect(() => { setAnswer(selected?.submission?.answer ?? '') }, [selected])
  useEffect(() => {
    let cancelled = false
    if (!selected || mode !== 'manage') { setSubmissions([]); return }
    api.get<{ data: Submission[] }>(`${base}/assignments/${selected.id}/submissions`)
      .then(result => { if (!cancelled) setSubmissions(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load submissions.') })
    return () => { cancelled = true }
  }, [base, mode, selected])

  async function createAssignment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!course) return
    setSaving(true); setError(''); setMessage('')
    try {
      const created = await api.post<{ data: Assignment }>(`${base}/courses/${course.id}/assignments`, {
        title, instructions: instructions || null, due_at: dueAt ? new Date(dueAt).toISOString() : null, max_points: Number(maxPoints), status: 'draft',
      })
      await api.patch(`${base}/assignments/${created.data.id}`, { status: 'published' })
      await loadAssignments(); setTitle(''); setInstructions(''); setDueAt(''); setMessage('Assignment published for enrolled students.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not create the assignment.') } finally { setSaving(false) }
  }

  async function submitAnswer(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selected) return
    setSaving(true); setError(''); setMessage('')
    try {
      await api.post(`${base}/assignments/${selected.id}/submissions`, { answer })
      setMessage('Your answer has been submitted.')
      const result = await api.get<{ data: Assignment[] }>(`${base}/courses/${selected.course_id}/assignments`)
      setAssignments(result.data)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not submit your answer.') } finally { setSaving(false) }
  }

  async function grade(submission: Submission) {
    setSaving(true); setError(''); setMessage('')
    try {
      await api.patch(`${base}/submissions/${submission.id}/grade`, { awarded_points: Number(scores[submission.id] ?? submission.awarded_points ?? 0), feedback: feedback[submission.id] ?? submission.feedback })
      const result = await api.get<{ data: Submission[] }>(`${base}/assignments/${selected?.id}/submissions`)
      setSubmissions(result.data); setMessage('Grade and feedback saved.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not save the grade.') } finally { setSaving(false) }
  }

  return <div className="assignment-workspace">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    {loading ? <section className="dash-panel dash-masters-panel"><p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading courses…</p></section> : courses.length === 0 ? <section className="dash-panel dash-masters-panel"><p className="master-empty"><ClipboardList size={18} />{mode === 'student' ? 'Enroll in a published course to see its assignments.' : 'Create a course before adding assignments.'}</p></section> : <section className="dash-panel dash-masters-panel">
      <div className="dash-panel-heading"><div><span className="dash-panel-kicker">{mode === 'student' ? 'COURSEWORK' : 'ASSESSMENT WORKSPACE'}</span><h2>{mode === 'student' ? 'My assignments' : 'Assignments and submissions'}</h2><p className="dash-record-subtitle">{mode === 'student' ? 'Submit your work and review instructor feedback.' : 'Publish assignments to enrolled students and grade submitted work.'}</p></div></div>
      <div className="assignment-course-select"><label>Course<select value={courseId ?? ''} onChange={event => setCourseId(Number(event.target.value))}>{courses.map(item => <option key={item.id} value={item.id}>{item.title}</option>)}</select></label><label>Assignment<select value={assignmentId ?? ''} onChange={event => setAssignmentId(Number(event.target.value))}>{assignments.map(item => <option key={item.id} value={item.id}>{item.title}{item.status === 'draft' ? ' · Draft' : ''}</option>)}</select></label></div>
      {mode === 'manage' && course && <form className="course-form assignment-create" onSubmit={event => void createAssignment(event)}><h3>Publish an assignment</h3>{course.status !== 'published' && <p className="dash-record-subtitle">Publish this course and enroll students before adding assignments.</p>}<label>Title<input required minLength={2} maxLength={160} value={title} onChange={event => setTitle(event.target.value)} /></label><label>Instructions<textarea rows={3} maxLength={10000} value={instructions} onChange={event => setInstructions(event.target.value)} /></label><div className="assignment-form-row"><label>Due date<input type="datetime-local" value={dueAt} onChange={event => setDueAt(event.target.value)} /></label><label>Maximum points<input type="number" min="0.01" max="100000" step="0.01" value={maxPoints} onChange={event => setMaxPoints(event.target.value)} required /></label></div><button className="master-submit" disabled={saving || course.status !== 'published'}><Save size={15} />{saving ? 'Saving…' : 'Create and publish'}</button></form>}
      {selected && <div className="assignment-detail"><div><span className={`course-status ${selected.status}`}>{selected.status}</span><h3>{selected.title}</h3><p>{selected.instructions || 'No additional instructions.'}</p><small>Maximum score: {selected.max_points}{selected.due_at ? ` · Due ${new Date(selected.due_at).toLocaleString()}` : ''}</small></div>
        {mode === 'student' && <form className="course-form assignment-answer" onSubmit={event => void submitAnswer(event)}><label>Your answer<textarea rows={7} maxLength={50000} required value={answer || selected.submission?.answer || ''} onChange={event => setAnswer(event.target.value)} placeholder="Write your response here" /></label>{selected.submission?.graded_at && <div className="assignment-grade"><b>Score: {selected.submission.awarded_points} / {selected.max_points}</b><p>{selected.submission.feedback || 'No feedback was added.'}</p></div>}<button className="master-submit" disabled={saving || Boolean(selected.submission?.graded_at)}><Save size={15} />{selected.submission ? 'Update submission' : 'Submit assignment'}</button></form>}
        {mode === 'manage' && <div className="assignment-submissions"><h3>Student submissions</h3>{submissions.length ? submissions.map(submission => <article key={submission.id} className="assignment-submission"><header><b>{submission.student?.name}</b><small>{submission.student?.admission_number} · Submitted {new Date(submission.submitted_at).toLocaleString()}</small></header><p>{submission.answer}</p><div className="assignment-grade-fields"><label>Score<input type="number" min="0" max={selected.max_points} step="0.01" value={scores[submission.id] ?? submission.awarded_points ?? ''} onChange={event => setScores(current => ({ ...current, [submission.id]: event.target.value }))} /></label><label>Feedback<textarea rows={2} value={feedback[submission.id] ?? submission.feedback ?? ''} onChange={event => setFeedback(current => ({ ...current, [submission.id]: event.target.value }))} /></label><button className="master-submit" disabled={saving} onClick={() => void grade(submission)}>Save grade</button></div></article>) : <p className="master-empty">No students have submitted this assignment yet.</p>}</div>}
      </div>}
      {assignments.length === 0 && <p className="master-empty">No assignments are available for this course yet.</p>}
    </section>}
  </div>
}
