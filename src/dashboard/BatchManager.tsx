import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, BookOpen, Check, LoaderCircle, Pencil, Plus, Search, UsersRound } from 'lucide-react'
import { api } from '../lib/api'

type Option = { id: number; name: string }
type Section = Option & { academic_session_id: number | null }
type Batch = {
  id: number
  name: string
  code: string | null
  is_active: boolean
  academic_session_id: number
  academic_class_id: number
  academic_section_id: number | null
  academic_session: Option
  academic_class: Option
  academic_section: Option | null
}
type Page = { current_page: number; last_page: number; total: number }
type BatchResponse = { data: Batch[]; meta: Page }
type Fields = { name: string; code: string; academic_session_id: string; academic_class_id: string; academic_section_id: string }
const emptyFields: Fields = { name: '', code: '', academic_session_id: '', academic_class_id: '', academic_section_id: '' }

export default function BatchManager({ organizationId, sessions, classes }: { organizationId: number; sessions: Option[]; classes: Option[] }) {
  const base = `/api/v1/organizations/${organizationId}`
  const [batches, setBatches] = useState<Batch[]>([])
  const [sections, setSections] = useState<Section[]>([])
  const [fields, setFields] = useState<Fields>(emptyFields)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [classFilter, setClassFilter] = useState('')
  const [sessionFilter, setSessionFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState<Page>({ current_page: 1, last_page: 1, total: 0 })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const loadBatches = useCallback(async () => {
    setLoading(true)
    setError('')
    const params = new URLSearchParams({ page: String(page), per_page: '25' })
    if (search) params.set('search', search)
    if (classFilter) params.set('academic_class_id', classFilter)
    if (sessionFilter) params.set('academic_session_id', sessionFilter)
    if (statusFilter) params.set('is_active', statusFilter)
    try {
      const result = await api.get<BatchResponse>(`${base}/batches?${params}`)
      setBatches(result.data)
      setMeta(result.meta)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not load academic batches.')
    } finally {
      setLoading(false)
    }
  }, [base, classFilter, page, search, sessionFilter, statusFilter])

  useEffect(() => { void loadBatches() }, [loadBatches])

  useEffect(() => {
    if (!fields.academic_class_id) {
      setSections([])
      return
    }
    let cancelled = false
    api.get<{ data: Section[] }>(`${base}/classes/${fields.academic_class_id}/sections`)
      .then(result => { if (!cancelled) setSections(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load batch sections.') })
    return () => { cancelled = true }
  }, [base, fields.academic_class_id])

  function changeField(field: keyof Fields, value: string) {
    setFields(current => ({ ...current, [field]: value, ...(field === 'academic_class_id' ? { academic_section_id: '' } : {}) }))
  }

  function edit(batch: Batch) {
    setEditingId(batch.id)
    setFields({
      name: batch.name,
      code: batch.code ?? '',
      academic_session_id: String(batch.academic_session_id),
      academic_class_id: String(batch.academic_class_id),
      academic_section_id: batch.academic_section_id ? String(batch.academic_section_id) : '',
    })
    setError('')
    setMessage('')
  }

  function cancelEdit() {
    setEditingId(null)
    setFields(emptyFields)
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setError('')
    setMessage('')
    const payload = {
      name: fields.name.trim(),
      code: fields.code.trim() || null,
      academic_session_id: Number(fields.academic_session_id),
      academic_class_id: Number(fields.academic_class_id),
      academic_section_id: fields.academic_section_id ? Number(fields.academic_section_id) : null,
    }
    try {
      if (editingId) {
        await api.patch(`${base}/batches/${editingId}`, payload)
        setMessage('Batch updated.')
      } else {
        await api.post(`${base}/batches`, payload)
        setMessage('Batch created.')
      }
      setEditingId(null)
      setFields(emptyFields)
      await loadBatches()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not save this batch.')
    } finally {
      setSaving(false)
    }
  }

  async function toggleActive(batch: Batch) {
    setSaving(true)
    setError('')
    setMessage('')
    try {
      await api.patch(`${base}/batches/${batch.id}`, { is_active: !batch.is_active })
      setMessage(batch.is_active ? 'Batch deactivated.' : 'Batch activated.')
      await loadBatches()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not update batch status.')
    } finally {
      setSaving(false)
    }
  }

  const visibleSections = sections.filter(section => section.academic_session_id === null || String(section.academic_session_id) === fields.academic_session_id)

  return <div className="batch-manager">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    <div className="master-content">
      <form className="master-form" onSubmit={event => void save(event)}>
        <h3><Plus size={17} />{editingId ? 'Edit batch' : 'Add batch'}</h3>
        <label>Batch name<input required maxLength={120} value={fields.name} onChange={event => changeField('name', event.target.value)} placeholder="Morning batch" /></label>
        <label>Code <span>(optional)</span><input maxLength={32} value={fields.code} onChange={event => changeField('code', event.target.value)} placeholder="MORN-8" /></label>
        <label>Academic session<select required value={fields.academic_session_id} onChange={event => changeField('academic_session_id', event.target.value)}><option value="">Choose a session</option>{sessions.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        <label>Class<select required value={fields.academic_class_id} onChange={event => changeField('academic_class_id', event.target.value)}><option value="">Choose a class</option>{classes.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        <label>Section <span>(optional)</span><select disabled={!fields.academic_class_id} value={fields.academic_section_id} onChange={event => changeField('academic_section_id', event.target.value)}><option value="">All class sections</option>{visibleSections.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        <div className="batch-form-actions"><button className="master-submit" disabled={saving || !sessions.length || !classes.length}>{saving ? <><LoaderCircle className="master-spin" size={16} />Saving…</> : <><Plus size={16} />{editingId ? 'Save changes' : 'Create batch'}</>}</button>{editingId && <button type="button" className="master-record-action" onClick={cancelEdit}>Cancel</button>}</div>
        {(!sessions.length || !classes.length) && <p className="master-hint">Create an academic session and class before adding a batch.</p>}
      </form>
      <div className="master-list">
        <div className="master-list-heading"><div><h3>Academic batches</h3><span>{meta.total} record{meta.total === 1 ? '' : 's'}</span></div></div>
        <form className="batch-search" onSubmit={event => { event.preventDefault(); setPage(1); setSearch(searchInput.trim()) }}><label><Search size={15} /><input aria-label="Search batches" value={searchInput} onChange={event => setSearchInput(event.target.value)} placeholder="Search name or code" /></label><button type="submit">Search</button></form>
        <div className="batch-filters"><label>Session<select value={sessionFilter} onChange={event => { setSessionFilter(event.target.value); setPage(1) }}><option value="">All sessions</option>{sessions.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Class<select value={classFilter} onChange={event => { setClassFilter(event.target.value); setPage(1) }}><option value="">All classes</option>{classes.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Status<select value={statusFilter} onChange={event => { setStatusFilter(event.target.value); setPage(1) }}><option value="">All statuses</option><option value="1">Active</option><option value="0">Inactive</option></select></label></div>
        {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading batches…</p> : batches.length === 0 ? <p className="master-empty"><BookOpen size={18} />No batches match these filters.</p> : <ul>{batches.map(batch => <li key={batch.id}><span className="master-record-icon"><UsersRound size={17} /></span><span className="master-record-main"><b>{batch.name}</b><small>{batch.academic_class.name}{batch.academic_section ? ` · ${batch.academic_section.name}` : ''} · {batch.academic_session.name}{batch.code ? ` · ${batch.code}` : ''}</small></span><span className={`master-status ${batch.is_active ? 'active' : ''}`}>{batch.is_active ? 'Active' : 'Inactive'}</span><button className="master-record-action" type="button" onClick={() => edit(batch)} aria-label={`Edit ${batch.name}`}><Pencil size={14} />Edit</button><button className="master-record-action" type="button" disabled={saving} onClick={() => void toggleActive(batch)}>{batch.is_active ? 'Deactivate' : 'Activate'}</button></li>)}</ul>}
        {meta.last_page > 1 && <div className="dash-table-footer"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage(value => value - 1)}>Previous</button><span>Page {page} of {meta.last_page}</span><button type="button" disabled={page >= meta.last_page || loading} onClick={() => setPage(value => value + 1)}>Next</button></div>}
      </div>
    </div>
  </div>
}
