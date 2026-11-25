import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, CalendarDays, Check, Clock3, ExternalLink, LoaderCircle, Pencil, Plus, RefreshCw, X } from 'lucide-react'
import { api } from '../lib/api'

type NamedRecord = { id: number; name: string; is_current?: boolean; is_active?: boolean; status?: string }
type Section = { id: number; name: string; academic_session_id: number | null; is_active?: boolean; academic_class?: { id: number; name: string } }
type Entry = {
  id: number
  academic_session_id: number
  academic_section_id: number
  weekday: number
  starts_at: string
  ends_at: string
  room: string | null
  meeting_url: string | null
  is_active: boolean
  academic_section: { id: number; name: string; academic_class: { id: number; name: string } }
  subject: { id: number; name: string }
  teacher: { id: number; name: string } | null
}
type Response<T> = { data: T[]; meta?: { total: number } }

const weekdayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

export default function TimetableManager({ organizationId }: { organizationId: number }) {
  const base = `/api/v1/organizations/${organizationId}`
  const [sessions, setSessions] = useState<NamedRecord[]>([])
  const [sections, setSections] = useState<Section[]>([])
  const [subjects, setSubjects] = useState<NamedRecord[]>([])
  const [teachers, setTeachers] = useState<NamedRecord[]>([])
  const [entries, setEntries] = useState<Entry[]>([])
  const [sessionId, setSessionId] = useState('')
  const [editingEntry, setEditingEntry] = useState<Entry | null>(null)
  const [fields, setFields] = useState<Record<string, string>>({ weekday: '0', starts_at: '09:00', ends_at: '10:00' })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const loadEntries = useCallback(async () => {
    if (!sessionId) {
      setEntries([])
      return
    }
    try {
      const result = await api.get<Response<Entry>>(`${base}/timetable?academic_session_id=${sessionId}&per_page=100`)
      setEntries(result.data)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not load the timetable.')
    }
  }, [base, sessionId])

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    void Promise.all([
      api.get<Response<NamedRecord>>(`${base}/academic-sessions?per_page=100`),
      api.get<Response<NamedRecord>>(`${base}/classes?per_page=100`),
      api.get<Response<NamedRecord>>(`${base}/subjects?per_page=100`),
      api.get<Response<NamedRecord>>(`${base}/teachers?per_page=100`),
    ]).then(async ([sessionResult, classResult, subjectResult, teacherResult]) => {
      const sectionLists = await Promise.all(classResult.data.filter(item => item.is_active !== false).map(async item => {
        const result = await api.get<Response<Section>>(`${base}/classes/${item.id}/sections?per_page=100`)
        return result.data.filter(section => section.is_active !== false).map(section => ({ ...section, academic_class: { id: item.id, name: item.name } }))
      }))
      if (!cancelled) {
        setSessions(sessionResult.data.filter(session => session.is_active !== false))
          setSections(sectionLists.flat().filter(section => section.academic_session_id !== null))
        setSubjects(subjectResult.data)
        setTeachers(teacherResult.data.filter(teacher => teacher.status === undefined || teacher.status === 'active'))
        setSessionId(current => current || String(sessionResult.data.find(session => session.is_current)?.id ?? sessionResult.data.find(session => session.is_active !== false)?.id ?? ''))
      }
    }).catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load timetable setup.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base])

  useEffect(() => { void loadEntries() }, [loadEntries])

  function updateField(name: string, value: string) {
    setFields(current => ({ ...current, [name]: value }))
  }

  function resetForm() {
    setEditingEntry(null)
    setFields({ weekday: '0', starts_at: '09:00', ends_at: '10:00' })
    setError('')
    setMessage('')
  }

  function beginEdit(entry: Entry) {
    setEditingEntry(entry)
    setFields({
      section_id: String(entry.academic_section_id), subject_id: String(entry.subject.id),
      teacher_id: entry.teacher ? String(entry.teacher.id) : '', weekday: String(entry.weekday),
      starts_at: entry.starts_at.slice(0, 5), ends_at: entry.ends_at.slice(0, 5),
      room: entry.room ?? '', meeting_url: entry.meeting_url ?? '',
    })
    setError('')
    setMessage('')
  }

  async function saveEntry(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setError('')
    setMessage('')
    try {
      const payload = {
        academic_session_id: Number(sessionId), academic_section_id: Number(fields.section_id),
        subject_id: Number(fields.subject_id), teacher_id: fields.teacher_id ? Number(fields.teacher_id) : null,
        weekday: Number(fields.weekday), starts_at: fields.starts_at, ends_at: fields.ends_at,
        room: fields.room || null, meeting_url: fields.meeting_url || null,
      }
      if (editingEntry) {
        await api.patch(`${base}/timetable/${editingEntry.id}`, payload)
        setMessage('Timetable entry updated.')
      } else {
        await api.post(`${base}/timetable`, payload)
        setMessage('Timetable entry saved.')
      }
      setEditingEntry(null)
      setFields({ weekday: '0', starts_at: '09:00', ends_at: '10:00' })
      await loadEntries()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not save this timetable entry.')
    } finally {
      setSaving(false)
    }
  }

  async function toggleActive(entry: Entry) {
    setError('')
    try {
      await api.patch(`${base}/timetable/${entry.id}`, { is_active: !entry.is_active })
      setMessage(`Timetable entry ${entry.is_active ? 'deactivated' : 'activated'}.`)
      await loadEntries()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not update this timetable entry.')
    }
  }

  return <section className="dash-panel dash-masters-panel timetable-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">WEEKLY CLASS PLAN</span><h2>Timetable</h2><p className="dash-record-subtitle">Schedule sections and subjects, assign a teacher, and add an optional secure meeting link.</p></div><button className="dash-icon-button" onClick={() => void loadEntries()} aria-label="Refresh timetable"><RefreshCw size={16} /></button></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    <div className="timetable-toolbar"><label>Academic session<select value={sessionId} onChange={event => { resetForm(); setSessionId(event.target.value) }}><option value="">Select a session</option>{sessions.map(session => <option key={session.id} value={session.id}>{session.name}{session.is_current ? ' · Current' : ''}</option>)}</select></label><span>{entries.length} schedule entr{entries.length === 1 ? 'y' : 'ies'}</span></div>
    <div className="master-content timetable-content">
      <form className="master-form" onSubmit={saveEntry}>
        <h3>{editingEntry ? <><Pencil size={17} />Edit class time</> : <><Plus size={17} />Add class time</>}</h3>
        <label>Class section<select required value={fields.section_id ?? ''} onChange={event => updateField('section_id', event.target.value)}><option value="">Select section</option>{editingEntry && !sections.some(section => String(section.id) === fields.section_id) && <option value={fields.section_id}>{editingEntry.academic_section.academic_class.name} · {editingEntry.academic_section.name} (inactive)</option>}{sections.filter(section => String(section.academic_session_id) === sessionId).map(section => <option key={section.id} value={section.id}>{section.academic_class?.name} · {section.name}</option>)}</select></label>
        <label>Subject<select required value={fields.subject_id ?? ''} onChange={event => updateField('subject_id', event.target.value)}><option value="">Select subject</option>{editingEntry && !subjects.some(subject => String(subject.id) === fields.subject_id) && <option value={fields.subject_id}>{editingEntry.subject.name} (inactive)</option>}{subjects.filter(subject => subject.is_active !== false).map(subject => <option key={subject.id} value={subject.id}>{subject.name}</option>)}</select></label>
        <label>Teacher <span>(optional)</span><select value={fields.teacher_id ?? ''} onChange={event => updateField('teacher_id', event.target.value)}><option value="">No teacher assigned</option>{editingEntry?.teacher && !teachers.some(teacher => String(teacher.id) === fields.teacher_id) && <option value={fields.teacher_id}>{editingEntry.teacher.name} (inactive)</option>}{teachers.map(teacher => <option key={teacher.id} value={teacher.id}>{teacher.name}</option>)}</select></label>
        <label>Weekday<select value={fields.weekday ?? '0'} onChange={event => updateField('weekday', event.target.value)}>{weekdayNames.map((name, index) => <option key={name} value={index}>{name}</option>)}</select></label>
        <div className="master-form-row"><label>Starts<input type="time" required value={fields.starts_at ?? '09:00'} onChange={event => updateField('starts_at', event.target.value)} /></label><label>Ends<input type="time" required value={fields.ends_at ?? '10:00'} onChange={event => updateField('ends_at', event.target.value)} /></label></div>
        <label>Room <span>(optional)</span><input maxLength={120} value={fields.room ?? ''} onChange={event => updateField('room', event.target.value)} placeholder="Room 2" /></label>
        <label>Meeting link <span>(HTTPS link, optional)</span><input type="url" maxLength={2048} value={fields.meeting_url ?? ''} onChange={event => updateField('meeting_url', event.target.value)} placeholder="https://meet.google.com/..." /></label>
        <div className="master-form-actions"><button className="master-submit" disabled={saving || loading || !sessionId}>{saving ? <><LoaderCircle className="master-spin" size={16} />Saving…</> : editingEntry ? <><Check size={16} />Save changes</> : <><Plus size={16} />Save schedule</>}</button>{editingEntry && <button className="master-record-action" type="button" disabled={saving} onClick={resetForm}><X size={15} />Cancel edit</button>}</div>
      </form>
      <div className="master-list timetable-list"><div className="master-list-heading"><div><h3>Weekly schedule</h3><span>{sessionId ? sessions.find(item => String(item.id) === sessionId)?.name : 'Choose an academic session'}</span></div></div>
        {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading timetable setup…</p> : entries.length === 0 ? <p className="master-empty"><CalendarDays size={18} />No timetable entries yet. Add the first class time.</p> : <ul>{entries.map(entry => <li key={entry.id} className={!entry.is_active ? 'timetable-inactive' : ''}><span className="master-record-icon"><Clock3 size={17} /></span><span className="master-record-main"><b>{weekdayNames[entry.weekday]} · {entry.starts_at.slice(0, 5)}–{entry.ends_at.slice(0, 5)} · {entry.subject.name}</b><small>{entry.academic_section.academic_class.name} · Section {entry.academic_section.name}{entry.teacher ? ` · ${entry.teacher.name}` : ''}{entry.room ? ` · ${entry.room}` : ''}{entry.meeting_url ? ' · Meeting link' : ''}</small></span>{entry.meeting_url && <a className="master-record-action" href={entry.meeting_url} target="_blank" rel="noopener noreferrer" aria-label="Open meeting link"><ExternalLink size={14} /></a>}<button className="master-record-action" type="button" aria-label={`Edit ${weekdayNames[entry.weekday]} ${entry.subject.name} timetable entry`} onClick={() => beginEdit(entry)}>Edit</button><button className="master-record-action" type="button" onClick={() => void toggleActive(entry)}>{entry.is_active ? 'Deactivate' : 'Activate'}</button></li>)}</ul>}
      </div>
    </div>
  </section>
}
