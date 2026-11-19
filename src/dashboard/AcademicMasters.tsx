import { useCallback, useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, BookOpen, CalendarDays, Check, LoaderCircle, Plus, RefreshCw, UsersRound } from 'lucide-react'
import { api, ApiError } from '../lib/api'
import BatchManager from './BatchManager'
import BranchManager from './BranchManager'

type MasterRecord = {
  id: number
  name: string
  code?: string | null
  starts_on?: string
  ends_on?: string
  is_current?: boolean
  is_active: boolean
  academic_session_id?: number | null
  academic_session?: { id: number; name: string } | null
  capacity?: number | null
}
type MasterTab = 'sessions' | 'classes' | 'subjects' | 'sections' | 'batches' | 'branches'
type ListResponse = { data: MasterRecord[] }

const labels: Record<MasterTab, string> = {
  sessions: 'Academic sessions',
  classes: 'Classes',
  subjects: 'Subjects',
  sections: 'Sections',
  batches: 'Batches',
  branches: 'Branches',
}

export default function AcademicMasters({ organizationId }: { organizationId: number }) {
  const [tab, setTab] = useState<MasterTab>('sessions')
  const [sessions, setSessions] = useState<MasterRecord[]>([])
  const [classes, setClasses] = useState<MasterRecord[]>([])
  const [subjects, setSubjects] = useState<MasterRecord[]>([])
  const [sections, setSections] = useState<MasterRecord[]>([])
  const [classId, setClassId] = useState('')
  const [fields, setFields] = useState<Record<string, string>>({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const base = `/api/v1/organizations/${organizationId}`

  const loadMasters = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [sessionResult, classResult, subjectResult] = await Promise.all([
        api.get<ListResponse>(`${base}/academic-sessions`),
        api.get<ListResponse>(`${base}/classes`),
        api.get<ListResponse>(`${base}/subjects`),
      ])
      setSessions(sessionResult.data)
      setClasses(classResult.data)
      setSubjects(subjectResult.data)
      setClassId(current => current || String(classResult.data[0]?.id ?? ''))
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not load academic records.')
    } finally {
      setLoading(false)
    }
  }, [base])

  useEffect(() => { void loadMasters() }, [loadMasters])

  useEffect(() => {
    if (tab !== 'sections' || !classId) {
      setSections([])
      return
    }
    let cancelled = false
    void api.get<ListResponse>(`${base}/classes/${classId}/sections`)
      .then(result => { if (!cancelled) setSections(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load sections.') })
    return () => { cancelled = true }
  }, [base, classId, tab])

  const records = useMemo(() => tab === 'batches' || tab === 'branches' ? [] : ({ sessions, classes, subjects, sections })[tab], [classes, sections, sessions, subjects, tab])

  function setField(name: string, value: string) {
    setFields(current => ({ ...current, [name]: value }))
  }

  async function createRecord(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setError('')
    setMessage('')
    try {
      if (tab === 'sessions') {
        await api.post(`${base}/academic-sessions`, {
          name: fields.name, starts_on: fields.starts_on, ends_on: fields.ends_on,
          is_current: fields.is_current === 'on',
        })
      } else if (tab === 'classes') {
        await api.post(`${base}/classes`, { name: fields.name, code: fields.code || null })
      } else if (tab === 'subjects') {
        await api.post(`${base}/subjects`, { name: fields.name, code: fields.code || null, description: fields.description || null })
      } else {
        await api.post(`${base}/classes/${classId}/sections`, {
          name: fields.name, academic_session_id: fields.academic_session_id ? Number(fields.academic_session_id) : null,
          capacity: fields.capacity ? Number(fields.capacity) : null,
        })
      }
      setFields({})
      const singularLabel = tab === 'sessions' ? 'Academic session' : tab === 'classes' ? 'Class' : tab === 'subjects' ? 'Subject' : 'Section'
      setMessage(`${singularLabel} saved.`)
      if (tab === 'sections') {
        const result = await api.get<ListResponse>(`${base}/classes/${classId}/sections`)
        setSections(result.data)
      } else {
        await loadMasters()
      }
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : reason instanceof Error ? reason.message : 'Could not save this record.')
    } finally {
      setSaving(false)
    }
  }

  async function toggleActive(record: MasterRecord) {
    const endpoint = tab === 'sessions' ? 'academic-sessions' : tab
    const url = tab === 'sections' ? `${base}/sections/${record.id}` : `${base}/${endpoint}/${record.id}`
    try {
      if (tab === 'sessions' && !record.is_current) {
        await api.patch(url, { is_current: true })
      } else {
        await api.patch(url, { is_active: !record.is_active })
      }
      setMessage(tab === 'sessions' && !record.is_current ? 'Current academic session updated.' : `Record ${record.is_active ? 'archived' : 'restored'}.`)
      if (tab === 'sections') {
        const result = await api.get<ListResponse>(`${base}/classes/${classId}/sections`)
        setSections(result.data)
      } else await loadMasters()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not update this record.')
    }
  }

  return <section className="dash-panel dash-masters-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">ACADEMIC SETUP</span><h2>Academic master entries</h2><p className="dash-record-subtitle">These records belong to your selected organization.</p></div><button className="dash-icon-button" onClick={() => void loadMasters()} aria-label="Refresh academic records"><RefreshCw size={16} /></button></div>
    <div className="master-tabs" role="tablist" aria-label="Academic record type">{(['sessions', 'classes', 'subjects', 'sections', 'batches', 'branches'] as MasterTab[]).map(item => <button key={item} role="tab" aria-selected={tab === item} className={tab === item ? 'selected' : ''} onClick={() => setTab(item)}>{labels[item]}</button>)}</div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    {tab === 'batches' ? <BatchManager organizationId={organizationId} sessions={sessions} classes={classes} /> : tab === 'branches' ? <BranchManager organizationId={organizationId} /> : <div className="master-content">
      <form className="master-form" onSubmit={createRecord}>
        <h3><Plus size={17} />Add {labels[tab].toLowerCase().replace(/s$/, '')}</h3>
        {tab === 'sessions' && <>
          <label>Session name<input required maxLength={120} value={fields.name ?? ''} onChange={e => setField('name', e.target.value)} placeholder="2026–2027" /></label>
          <div className="master-form-row"><label>Starts on<input required type="date" value={fields.starts_on ?? ''} onChange={e => setField('starts_on', e.target.value)} /></label><label>Ends on<input required type="date" value={fields.ends_on ?? ''} onChange={e => setField('ends_on', e.target.value)} /></label></div>
          <label className="master-checkbox"><input type="checkbox" checked={fields.is_current === 'on'} onChange={e => setField('is_current', e.target.checked ? 'on' : '')} />Set as current session</label>
        </>}
        {(tab === 'classes' || tab === 'subjects') && <>
          <label>Name<input required maxLength={120} value={fields.name ?? ''} onChange={e => setField('name', e.target.value)} placeholder={tab === 'classes' ? 'Grade 8' : 'Mathematics'} /></label>
          <label>Code <span>(optional)</span><input maxLength={32} value={fields.code ?? ''} onChange={e => setField('code', e.target.value)} placeholder={tab === 'classes' ? 'G8' : 'MATH'} /></label>
          {tab === 'subjects' && <label>Description <span>(optional)</span><textarea maxLength={2000} value={fields.description ?? ''} onChange={e => setField('description', e.target.value)} rows={3} /> </label>}
        </>}
        {tab === 'sections' && <>
          <label>Class<select required value={classId} onChange={e => setClassId(e.target.value)}><option value="">Select class</option>{classes.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
          <label>Section name<input required maxLength={80} value={fields.name ?? ''} onChange={e => setField('name', e.target.value)} placeholder="A" /></label>
          <label>Academic session<select value={fields.academic_session_id ?? ''} onChange={e => setField('academic_session_id', e.target.value)}><option value="">Not assigned</option>{sessions.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
          <label>Capacity <span>(optional)</span><input type="number" min="1" max="65535" value={fields.capacity ?? ''} onChange={e => setField('capacity', e.target.value)} /></label>
        </>}
        <button className="master-submit" disabled={saving || (tab === 'sections' && !classId)}>{saving ? <><LoaderCircle className="master-spin" size={16} />Saving…</> : <><Plus size={16} />Save record</>}</button>
      </form>
      <div className="master-list"><div className="master-list-heading"><div><h3>{labels[tab]}</h3><span>{records.length} record{records.length === 1 ? '' : 's'}</span></div></div>
        {tab === 'sections' && <label className="master-class-filter"><UsersRound size={15} /><select aria-label="Filter sections by class" value={classId} onChange={e => setClassId(e.target.value)}><option value="">Choose a class</option>{classes.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>}
        {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading records…</p> : records.length === 0 ? <p className="master-empty"><BookOpen size={18} />No {labels[tab].toLowerCase()} yet. Add the first record to get started.</p> : <ul>{records.map(record => <li key={record.id}><span className="master-record-icon">{tab === 'sessions' ? <CalendarDays size={17} /> : tab === 'classes' ? <UsersRound size={17} /> : <BookOpen size={17} />}</span><span className="master-record-main"><b>{record.name}</b><small>{tab === 'sessions' ? `${record.starts_on} – ${record.ends_on}${record.is_current ? ' · Current' : ''}` : tab === 'sections' ? `${record.academic_session?.name ?? 'No session'}${record.capacity ? ` · capacity ${record.capacity}` : ''}` : record.code || 'No code'}</small></span><span className={`master-status ${record.is_active ? 'active' : ''}`}>{record.is_active ? 'Active' : 'Archived'}</span><button className="master-record-action" onClick={() => void toggleActive(record)}>{tab === 'sessions' && !record.is_current ? 'Make current' : record.is_active ? 'Archive' : 'Restore'}</button></li>)}</ul>}
      </div>
    </div>}
  </section>
}
