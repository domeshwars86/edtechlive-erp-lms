import { useCallback, useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, ChevronLeft, ChevronRight, ClipboardCheck, LoaderCircle, RefreshCw, Save } from 'lucide-react'
import { api } from '../lib/api'

type AttendanceStatus = '' | 'present' | 'absent' | 'late' | 'excused'
type Section = { id: number; name: string; academic_session_id: number | null; is_active?: boolean; academic_session?: { id: number; name: string } | null; academic_class?: { id: number; name: string } | null }
type StudentRow = { id: number; name: string; admission_number: string; status: AttendanceStatus; note: string | null }
type PageResponse = {
  data: StudentRow[]
  context: { section: { id: number; name: string }; class: { id: number; name: string }; session: { id: number; name: string }; date: string }
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}
type ListResponse<T> = { data: T[] }

function localToday(): string {
  const date = new Date()
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

export default function AttendanceRegister({ organizationId }: { organizationId: number }) {
  const base = `/api/v1/organizations/${organizationId}`
  const [sections, setSections] = useState<Section[]>([])
  const [sectionId, setSectionId] = useState('')
  const [date, setDate] = useState(localToday)
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<StudentRow[]>([])
  const [meta, setMeta] = useState<PageResponse['meta']>({ current_page: 1, last_page: 1, per_page: 100, total: 0 })
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  useEffect(() => {
    let cancelled = false
    void api.get<ListResponse<{ id: number; name: string }>>(`${base}/classes?per_page=100`)
      .then(async classes => {
        const sectionLists = await Promise.all(classes.data.map(async item => {
          const result = await api.get<ListResponse<Section>>(`${base}/classes/${item.id}/sections?per_page=100`)
          return result.data.map(section => ({ ...section, academic_class: { id: item.id, name: item.name } }))
        }))
        if (!cancelled) {
          const items = sectionLists.flat()
          setSections(items.filter(item => item.is_active !== false && item.academic_session_id !== null))
          setSectionId(current => current || String(items[0]?.id ?? ''))
        }
      })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load class sections.') })
    return () => { cancelled = true }
  }, [base])

  const loadAttendance = useCallback(async () => {
    if (!sectionId || !date) {
      setRows([])
      setMeta({ current_page: 1, last_page: 1, per_page: 100, total: 0 })
      return
    }
    setLoading(true)
    setError('')
    setMessage('')
    try {
      const query = new URLSearchParams({ section_id: sectionId, date, page: String(page), per_page: '100' })
      const result = await api.get<PageResponse>(`${base}/attendance?${query}`)
      setRows(result.data.map(row => ({ ...row, status: row.status ?? '', note: row.note ?? '' })))
      setMeta(result.meta)
    } catch (reason) {
      setRows([])
      setError(reason instanceof Error ? reason.message : 'Could not load this attendance register.')
    } finally {
      setLoading(false)
    }
  }, [base, date, page, sectionId])

  useEffect(() => { void loadAttendance() }, [loadAttendance])

  const counts = useMemo(() => rows.reduce((result, row) => {
    if (row.status) result[row.status] += 1
    return result
  }, { present: 0, absent: 0, late: 0, excused: 0 }), [rows])

  function updateRow(id: number, field: 'status' | 'note', value: string) {
    setRows(current => current.map(row => row.id === id ? { ...row, [field]: value } : row))
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (rows.some(row => !row.status)) {
      setError('Mark every student on this page before saving the register.')
      return
    }
    setSaving(true)
    setError('')
    setMessage('')
    try {
      const result = await api.put<{ message: string; saved: number }>(`${base}/attendance`, {
        section_id: Number(sectionId), date,
        records: rows.map(row => ({ student_id: row.id, status: row.status, note: row.note || null })),
      })
      setMessage(`${result.saved} attendance record${result.saved === 1 ? '' : 's'} saved for this page.`)
      await loadAttendance()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not save attendance.')
    } finally {
      setSaving(false)
    }
  }

  return <section className="dash-panel dash-masters-panel attendance-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">DAILY REGISTER</span><h2>Attendance</h2><p className="dash-record-subtitle">Take attendance for active students in a class section and academic session.</p></div><button className="dash-icon-button" onClick={() => void loadAttendance()} aria-label="Refresh attendance"><RefreshCw size={16} /></button></div>
    <div className="attendance-filters"><label>Class section<select value={sectionId} onChange={event => { setSectionId(event.target.value); setPage(1) }}><option value="">Select a section</option>{sections.map(section => <option key={section.id} value={section.id}>{section.academic_class?.name} · {section.name} · {section.academic_session?.name ?? 'No academic session'}</option>)}</select></label><label>Date<input type="date" required value={date} onChange={event => { setDate(event.target.value); setPage(1) }} /></label></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    <div className="attendance-summary"><span><b>{meta.total}</b> active students</span><span><i className="attendance-dot present" />Present <b>{counts.present}</b></span><span><i className="attendance-dot absent" />Absent <b>{counts.absent}</b></span><span><i className="attendance-dot late" />Late <b>{counts.late}</b></span><span><i className="attendance-dot excused" />Excused <b>{counts.excused}</b></span></div>
    <form onSubmit={save}>
      <div className="dash-table-scroll attendance-table"><table><thead><tr><th>STUDENT</th><th>ADMISSION NO.</th><th>STATUS</th><th>NOTE</th></tr></thead><tbody>{rows.map(row => <tr key={row.id}><td><b>{row.name}</b></td><td>{row.admission_number}</td><td><select aria-label={`Attendance status for ${row.name}`} required value={row.status} onChange={event => updateRow(row.id, 'status', event.target.value as AttendanceStatus)}><option value="">Mark attendance</option><option value="present">Present</option><option value="absent">Absent</option><option value="late">Late</option><option value="excused">Excused</option></select></td><td><input aria-label={`Attendance note for ${row.name}`} maxLength={500} value={row.note ?? ''} onChange={event => updateRow(row.id, 'note', event.target.value)} placeholder="Optional" /></td></tr>)}</tbody></table></div>
      {!loading && sectionId && rows.length === 0 && <p className="master-empty"><ClipboardCheck size={18} />No active students are assigned to this section and session.</p>}
      {loading && <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading attendance…</p>}
      <div className="attendance-footer"><span>Page {meta.current_page} of {meta.last_page} · {meta.total} student{meta.total === 1 ? '' : 's'}</span><div><button type="button" disabled={page <= 1 || loading} onClick={() => setPage(current => current - 1)}><ChevronLeft size={14} />Previous</button><button type="button" disabled={page >= meta.last_page || loading} onClick={() => setPage(current => current + 1)}>Next<ChevronRight size={14} /></button><button className="master-submit" disabled={saving || loading || rows.length === 0}><Save size={15} />{saving ? 'Saving…' : 'Save this page'}</button></div></div>
    </form>
  </section>
}
