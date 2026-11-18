import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, LoaderCircle, Megaphone, Send } from 'lucide-react'
import { api } from '../lib/api'

type Audience = 'all' | 'staff' | 'teachers' | 'students' | 'parents'
type Announcement = { id: number; title: string; body: string; audience: Audience; recipient_count: number; sent_at: string }
const audienceLabels: Record<Audience, string> = { all: 'All active members', staff: 'Staff and teachers', teachers: 'Teachers', students: 'Students', parents: 'Parents and guardians' }

export default function AnnouncementWorkspace({ organizationId }: { organizationId: number }) {
  const base = `/api/v1/organizations/${organizationId}/announcements`
  const [announcements, setAnnouncements] = useState<Announcement[]>([])
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [audience, setAudience] = useState<Audience>('all')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  useEffect(() => {
    let cancelled = false
    api.get<{ data: Announcement[] }>(`${base}?per_page=50`)
      .then(result => { if (!cancelled) setAnnouncements(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load announcements.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base])

  async function publish(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.post<{ data: Announcement }>(base, { title: title.trim(), body: body.trim(), audience })
      setAnnouncements(current => [result.data, ...current].slice(0, 50))
      setTitle(''); setBody('')
      setMessage(`Announcement sent to ${result.data.recipient_count} active members.`)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not publish this announcement.') } finally { setSaving(false) }
  }

  return <div className="announcement-workspace">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">INSTITUTION COMMUNICATION</span><h2>Publish an announcement</h2><p className="dash-record-subtitle">Send an in-app notice to active members in this organization.</p></div></div>
      <form className="announcement-form" onSubmit={event => void publish(event)}>
        <label>Audience<select value={audience} onChange={event => setAudience(event.target.value as Audience)}>{Object.entries(audienceLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label>
        <label>Title<input value={title} onChange={event => setTitle(event.target.value)} required minLength={2} maxLength={120} /></label>
        <label>Message<textarea value={body} onChange={event => setBody(event.target.value)} required minLength={2} maxLength={4000} rows={4} /></label>
        <button className="master-submit" disabled={saving}><Send size={15} />{saving ? 'Sending…' : 'Send announcement'}</button>
      </form>
    </section>
    <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">SENT HISTORY</span><h2>Recent announcements</h2></div></div>
      {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading announcements…</p> : announcements.length ? <div className="announcement-list">{announcements.map(item => <article className="announcement-card" key={item.id}><header><h3>{item.title}</h3><span>{item.recipient_count} recipients</span></header><p>{item.body}</p><footer><span>{audienceLabels[item.audience]}</span><time dateTime={item.sent_at}>{new Date(item.sent_at).toLocaleString()}</time></footer></article>)}</div> : <p className="master-empty"><Megaphone size={18} />No announcements have been sent.</p>}
    </section>
  </div>
}
