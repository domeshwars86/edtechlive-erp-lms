import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, CalendarDays, Check, ExternalLink, FileVideo, LoaderCircle, Plus, Upload, Video } from 'lucide-react'
import { api } from '../lib/api'

type Section = { id: number; name: string; academic_session_id: number; academic_class?: { name: string } }
type Meeting = { id: number; kind: 'class' | 'management'; title: string; description: string | null; starts_at: string; ends_at: string; meeting_url: string; status: 'scheduled' | 'cancelled'; section: { id: number; name: string; class: string } | null; course: { id: number; title: string } | null; creator: { id: number; name: string } | null }
type ListResult<T> = { data: T[] }
type Recording = { id: number; title: string; description: string | null; filename: string | null; size_bytes: number | null; external_url: string | null; download_url: string | null; status: 'draft' | 'published' | 'withdrawn'; can_manage: boolean }

export default function MeetingWorkspace({ organizationId, mode, userId }: { organizationId: number; mode: 'manage' | 'teacher' | 'family'; userId?: number }) {
  const base = `/api/v1/organizations/${organizationId}`
  const canManage = mode === 'manage' || mode === 'teacher'
  const [meetings, setMeetings] = useState<Meeting[]>([])
  const [sections, setSections] = useState<Section[]>([])
  const [meetingKind, setMeetingKind] = useState<'class' | 'management'>('class')
  const [sectionId, setSectionId] = useState('')
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [meetingUrl, setMeetingUrl] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  async function refresh() {
    const result = await api.get<ListResult<Meeting>>(`${base}/meetings?per_page=100`)
    setMeetings(result.data)
  }

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    const meetingsRequest = api.get<ListResult<Meeting>>(`${base}/meetings?per_page=100`)
    const setupRequest = canManage ? Promise.all([
      api.get<ListResult<{ id: number; name: string }>>(`${base}/classes?per_page=100`),
    ]).then(async ([classResult]) => Promise.all(classResult.data.map(async item => {
      const result = await api.get<ListResult<Section>>(`${base}/classes/${item.id}/sections?per_page=100`)
      return result.data.filter(section => section.academic_session_id !== null).map(section => ({ ...section, academic_class: { name: item.name } }))
    })).then(items => items.flat())) : Promise.resolve([] as Section[])
    Promise.all([meetingsRequest, setupRequest])
      .then(([meetingResult, sectionResult]) => {
        if (!cancelled) { setMeetings(meetingResult.data); setSections(sectionResult) }
      })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load meetings.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base, canManage])

  async function createMeeting(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true); setError(''); setMessage('')
    const section = sections.find(item => String(item.id) === sectionId)
    try {
      await api.post(`${base}/meetings`, {
        kind: mode === 'teacher' ? 'class' : meetingKind,
        academic_section_id: meetingKind === 'class' && section ? section.id : null,
        academic_session_id: meetingKind === 'class' && section ? section.academic_session_id : null,
        title: title.trim(), description: description.trim() || null,
        starts_at: new Date(startsAt).toISOString(), ends_at: new Date(endsAt).toISOString(),
        meeting_url: meetingUrl.trim(),
      })
      setTitle(''); setDescription(''); setMeetingUrl(''); setSectionId(''); setMessage('Meeting scheduled.')
      await refresh()
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not schedule the meeting.') } finally { setSaving(false) }
  }

  async function cancelMeeting(meeting: Meeting) {
    setSaving(true); setError(''); setMessage('')
    try {
      await api.patch(`${base}/meetings/${meeting.id}`, { status: 'cancelled' })
      await refresh(); setMessage('Meeting cancelled.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not cancel the meeting.') } finally { setSaving(false) }
  }

  return <div className="meeting-workspace">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    {canManage && <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">LIVE CLASSES & MEETINGS</span><h2>Schedule a meeting</h2><p className="dash-record-subtitle">Add a secure meeting link from Google Meet or another HTTPS provider. The platform does not create provider rooms.</p></div></div><form className="meeting-create-form" onSubmit={event => void createMeeting(event)}>
      {mode === 'manage' && <label>Meeting type<select value={meetingKind} onChange={event => setMeetingKind(event.target.value as 'class' | 'management')}><option value="class">Class meeting</option><option value="management">Management meeting</option></select></label>}
      {(mode === 'teacher' || meetingKind === 'class') && <label>Class section<select value={sectionId} onChange={event => setSectionId(event.target.value)} required><option value="">Select a section</option>{sections.map(section => <option key={section.id} value={section.id}>{section.academic_class?.name} · {section.name}</option>)}</select></label>}
      <label>Meeting title<input value={title} onChange={event => setTitle(event.target.value)} required minLength={2} maxLength={160} /></label>
      <label>Description<input value={description} onChange={event => setDescription(event.target.value)} maxLength={5000} /></label>
      <label>Starts at<input type="datetime-local" value={startsAt} onChange={event => setStartsAt(event.target.value)} required /></label>
      <label>Ends at<input type="datetime-local" value={endsAt} onChange={event => setEndsAt(event.target.value)} required /></label>
      <label>Meeting URL<input type="url" value={meetingUrl} onChange={event => setMeetingUrl(event.target.value)} placeholder="https://meet.google.com/…" pattern="https://.+" required /></label>
      <button className="master-submit" disabled={saving || (meetingKind === 'class' && sections.length === 0)}><Plus size={15} />{saving ? 'Saving…' : 'Schedule meeting'}</button>
    </form></section>}
    <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">{mode === 'family' ? 'YOUR SCHEDULE' : 'MEETING SCHEDULE'}</span><h2>{mode === 'family' ? 'Upcoming class meetings' : 'Scheduled meetings'}</h2><p className="dash-record-subtitle">Only meetings available to your organization role and class links are shown.</p></div></div>
      {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading meetings…</p> : meetings.length === 0 ? <p className="master-empty"><Video size={18} />No meetings are scheduled for this workspace.</p> : <div className="meeting-list">{meetings.map(meeting => { const mayCancel = meeting.status === 'scheduled' && (mode === 'manage' || (mode === 'teacher' && meeting.creator?.id === userId)); const mayAddRecording = mode === 'manage' || (mode === 'teacher' && meeting.creator?.id === userId); return <article className={`meeting-card ${meeting.status === 'cancelled' ? 'meeting-cancelled' : ''}`} key={meeting.id}><header><div><span className={`meeting-status meeting-${meeting.status}`}>{meeting.status}</span><h3>{meeting.title}</h3><p>{meeting.kind === 'management' ? 'Management meeting' : meeting.section ? `${meeting.section.class} · Section ${meeting.section.name}` : meeting.course?.title ?? 'Class meeting'}</p></div><CalendarDays size={20} /></header>{meeting.description && <p>{meeting.description}</p>}<div className="meeting-dates"><span>{new Date(meeting.starts_at).toLocaleString()}</span><span>to {new Date(meeting.ends_at).toLocaleTimeString()}</span></div><div className="meeting-actions">{meeting.status === 'scheduled' && <a href={meeting.meeting_url} target="_blank" rel="noreferrer">Join meeting <ExternalLink size={14} /></a>}{mayCancel && <button type="button" disabled={saving} onClick={() => void cancelMeeting(meeting)}>Cancel meeting</button>}</div><MeetingRecordings organizationId={organizationId} meeting={meeting} canUpload={mayAddRecording} /></article> })}</div>}
    </section>
  </div>
}

