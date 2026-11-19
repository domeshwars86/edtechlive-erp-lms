import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, Download, GraduationCap, LoaderCircle, Plus, RefreshCw, Search, UsersRound } from 'lucide-react'
import { api, ApiError } from '../lib/api'
import { downloadCsv } from '../lib/csv'

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
  organization_branch_id?: number | null
  branch?: { id: number; name: string; code?: string | null } | null
  academic_class_id?: number | null
  academic_section_id?: number | null
  academic_session_id?: number | null
  enrolled_on?: string | null
}
type RosterResponse = { data: Person[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }
type MasterList = { data: { id: number; name: string; code?: string | null; academic_session_id?: number | null; organization_branch_id?: number | null; branch?: { id: number; name: string } | null }[] }
type ParentAccount = { id: number; name: string; email: string }
type GuardianLink = { guardian_user_id: number; name: string; email: string; relationship: string; is_primary: boolean; linked_at: string }
type Props = { organizationId: number; kind: 'students' | 'teachers' }

export default function PeopleRoster({ organizationId, kind }: Props) {
  const [records, setRecords] = useState<Person[]>([])
  const [classes, setClasses] = useState<MasterList['data']>([])
  const [sessions, setSessions] = useState<MasterList['data']>([])
  const [branches, setBranches] = useState<MasterList['data']>([])
  const [sections, setSections] = useState<MasterList['data']>([])
  const [fields, setFields] = useState<Record<string, string>>({})
  const [editingPerson, setEditingPerson] = useState<Person | null>(null)
  const [search, setSearch] = useState('')
  const [branchFilter, setBranchFilter] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [guardianStudentId, setGuardianStudentId] = useState<number | null>(null)
  const [parentAccounts, setParentAccounts] = useState<ParentAccount[]>([])
  const [guardianLinks, setGuardianLinks] = useState<GuardianLink[]>([])
  const [guardianUserId, setGuardianUserId] = useState('')
  const [guardianRelationship, setGuardianRelationship] = useState('parent')
  const [guardianPrimary, setGuardianPrimary] = useState(false)
  const [guardianSaving, setGuardianSaving] = useState(false)
  const base = `/api/v1/organizations/${organizationId}/${kind}`
  const isStudent = kind === 'students'
  const organizationBase = `/api/v1/organizations/${organizationId}`

  useEffect(() => {
    void Promise.all([
      api.get<MasterList>(`${organizationBase}/branches?is_active=1&per_page=100`),
      ...(isStudent ? [api.get<MasterList>(`${organizationBase}/classes`), api.get<MasterList>(`${organizationBase}/academic-sessions`)] : []),
    ]).then(([branchResult, classResult, sessionResult]) => {
      setBranches(branchResult.data)
      if (isStudent) {
        setClasses((classResult as MasterList).data)
        setSessions((sessionResult as MasterList).data)
      }
    }).catch(reason => setError(reason instanceof Error ? reason.message : 'Could not load roster placement options.'))
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

  const load = useCallback(async (query: string, branchId = '') => {
    setLoading(true)
    setError('')
    try {
      const parameters = new URLSearchParams()
      if (query) parameters.set('search', query)
      if (branchId) parameters.set('organization_branch_id', branchId)
      const suffix = parameters.toString()
      const result = await api.get<RosterResponse>(`${base}${suffix ? `?${suffix}` : ''}`)
      setRecords(result.data)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not load this roster.')
    } finally {
      setLoading(false)
    }
  }, [base])

  useEffect(() => { void load('', '') }, [load])

  useEffect(() => {
    if (!isStudent || guardianStudentId === null) return
    let cancelled = false
    void Promise.all([
      api.get<{ data: ParentAccount[] }>(`${organizationBase}/parent-accounts`),
      api.get<{ data: GuardianLink[] }>(`${organizationBase}/students/${guardianStudentId}/guardians`),
    ]).then(([parents, links]) => {
      if (!cancelled) { setParentAccounts(parents.data); setGuardianLinks(links.data) }
    }).catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load guardian accounts.') })
    return () => { cancelled = true }
  }, [guardianStudentId, isStudent, organizationBase])

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
          organization_branch_id: fields.organization_branch_id ? Number(fields.organization_branch_id) : null,
        }
      : {
          name: fields.name, employee_number: fields.identifier || null, email: fields.email || null,
          phone: fields.phone || null, specialization: fields.specialization || null,
          organization_branch_id: fields.organization_branch_id ? Number(fields.organization_branch_id) : null,
        }
    try {
      if (editingPerson) await api.patch(`${base}/${editingPerson.id}`, body)
      else await api.post(base, body)
      setFields({})
      setEditingPerson(null)
      setMessage(`${isStudent ? 'Student' : 'Teacher'} ${editingPerson ? 'updated in' : 'added to'} the organization roster.`)
      await load('', branchFilter)
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : reason instanceof Error ? reason.message : 'Could not save the record.')
    } finally {
      setSaving(false)
    }
  }

  async function exportRoster() {
    setExporting(true)
    setError('')
    setMessage('')
    try {
      const parameters = new URLSearchParams({ per_page: '100', page: '1' })
      if (search.trim()) parameters.set('search', search.trim())
      if (branchFilter) parameters.set('organization_branch_id', branchFilter)
      const firstPage = await api.get<RosterResponse>(`${base}?${parameters}`)
      const rows = [...firstPage.data]
      const expectedTotal = firstPage.meta.total
      for (let page = 2; page <= firstPage.meta.last_page; page += 1) {
        parameters.set('page', String(page))
        const result = await api.get<RosterResponse>(`${base}?${parameters}`)
        if (result.meta.total !== expectedTotal) throw new Error('The roster changed while exporting. Refresh it and try again.')
        rows.push(...result.data)
      }
      const headers = isStudent
        ? ['Admission number', 'Student name', 'Email', 'Phone', 'Status', 'Enrollment date', 'Branch', 'Class', 'Section', 'Academic session']
        : ['Employee number', 'Teacher name', 'Email', 'Phone', 'Specialization', 'Status', 'Branch']
      const data = rows.map(person => isStudent
        ? [person.admission_number, person.name, person.email, person.phone, person.status, person.enrolled_on?.slice(0, 10), person.branch?.name, person.academic_class?.name, person.academic_section?.name, person.academic_session?.name]
        : [person.employee_number, person.name, person.email, person.phone, person.specialization, person.status, person.branch?.name])
      downloadCsv(`${kind}-${new Date().toISOString().slice(0, 10)}.csv`, headers, data)
      const recordLabel = isStudent ? 'student' : 'teacher'
      setMessage(`Exported ${rows.length} ${recordLabel} record${rows.length === 1 ? '' : 's'}${search.trim() || branchFilter ? ' matching the current filters' : ''}.`)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not export this roster.')
    } finally {
      setExporting(false)
    }
  }

  function editPerson(person: Person) {
    setEditingPerson(person)
    setError('')
    setMessage('')
    setFields({
      name: person.name,
      identifier: isStudent ? person.admission_number ?? '' : person.employee_number ?? '',
      email: person.email ?? '', phone: person.phone ?? '', specialization: person.specialization ?? '',
      enrolled_on: person.enrolled_on?.slice(0, 10) ?? '',
      academic_class_id: person.academic_class_id ? String(person.academic_class_id) : '',
      academic_section_id: person.academic_section_id ? String(person.academic_section_id) : '',
      academic_session_id: person.academic_session_id ? String(person.academic_session_id) : '',
      organization_branch_id: person.organization_branch_id ? String(person.organization_branch_id) : '',
    })
  }

  async function toggleStatus(person: Person) {
    setError('')
    try {
      await api.patch(`${base}/${person.id}`, { status: person.status === 'active' ? 'inactive' : 'active' })
      setMessage(`${person.name} marked ${person.status === 'active' ? 'inactive' : 'active'}.`)
      await load(search, branchFilter)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not update the record.')
    }
  }

  async function linkGuardian(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (guardianStudentId === null) return
    setGuardianSaving(true); setError(''); setMessage('')
    try {
      await api.post(`${organizationBase}/students/${guardianStudentId}/guardians`, {
        guardian_user_id: Number(guardianUserId), relationship: guardianRelationship, is_primary: guardianPrimary,
      })
      const result = await api.get<{ data: GuardianLink[] }>(`${organizationBase}/students/${guardianStudentId}/guardians`)
      setGuardianLinks(result.data); setGuardianUserId(''); setGuardianPrimary(false)
      setMessage('Parent access linked to this student.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not link this parent account.') }
    finally { setGuardianSaving(false) }
  }

  async function unlinkGuardian(link: GuardianLink) {
    if (guardianStudentId === null) return
    setGuardianSaving(true); setError(''); setMessage('')
    try {
      await api.delete(`${organizationBase}/students/${guardianStudentId}/guardians/${link.guardian_user_id}`)
      setGuardianLinks(current => current.filter(item => item.guardian_user_id !== link.guardian_user_id))
      setMessage(`Parent access removed for ${link.name}.`)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not remove parent access.') }
    finally { setGuardianSaving(false) }
  }

  return <section className="dash-panel dash-masters-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">ORGANIZATION ROSTER</span><h2>{isStudent ? 'Students' : 'Teachers'}</h2><p className="dash-record-subtitle">Manage records for this organization. Student and staff login invitations are a separate workflow.</p></div><div className="report-actions"><button className="dash-secondary-button" type="button" disabled={exporting || loading} onClick={() => void exportRoster()}><Download size={14} />{exporting ? 'Preparing CSV…' : 'Export CSV'}</button><button className="dash-icon-button" type="button" onClick={() => void load(search, branchFilter)} aria-label={`Refresh ${kind}`}><RefreshCw size={16} /></button></div></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    <div className="master-content">
      <form className="master-form" onSubmit={submit}>
        <h3>{isStudent ? <UsersRound size={17} /> : <GraduationCap size={17} />}{editingPerson ? 'Edit' : 'Add'} {isStudent ? 'student' : 'teacher'}</h3>
        <label>Full name<input required maxLength={120} value={fields.name ?? ''} onChange={e => setFields(current => ({ ...current, name: e.target.value }))} /></label>
        <label>{isStudent ? 'Admission number' : 'Employee number'}{!isStudent && <span>(optional)</span>}<input required={isStudent} maxLength={40} value={fields.identifier ?? ''} onChange={e => setFields(current => ({ ...current, identifier: e.target.value }))} /></label>
        <label>Email address <span>(optional)</span><input type="email" maxLength={254} value={fields.email ?? ''} onChange={e => setFields(current => ({ ...current, email: e.target.value }))} /></label>
        <label>Phone <span>(optional)</span><input type="tel" pattern="[+0-9() .-]{7,24}" maxLength={24} value={fields.phone ?? ''} onChange={e => setFields(current => ({ ...current, phone: e.target.value }))} /></label>
        {isStudent ? <label>Enrollment date <span>(optional)</span><input type="date" value={fields.enrolled_on ?? ''} onChange={e => setFields(current => ({ ...current, enrolled_on: e.target.value }))} /></label> : <label>Specialization <span>(optional)</span><input maxLength={120} value={fields.specialization ?? ''} onChange={e => setFields(current => ({ ...current, specialization: e.target.value }))} /></label>}
        {isStudent && <>
          <label>Branch <span>(optional)</span><select value={fields.organization_branch_id ?? ''} onChange={e => setFields(current => { const selectedClass = classes.find(item => String(item.id) === current.academic_class_id); const selectedBranch = e.target.value; const conflict = selectedClass?.organization_branch_id && selectedBranch && String(selectedClass.organization_branch_id) !== selectedBranch; return { ...current, organization_branch_id: selectedBranch, ...(conflict ? { academic_class_id: '', academic_section_id: '' } : {}) } })}><option value="">Organization-wide</option>{editingPerson?.branch && !branches.some(item => item.id === editingPerson.organization_branch_id) && <option value={editingPerson.organization_branch_id ?? ''} disabled>{editingPerson.branch.name} (inactive)</option>}{branches.map(item => <option key={item.id} value={item.id}>{item.name}{item.code ? ` · ${item.code}` : ''}</option>)}</select></label>
          <label>Class <span>(optional)</span><select value={fields.academic_class_id ?? ''} onChange={e => { const selected = classes.find(item => String(item.id) === e.target.value); setFields(current => ({ ...current, academic_class_id: e.target.value, academic_section_id: '', organization_branch_id: selected?.organization_branch_id ? String(selected.organization_branch_id) : current.organization_branch_id ?? '' })) }}><option value="">Not assigned</option>{classes.filter(item => !fields.organization_branch_id || !item.organization_branch_id || String(item.organization_branch_id) === fields.organization_branch_id).map(item => <option key={item.id} value={item.id}>{item.name}{item.branch ? ` · ${item.branch.name}` : ''}</option>)}</select></label>
          <label>Section <span>(optional)</span><select disabled={!fields.academic_class_id} value={fields.academic_section_id ?? ''} onChange={e => { const selected = sections.find(item => String(item.id) === e.target.value); setFields(current => ({ ...current, academic_section_id: e.target.value, academic_session_id: selected?.academic_session_id ? String(selected.academic_session_id) : current.academic_session_id ?? '' })) }}><option value="">Not assigned</option>{sections.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
          <label>Academic session <span>(optional)</span><select value={fields.academic_session_id ?? ''} onChange={e => setFields(current => ({ ...current, academic_session_id: e.target.value }))}><option value="">Not assigned</option>{sessions.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        </>}
        {!isStudent && <label>Branch <span>(optional)</span><select value={fields.organization_branch_id ?? ''} onChange={e => setFields(current => ({ ...current, organization_branch_id: e.target.value }))}><option value="">Organization-wide</option>{editingPerson?.branch && !branches.some(item => item.id === editingPerson.organization_branch_id) && <option value={editingPerson.organization_branch_id ?? ''} disabled>{editingPerson.branch.name} (inactive)</option>}{branches.map(item => <option key={item.id} value={item.id}>{item.name}{item.code ? ` · ${item.code}` : ''}</option>)}</select></label>}
        <div className="master-form-actions"><button className="master-submit" disabled={saving}>{saving ? <><LoaderCircle className="master-spin" size={16} />Saving…</> : <><Plus size={16} />{editingPerson ? 'Update record' : 'Save record'}</>}</button>{editingPerson && <button type="button" className="master-record-action" onClick={() => { setEditingPerson(null); setFields({}) }}>Cancel</button>}</div>
      </form>
      <div className="master-list">
        <div className="master-list-heading"><div><h3>{isStudent ? 'Student roster' : 'Teaching staff'}</h3><span>{records.length} record{records.length === 1 ? '' : 's'} on this page</span></div></div>
        <form className="roster-search" onSubmit={event => { event.preventDefault(); void load(search, branchFilter) }}><label><Search size={15} /><input aria-label={`Search ${kind}`} value={search} onChange={e => setSearch(e.target.value)} placeholder={`Search ${isStudent ? 'name, email, or admission number' : 'name, email, or employee number'}`} /></label><label><span className="sr-only">Filter by branch</span><select aria-label="Filter roster by branch" value={branchFilter} onChange={e => { setBranchFilter(e.target.value); void load(search, e.target.value) }}><option value="">All branches</option>{branches.map(branch => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label><button>Search</button></form>
        {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading roster…</p> : records.length === 0 ? <p className="master-empty"><UsersRound size={18} />No {kind} found. Add a record or change the search.</p> : <ul>{records.map(person => <li key={person.id}><span className="master-record-icon">{isStudent ? <UsersRound size={17} /> : <GraduationCap size={17} />}</span><span className="master-record-main"><b>{person.name}</b><small>{isStudent ? person.admission_number : person.employee_number || 'No employee number'}{person.email ? ` · ${person.email}` : ''}{person.branch ? ` · ${person.branch.name}` : ''}{person.academic_class ? ` · ${person.academic_class.name}` : ''}{person.academic_section ? ` · Section ${person.academic_section.name}` : ''}{person.academic_session ? ` · ${person.academic_session.name}` : ''}</small></span><span className={`master-status ${person.status === 'active' ? 'active' : ''}`}>{person.status.replace('_', ' ')}</span>{isStudent && <button className="master-record-action" onClick={() => { setGuardianStudentId(person.id); setError(''); setMessage('') }}>Parents</button>}<button className="master-record-action" onClick={() => editPerson(person)}>Edit</button><button className="master-record-action" onClick={() => void toggleStatus(person)}>{person.status === 'active' ? 'Deactivate' : 'Activate'}</button></li>)}</ul>}
        {isStudent && guardianStudentId !== null && <section className="guardian-manager"><header><div><h3>Parent access · {records.find(person => person.id === guardianStudentId)?.name ?? 'Student'}</h3><p>Only active, verified parent accounts in this organization can be linked.</p></div><button className="master-record-action" onClick={() => { setGuardianStudentId(null); setGuardianLinks([]) }}>Close</button></header><form className="guardian-link-form" onSubmit={event => void linkGuardian(event)}><label>Parent account<select required value={guardianUserId} onChange={event => setGuardianUserId(event.target.value)}><option value="">Choose a parent account</option>{parentAccounts.filter(parent => !guardianLinks.some(link => link.guardian_user_id === parent.id)).map(parent => <option key={parent.id} value={parent.id}>{parent.name} · {parent.email}</option>)}</select></label><label>Relationship<select value={guardianRelationship} onChange={event => setGuardianRelationship(event.target.value)}><option value="parent">Parent</option><option value="guardian">Guardian</option><option value="other">Other caregiver</option></select></label><label className="guardian-primary-option"><input type="checkbox" checked={guardianPrimary} onChange={event => setGuardianPrimary(event.target.checked)} /> Set as primary contact</label><button className="master-submit" disabled={guardianSaving || !guardianUserId}>{guardianSaving ? 'Saving…' : 'Link parent'}</button></form>{guardianLinks.length ? <ul>{guardianLinks.map(link => <li key={link.guardian_user_id}><span><b>{link.name}</b><small>{link.email} · {link.relationship}{link.is_primary ? ' · Primary' : ''}</small></span><button className="master-record-action" disabled={guardianSaving} onClick={() => void unlinkGuardian(link)}>Remove access</button></li>)}</ul> : <p className="master-empty">No parent accounts are linked.</p>}</section>}
      </div>
    </div>
  </section>
}
