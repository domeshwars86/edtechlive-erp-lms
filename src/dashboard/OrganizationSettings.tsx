import { useEffect, useState, type FormEvent } from 'react'
import { AlertCircle, Check, LoaderCircle, Save } from 'lucide-react'
import { api } from '../lib/api'

type SettingsResponse = { data: { membership_id: number; role: string; organization: { id: number; name: string; slug: string; type: string; status: string; timezone: string } } }
type UpdatedOrganization = { data: { id: number; name: string; slug: string; type: string; status: string; timezone: string } }

export default function OrganizationSettings({ organizationId, onSaved }: { organizationId: number; onSaved: (name: string) => void }) {
  const [name, setName] = useState('')
  const [timezone, setTimezone] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    api.get<SettingsResponse>(`/api/v1/organizations/${organizationId}`)
      .then((result) => {
        if (cancelled) return
        setName(result.data.organization.name)
        setTimezone(result.data.organization.timezone)
      })
      .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load organization settings.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [organizationId])

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setError('')
    setMessage('')
    try {
      const result = await api.patch<UpdatedOrganization>(`/api/v1/organizations/${organizationId}/settings`, { name: name.trim(), timezone: timezone.trim() })
      setName(result.data.name)
      setTimezone(result.data.timezone)
      onSaved(result.data.name)
      setMessage('Organization settings saved.')
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not save organization settings.')
    } finally {
      setSaving(false)
    }
  }

  return <section className="dash-panel dash-masters-panel organization-settings">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">WORKSPACE CONFIGURATION</span><h2>Organization settings</h2><p className="dash-record-subtitle">Set the name and timezone used throughout this organization’s schedules and reports.</p></div></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading settings…</p> : <form className="organization-settings-form" onSubmit={(event) => void save(event)}>
      <label>Organization name<input value={name} onChange={(event) => setName(event.target.value)} required minLength={2} maxLength={180} /></label>
      <label>Timezone<input value={timezone} onChange={(event) => setTimezone(event.target.value)} required maxLength={100} placeholder="Asia/Kolkata" /></label>
      <p>Use an IANA timezone such as <code>Asia/Kolkata</code> or <code>America/New_York</code>. Dates in attendance, timetables, and reports use this timezone.</p>
      <button className="master-submit" type="submit" disabled={saving || !name.trim() || !timezone.trim()}><Save size={15} />{saving ? 'Saving…' : 'Save settings'}</button>
    </form>}
  </section>
}
