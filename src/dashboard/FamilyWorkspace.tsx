import { useCallback, useEffect, useMemo, useState } from 'react'
import { AlertCircle, CalendarDays, ClipboardCheck, LoaderCircle, UsersRound } from 'lucide-react'
import { api } from '../lib/api'

type StudentProfile = { id: number; name: string; admission_number: string; academic_class?: { id: number; name: string } | null; academic_section?: { id: number; name: string } | null; academic_session?: { id: number; name: string } | null; relationship?: string; is_primary?: boolean }
type AttendanceEntry = { id: number; date: string; status: 'present' | 'absent' | 'late' | 'excused'; note: string | null; section: { class: string; name: string }; session: { name: string } }
type ListResult<T> = { data: T[]; meta?: { current_page: number; last_page: number; total: number } }

export default function FamilyWorkspace({ organizationId, role, active }: { organizationId: number; role: 'student' | 'parent'; active: string }) {
  const base = `/api/v1/organizations/${organizationId}/my`
  const [students, setStudents] = useState<StudentProfile[]>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [entries, setEntries] = useState<AttendanceEntry[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    const request = role === 'student'
      ? api.get<{ data: StudentProfile }>(`${base}/student`).then(result => ({ data: [result.data] }))
      : api.get<ListResult<StudentProfile>>(`${base}/children?per_page=100`)
    request.then(result => {
      if (cancelled) return
      setStudents(result.data)
      setSelectedId(current => current ?? result.data[0]?.id ?? null)
    }).catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load linked student profiles.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base, role])

  const selected = students.find(student => student.id === selectedId) ?? null
  const loadAttendance = useCallback(async () => {
    if (!selected) { setEntries([]); setTotal(0); return }
    setError('')
    try {
      const path = role === 'student' ? `${base}/student/attendance?per_page=100` : `${base}/children/${selected.id}/attendance?per_page=100`
      const result = await api.get<ListResult<AttendanceEntry>>(path)
      setEntries(result.data); setTotal(result.meta?.total ?? result.data.length)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not load attendance history.') }
  }, [base, role, selected])

  useEffect(() => { void loadAttendance() }, [loadAttendance])

  const counts = useMemo(() => entries.reduce((totals, entry) => { totals[entry.status] += 1; return totals }, { present: 0, absent: 0, late: 0, excused: 0 }), [entries])
  const showAttendance = active === 'Attendance'

  return <div className="family-workspace">
    <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">{role === 'student' ? 'MY LEARNING RECORD' : 'FAMILY WORKSPACE'}</span><h2>{showAttendance ? 'Attendance history' : role === 'student' ? 'My student profile' : 'My children'}</h2><p className="dash-record-subtitle">{role === 'student' ? 'This profile is linked to your signed-in student account.' : 'Only student profiles connected through a parent invitation appear here.'}</p></div></div>
      {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
      {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading linked records…</p> : students.length === 0 ? <p className="master-empty"><UsersRound size={18} />No linked student profile is available for this account yet.</p> : <div className="family-student-list">{students.map(student => <button key={student.id} className={`family-student-card ${student.id === selectedId ? 'family-student-selected' : ''}`} onClick={() => setSelectedId(student.id)}><span><UsersRound size={17} /></span><b>{student.name}</b><small>{student.academic_class?.name ? `${student.academic_class.name} · Section ${student.academic_section?.name ?? '—'}` : `Admission ${student.admission_number}`}</small>{student.academic_session?.name && <small>{student.academic_session.name}</small>}{student.relationship && <small>{student.relationship}{student.is_primary ? ' · Primary contact' : ''}</small>}</button>)}</div>}
    </section>
    {showAttendance && selected && <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">{selected.name.toUpperCase()}</span><h2>Daily records</h2><p className="dash-record-subtitle">Attendance records shared with this student or family account.</p></div></div>
      <div className="attendance-summary"><span><b>{total}</b> records</span><span>Present <b>{counts.present}</b></span><span>Absent <b>{counts.absent}</b></span><span>Late <b>{counts.late}</b></span><span>Excused <b>{counts.excused}</b></span></div>
      {entries.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>DATE</th><th>CLASS</th><th>SESSION</th><th>STATUS</th><th>NOTE</th></tr></thead><tbody>{entries.map(entry => <tr key={entry.id}><td><span className="family-date"><CalendarDays size={14} />{new Date(`${entry.date}T00:00:00`).toLocaleDateString()}</span></td><td>{entry.section.class} · {entry.section.name}</td><td>{entry.session.name}</td><td><span className={`family-attendance-status ${entry.status}`}>{entry.status}</span></td><td>{entry.note || '—'}</td></tr>)}</tbody></table></div> : !error && <p className="master-empty"><ClipboardCheck size={18} />No attendance records have been shared yet.</p>}
    </section>}
  </div>
}
