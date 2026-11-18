import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, LoaderCircle, Plus, RefreshCw, Trash2, UsersRound } from 'lucide-react'
import { api } from '../lib/api'

type Named = { id: number; name: string; is_current?: boolean; is_active?: boolean; status?: string }
type Section = { id: number; name: string; academic_session_id: number | null; academic_class?: Named }
type Assignment = { id: number; academic_section: Section; subject: Named; teacher: Named }
type Result<T> = { data: T[] }

export default function TeachingAssignments({ organizationId }: { organizationId: number }) {
  const base = `/api/v1/organizations/${organizationId}`
  const [sessions, setSessions] = useState<Named[]>([])
  const [sections, setSections] = useState<Section[]>([])
  const [subjects, setSubjects] = useState<Named[]>([])
  const [teachers, setTeachers] = useState<Named[]>([])
  const [assignments, setAssignments] = useState<Assignment[]>([])
  const [sessionId, setSessionId] = useState('')
  const [fields, setFields] = useState({ section_id: '', subject_id: '', teacher_id: '' })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const loadAssignments = useCallback(async () => {
    if (!sessionId) { setAssignments([]); return }
    try {
      const result = await api.get<Result<Assignment>>(`${base}/teaching-assignments?academic_session_id=${sessionId}&per_page=100`)
      setAssignments(result.data)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not load teaching assignments.') }
  }, [base, sessionId])

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    void Promise.all([
      api.get<Result<Named>>(`${base}/academic-sessions?per_page=100`),
      api.get<Result<Named>>(`${base}/classes?per_page=100`),
      api.get<Result<Named>>(`${base}/subjects?per_page=100`),
      api.get<Result<Named>>(`${base}/teachers?per_page=100`),
    ]).then(async ([sessionResult, classResult, subjectResult, teacherResult]) => {
      const sectionResults = await Promise.all(classResult.data.map(async item => {
        const result = await api.get<Result<Section>>(`${base}/classes/${item.id}/sections?per_page=100`)
        return result.data.map(section => ({ ...section, academic_class: item }))
      }))
      if (cancelled) return
      setSessions(sessionResult.data.filter(session => session.is_active !== false))
      setSections(sectionResults.flat())
      setSubjects(subjectResult.data.filter(subject => subject.is_active !== false))
      setTeachers(teacherResult.data.filter(teacher => teacher.status === 'active'))
      setSessionId(current => current || String(sessionResult.data.find(session => session.is_current)?.id ?? sessionResult.data.find(session => session.is_active !== false)?.id ?? ''))
    }).catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load assignment setup.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base])

  useEffect(() => { void loadAssignments() }, [loadAssignments])

  async function createAssignment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setSaving(true); setError(''); setMessage('')
    try {
      await api.post(`${base}/teaching-assignments`, { academic_session_id: Number(sessionId), academic_section_id: Number(fields.section_id), subject_id: Number(fields.subject_id), teacher_id: Number(fields.teacher_id) })
      setFields({ section_id: '', subject_id: '', teacher_id: '' }); setMessage('Teaching assignment saved.'); await loadAssignments()
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not save this assignment.') }
    finally { setSaving(false) }
  }

  async function removeAssignment(id: number) {
    setError(''); setMessage('')
    try { await api.delete(`${base}/teaching-assignments/${id}`); setMessage('Teaching assignment removed.'); await loadAssignments() }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not remove this assignment.') }
  }

  return <section className="dash-panel dash-masters-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">STAFFING PLAN</span><h2>Teaching assignments</h2><p className="dash-record-subtitle">Assign active teachers to a subject in a section for an academic session.</p></div><button className="dash-icon-button" onClick={() => void loadAssignments()} aria-label="Refresh assignments"><RefreshCw size={16} /></button></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    <div className="timetable-toolbar"><label>Academic session<select value={sessionId} onChange={event => setSessionId(event.target.value)}><option value="">Select a session</option>{sessions.map(session => <option key={session.id} value={session.id}>{session.name}{session.is_current ? ' · Current' : ''}</option>)}</select></label><span>{assignments.length} assignment{assignments.length === 1 ? '' : 's'}</span></div>
    <div className="master-content">
      <form className="master-form" onSubmit={createAssignment}><h3><Plus size={17} />Assign teacher</h3>
        <label>Class section<select required value={fields.section_id} onChange={event => setFields(current => ({ ...current, section_id: event.target.value }))}><option value="">Select section</option>{sections.filter(section => String(section.academic_session_id) === sessionId).map(section => <option key={section.id} value={section.id}>{section.academic_class?.name} · {section.name}</option>)}</select></label>
        <label>Subject<select required value={fields.subject_id} onChange={event => setFields(current => ({ ...current, subject_id: event.target.value }))}><option value="">Select subject</option>{subjects.map(subject => <option key={subject.id} value={subject.id}>{subject.name}</option>)}</select></label>
        <label>Teacher<select required value={fields.teacher_id} onChange={event => setFields(current => ({ ...current, teacher_id: event.target.value }))}><option value="">Select teacher</option>{teachers.map(teacher => <option key={teacher.id} value={teacher.id}>{teacher.name}</option>)}</select></label>
        <button className="master-submit" disabled={saving || loading || !sessionId}>{saving ? <><LoaderCircle className="master-spin" size={16} />Saving…</> : <><Plus size={16} />Save assignment</>}</button>
      </form>
      <div className="master-list"><div className="master-list-heading"><div><h3>Assigned teachers</h3><span>{sessionId ? sessions.find(item => String(item.id) === sessionId)?.name : 'Choose an academic session'}</span></div></div>
        {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading assignment setup…</p> : assignments.length === 0 ? <p className="master-empty"><UsersRound size={18} />No teaching assignments yet.</p> : <ul>{assignments.map(assignment => <li key={assignment.id}><span className="master-record-icon"><UsersRound size={17} /></span><span className="master-record-main"><b>{assignment.teacher.name} · {assignment.subject.name}</b><small>{assignment.academic_section.academic_class?.name} · Section {assignment.academic_section.name}</small></span><button className="master-record-action" onClick={() => void removeAssignment(assignment.id)} aria-label={`Remove ${assignment.teacher.name} assignment`}><Trash2 size={15} /></button></li>)}</ul>}
      </div>
    </div>
  </section>
}
