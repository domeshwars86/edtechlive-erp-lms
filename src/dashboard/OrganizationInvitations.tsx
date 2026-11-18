import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { AlertCircle, Check, LoaderCircle, MailPlus, RefreshCw, Trash2 } from 'lucide-react'
import { api } from '../lib/api'

type Invitation = { id: number; email: string; role: string; status: 'pending' | 'accepted' | 'expired'; expires_at: string; created_at: string }
type StudentProfile = { id: number; name: string; admission_number: string }
type Result<T> = { data: T[]; meta?: { total: number } }

export default function OrganizationInvitations({ organizationId }: { organizationId: number }) {
  const base = `/api/v1/organizations/${organizationId}/invitations`
  const [invitations, setInvitations] = useState<Invitation[]>([])
  const [students, setStudents] = useState<StudentProfile[]>([])
  const [role, setRole] = useState('teacher')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const load = useCallback(async () => {
    try {
      const [result, roster] = await Promise.all([
        api.get<Result<Invitation>>(`${base}?per_page=100`),
        api.get<Result<StudentProfile>>(`${base.replace(/\/invitations$/, '')}/students?per_page=100&status=active`),
      ])
      setInvitations(result.data); setStudents(roster.data)
    }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not load invitations.') }
    finally { setLoading(false) }
  }, [base])

  useEffect(() => { void load() }, [load])

  async function invite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(''); setMessage('')
    const form = event.currentTarget
    const values = new FormData(form)
    try {
      await api.post(base, { email: values.get('email'), role: values.get('role'), student_ids: role === 'parent' ? values.getAll('student_ids').map(Number) : undefined })
      setMessage('Invitation sent. It expires in seven days.')
      form.reset(); setRole('teacher'); await load()
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not send this invitation.') }
    finally { setBusy(false) }
  }

  async function revoke(id: number) {
    setError(''); setMessage('')
    try { await api.delete(`${base}/${id}`); setMessage('Invitation revoked.'); await load() }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not revoke this invitation.') }
  }

  return <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">ORGANIZATION ACCESS</span><h2>Invitations</h2><p className="dash-record-subtitle">Invite staff, learners, families, and management to this workspace.</p></div><button className="dash-icon-button" onClick={() => { setLoading(true); void load() }} aria-label="Refresh invitations"><RefreshCw size={16} /></button></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}{message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    <div className="master-content"><form className="master-form" onSubmit={invite}><h3><MailPlus size={17} />Invite a member</h3><label>Email address<input name="email" type="email" required maxLength={254} placeholder="person@example.com" /></label><label>Organization role<select name="role" value={role} onChange={event => setRole(event.target.value)}><option value="teacher">Teacher</option><option value="student">Student</option><option value="parent">Parent or guardian</option><option value="management">Management</option></select></label>{role === 'parent' && <label>Link to student profiles <span>(choose one or more)</span><select name="student_ids" multiple required size={Math.min(Math.max(students.length, 3), 6)}>{students.map(student => <option key={student.id} value={student.id}>{student.name} · {student.admission_number}</option>)}</select></label>}{role === 'student' && <small className="invitation-help">Use the email address on the student’s active roster profile.</small>}<button className="master-submit" disabled={busy}>{busy ? <><LoaderCircle className="master-spin" size={16} />Sending…</> : <><MailPlus size={16} />Send invitation</>}</button></form>
      <div className="master-list"><div className="master-list-heading"><div><h3>Recent invitations</h3><span>{invitations.length} listed</span></div></div>{loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading invitations…</p> : invitations.length === 0 ? <p className="master-empty"><MailPlus size={18} />No invitations sent yet.</p> : <ul>{invitations.map(invitation => <li key={invitation.id}><span className="master-record-icon"><MailPlus size={17} /></span><span className="master-record-main"><b>{invitation.email}</b><small>{invitation.role.replaceAll('_', ' ')} · {invitation.status}{invitation.status === 'pending' ? ` · expires ${new Date(invitation.expires_at).toLocaleDateString()}` : ''}</small></span>{invitation.status === 'pending' && <button className="master-record-action" onClick={() => void revoke(invitation.id)} aria-label={`Revoke invitation to ${invitation.email}`}><Trash2 size={15} /></button>}</li>)}</ul>}</div>
    </div>
  </section>
}