function MeetingRecordings({ organizationId, meeting, canUpload }: { organizationId: number; meeting: Meeting; canUpload: boolean }) {
  const base = `/api/v1/organizations/${organizationId}/meetings/${meeting.id}/recordings`
  const [recordings, setRecordings] = useState<Recording[]>([])
  const [title, setTitle] = useState('')
  const [externalUrl, setExternalUrl] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [consentConfirmed, setConsentConfirmed] = useState(false)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  async function refresh() {
    const result = await api.get<ListResult<Recording>>(base)
    setRecordings(result.data)
  }

  useEffect(() => {
    let cancelled = false
    api.get<ListResult<Recording>>(base).then(result => { if (!cancelled) setRecordings(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load recordings.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base])

  async function addRecording(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true); setError('')
    const form = new FormData()
    form.set('title', title.trim())
    form.set('consent_confirmed', consentConfirmed ? '1' : '0')
    if (file) form.set('file', file)
    if (externalUrl.trim()) form.set('external_url', externalUrl.trim())
    try {
      await api.postForm(`${base}`, form)
      setTitle(''); setExternalUrl(''); setFile(null); setConsentConfirmed(false)
      await refresh()
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not add recording.') } finally { setSaving(false) }
  }

  async function setStatus(recording: Recording, status: Recording['status']) {
    setSaving(true); setError('')
    try { await api.patch(`${base}/${recording.id}`, { status }); await refresh() }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not update recording.') }
    finally { setSaving(false) }
  }

  return <section className="meeting-recordings" aria-label={`Recordings for ${meeting.title}`}>
    <h4><FileVideo size={15} /> Class recordings</h4>
    {error && <p className="meeting-recording-error" role="alert">{error}</p>}
    {loading ? <p className="meeting-recording-empty">Loading recordings…</p> : recordings.length === 0 ? <p className="meeting-recording-empty">No recordings are available.</p> : <ul>{recordings.map(recording => <li key={recording.id}>
      <div><strong>{recording.title}</strong><span>{recording.status}{recording.filename ? ` · ${recording.filename}` : ''}</span>{recording.description && <small>{recording.description}</small>}</div>
      <div className="meeting-recording-actions">
        {recording.download_url && <a href={recording.download_url} target="_blank" rel="noreferrer">Open video <ExternalLink size={13} /></a>}
        {recording.external_url && <a href={recording.external_url} target="_blank" rel="noreferrer">Open recording <ExternalLink size={13} /></a>}
        {recording.can_manage && recording.status === 'draft' && <button type="button" disabled={saving} onClick={() => void setStatus(recording, 'published')}>Publish</button>}
        {recording.can_manage && recording.status === 'published' && <button type="button" disabled={saving} onClick={() => void setStatus(recording, 'draft')}>Unpublish</button>}
        {recording.can_manage && recording.status !== 'withdrawn' && <button type="button" disabled={saving} onClick={() => void setStatus(recording, 'withdrawn')}>Withdraw</button>}
      </div>
    </li>)}</ul>}
    {canUpload && <form className="meeting-recording-form" onSubmit={event => void addRecording(event)}>
      <label>Recording title<input value={title} onChange={event => setTitle(event.target.value)} required minLength={2} maxLength={180} /></label>
      <label>Video file (MP4, MOV or WebM, up to 50 MB)<input type="file" accept="video/mp4,video/quicktime,video/webm" onChange={event => setFile(event.target.files?.[0] ?? null)} /></label>
      <label>Or external HTTPS recording link<input type="url" value={externalUrl} onChange={event => setExternalUrl(event.target.value)} placeholder="https://…" pattern="https://.+" /></label>
      <label className="meeting-recording-consent"><input type="checkbox" checked={consentConfirmed} onChange={event => setConsentConfirmed(event.target.checked)} required />I confirm participants were notified and applicable consent was obtained. The platform records this attestation; it does not collect participants’ consent.</label>
      <button type="submit" disabled={saving || !consentConfirmed || Boolean(file && externalUrl.trim())}><Upload size={14} />{saving ? 'Saving…' : 'Add private recording'}</button>
    </form>}
  </section>
}
