import { useEffect, useMemo, useState } from 'react'
import { AlertCircle, BookOpen, LoaderCircle, UsersRound } from 'lucide-react'
import { api } from '../lib/api'
import AttendanceRegister from './AttendanceRegister'

type Assignment = {
  id: number
  academic_session: { id: number; name: string }
  academic_section: { id: number; name: string; academic_session_id: number; academic_class: { id: number; name: string } }
  subject: { id: number; name: string }
}
type Result = { data: Assignment[] }

export default function TeacherWorkspace({ organizationId, active }: { organizationId: number; active: string }) {
  const base = `/api/v1/organizations/${organizationId}`
  const [assignments, setAssignments] = useState<Assignment[]>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    api.get<Result>(`${base}/teaching-assignments?per_page=100`)
      .then(result => { if (!cancelled) { setAssignments(result.data); setSelectedId(current => current ?? result.data[0]?.id ?? null) } })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load your teaching assignments.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base])

  const selected = assignments.find(item => item.id === selectedId) ?? null
  const sections = useMemo(() => selected ? [{
    ...selected.academic_section,
    academic_session: selected.academic_session,
  }] : [], [selected])

  return <div className="teacher-workspace">
    <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">YOUR ASSIGNED CLASSES</span><h2>{active === 'Attendance' ? 'Take attendance' : 'My classes'}</h2><p className="dash-record-subtitle">Your organization assignments determine which class registers you can open.</p></div></div>
      {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
      {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading your assignments…</p> : assignments.length === 0 ? <p className="master-empty"><UsersRound size={18} />No classes have been assigned to your teacher profile yet.</p> : <div className="teacher-assignment-grid">{assignments.map(item => <button key={item.id} className={`teacher-assignment-card ${item.id === selectedId ? 'teacher-assignment-selected' : ''}`} onClick={() => setSelectedId(item.id)}><span><BookOpen size={17} /></span><b>{item.subject.name}</b><small>{item.academic_section.academic_class.name} · Section {item.academic_section.name}</small><small>{item.academic_session.name}</small></button>)}</div>}
    </section>
    {active === 'Attendance' && selected && <AttendanceRegister key={selected.academic_section.id} organizationId={organizationId} sectionsOverride={sections} initialSectionId={selected.academic_section.id} />}
  </div>
}
