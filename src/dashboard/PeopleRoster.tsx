import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, GraduationCap, LoaderCircle, Plus, RefreshCw, Search, UsersRound } from 'lucide-react'
import { api, ApiError } from '../lib/api'

type Person = {
  id: number
  name: string
  admission_number?: string
  employee_number?: string | null
  email?: string | null
  phone?: string | null
  specialization?: string | null
  status: string
  academic_class?: { id: number; name: string } | null
  academic_section?: { id: number; name: string } | null
  academic_session?: { id: number; name: string } | null
}
type RosterResponse = { data: Person[]; meta: { total: number } }
type MasterList = { data: { id: number; name: string; academic_session_id?: number | null }[] }
type Props = { organizationId: number; kind: 'students' | 'teachers' }

export default function PeopleRoster({ organizationId, kind }: Props) {
  const [records, setRecords] = useState<Person[]>([])
  const [classes, setClasses] = useState<MasterList['data']>([])
  const [sessions, setSessions] = useState<MasterList['data']>([])
  const [sections, setSections] = useState<MasterList['data']>([])
  const [fields, setFields] = useState<Record<string, string>>({})
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const base = `/api/v1/organizations/${organizationId}/${kind}`
  const isStudent = kind === 'students'
  const organizationBase = `/api/v1/organizations/${organizationId}`

  useEffect(() => {
    if (!isStudent) return
    void Promise.all([
      api.get<MasterList>(`${organizationBase}/classes`),
      api.get<MasterList>(`${organizationBase}/academic-sessions`),
    ]).then(([classResult, sessionResult]) => {
      setClasses(classResult.data)
      setSessions(sessionResult.data)
    }).catch(reason => setError(reason instanceof Error ? reason.message : 'Could not load placement options.'))
  }, [isStudent, organizationBase])

  useEffect(() => {
    if (!isStudent || !fields.academic_class_id) {
      setSections([])
      return
    }
    let cancelled = false
    void api.get<MasterList>(`${organizationBase}/classes/${fields.academic_class_id}/sections`)
      .then(result => { if (!cancelled) setSections(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load section options.') })
    return () => { cancelled = true }
  }, [fields.academic_class_id, isStudent, organizationBase])

  const load = useCallback(async (query: string) => {
    setLoading(true)
    setError('')
    try {
      const result = await api.get<RosterResponse>(`${base}${query ? `?search=${encodeURIComponent(query)}` : ''}`)
      setRecords(result.data)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not load this roster.')
    } finally {
      setLoading(false)
    }
  }, [base])

  useEffect(() => { void load('') }, [load])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setError('')
    setMessage('')
    const body = isStudent
      ? {
          name: fields.name, admission_number: fields.identifier, email: fields.email || null,
          phone: fields.phone || null, enrolled_on: fields.enrolled_on || null,
          academic_class_id: fields.academic_class_id ? Number(fields.academic_class_id) : null,
          academic_section_id: fields.academic_section_id ? Number(fields.academic_section_id) : null,
          academic_session_id: fields.academic_session_id ? Number(fields.academic_session_id) : null,
        }
      : {
          name: fields.name, employee_number: fields.identifier || null, email: fields.email || null,
          phone: fields.phone || null, specialization: fields.specialization || null,
        }
    try {
      await api.post(base, body)
      setFields({})
      setMessage(`${isStudent ? 'Student' : 'Teacher'} added to the organization roster.`)
      await load('')
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : reason instanceof Error ? reason.message : 'Could not save the record.')
    } finally {
      setSaving(false)
    }
  }

  async function toggleStatus(person: Person) {
    setError('')
    try {
      await api.patch(`${base}/${person.id}`, { status: person.status === 'active' ? 'inactive' : 'active' })
      setMessage(`${person.name} marked ${person.status === 'active' ? 'inactive' : 'active'}.`)
      await load(search)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not update the record.')
    }
  }

  return <section className="dash-panel dash-masters-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">ORGANIZATION ROSTER</span><h2>{isStudent ? 'Students' : 'Teachers'}</h2><p className="dash-record-subtitle">Manage records for this organization. Student and staff login invitations are a separate workflow.</p></div><button className="dash-icon-button" onClick={() => void load(search)} aria-label={`Refresh ${kind}`}><RefreshCw size={16} /></button></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    <div className="master-content">
      <form className="master-form" onSubmit={submit}>
        <h3>{isStudent ? <UsersRound size={17} /> : <GraduationCap size={17} />}Add {isStudent ? 'student' : 'teacher'}</h3>
        <label>Full name<input required maxLength={120} value={fields.name ?? ''} onChange={e => setFields(current => ({ ...current, name: e.target.value }))} /></label>
        <label>{isStudent ? 'Admission number' : 'Employee number'}{!isStudent && <span>(optional)</span>}<input required={isStudent} maxLength={40} value={fields.identifier ?? ''} onChange={e => setFields(current => ({ ...current, identifier: e.target.value }))} /></label>
        <label>Email address <span>(optional)</span><input type="email" maxLength={254} value={fields.email ?? ''} onChange={e => setFields(current => ({ ...current, email: e.target.value }))} /></label>
        <label>Phone <span>(optional)</span><input type="tel" pattern="[+0-9() .-]{7,24}" maxLength={24} value={fields.phone ?? ''} onChange={e => setFields(current => ({ ...current, phone: e.target.value }))} /></label>
        {isStudent ? <label>Enrollment date <span>(optional)</span><input type="date" value={fields.enrolled_on ?? ''} onChange={e => setFields(current => ({ ...current, enrolled_on: e.target.value }))} /></label> : <label>Specialization <span>(optional)</span><input maxLength={120} value={fields.specialization ?? ''} onChange={e => setFields(current => ({ ...current, specialization: e.target.value }))} /></label>}
        {isStudent && <>
          <label>Class <span>(optional)</span><select value={fields.academic_class_id ?? ''} onChange={e => setFields(current => ({ ...current, academic_class_id: e.target.value, academic_section_id: '' }))}><option value="">Not assigned</option>{classes.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
          <label>Section <span>(optional)</span><select disabled={!fields.academic_class_id} value={fields.academic_section_id ?? ''} onChange={e => { const selected = sections.find(item => String(item.id) === e.target.value); setFields(current => ({ ...current, academic_section_id: e.target.value, academic_session_id: selected?.academic_session_id ? String(selected.academic_session_id) : current.academic_session_id ?? '' })) }}><option value="">Not assigned</option>{sections.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
          <label>Academic session <span>(optional)</span><select value={fields.academic_session_id ?? ''} onChange={e => setFields(current => ({ ...current, academic_session_id: e.target.value }))}><option value="">Not assigned</option>{sessions.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        </>}
        <button className="master-submit" disabled={saving}>{saving ? <><LoaderCircle className="master-spin" size={16} />Saving…</> : <><Plus size={16} />Save record</>}</button>
      </form>
      <div className="master-list">
        <div className="master-list-heading"><div><h3>{isStudent ? 'Student roster' : 'Teaching staff'}</h3><span>{records.length} record{records.length === 1 ? '' : 's'} on this page</span></div></div>
        <form className="roster-search" onSubmit={event => { event.preventDefault(); void load(search) }}><label><Search size={15} /><input aria-label={`Search ${kind}`} value={search} onChange={e => setSearch(e.target.value)} placeholder={`Search ${isStudent ? 'name, email, or admission number' : 'name, email, or employee number'}`} /></label><button>Search</button></form>
        {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading roster…</p> : records.length === 0 ? <p className="master-empty"><UsersRound size={18} />No {kind} found. Add a record or change the search.</p> : <ul>{records.map(person => <li key={person.id}><span className="master-record-icon">{isStudent ? <UsersRound size={17} /> : <GraduationCap size={17} />}</span><span className="master-record-main"><b>{person.name}</b><small>{isStudent ? person.admission_number : person.employee_number || 'No employee number'}{person.email ? ` · ${person.email}` : ''}{person.academic_class ? ` · ${person.academic_class.name}` : ''}{person.academic_section ? ` · Section ${person.academic_section.name}` : ''}{person.academic_session ? ` · ${person.academic_session.name}` : ''}</small></span><span className={`master-status ${person.status === 'active' ? 'active' : ''}`}>{person.status.replace('_', ' ')}</span><button className="master-record-action" onClick={() => void toggleStatus(person)}>{person.status === 'active' ? 'Deactivate' : 'Activate'}</button></li>)}</ul>}
      </div>
    </div>
  </section>
}
