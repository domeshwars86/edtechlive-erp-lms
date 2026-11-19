import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Building2, Check, LoaderCircle, Pencil, Plus, Search } from 'lucide-react'
import { api } from '../lib/api'

type Branch = { id: number; name: string; code: string | null; address: string | null; contact_email: string | null; contact_phone: string | null; is_active: boolean }
type Page = { current_page: number; last_page: number; total: number }
type Fields = { name: string; code: string; address: string; contact_email: string; contact_phone: string }
const blank: Fields = { name: '', code: '', address: '', contact_email: '', contact_phone: '' }

export default function BranchManager({ organizationId }: { organizationId: number }) {
  const base = `/api/v1/organizations/${organizationId}/branches`
  const [branches, setBranches] = useState<Branch[]>([])
  const [fields, setFields] = useState<Fields>(blank)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState<Page>({ current_page: 1, last_page: 1, total: 0 })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const query = new URLSearchParams({ page: String(page), per_page: '20' })
      if (search) query.set('search', search)
      if (status) query.set('is_active', status)
      const result = await api.get<{ data: Branch[]; meta: Page }>(`${base}?${query}`)
      setBranches(result.data)
      setMeta(result.meta)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not load branches.')
    } finally { setLoading(false) }
  }, [base, page, search, status])

  useEffect(() => { void load() }, [load])

  function edit(branch: Branch) {
    setEditingId(branch.id)
    setFields({ name: branch.name, code: branch.code ?? '', address: branch.address ?? '', contact_email: branch.contact_email ?? '', contact_phone: branch.contact_phone ?? '' })
    setMessage('')
  }

  function cancelEdit() { setEditingId(null); setFields(blank) }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setError('')
    setMessage('')
    const payload = { name: fields.name.trim(), code: fields.code.trim() || null, address: fields.address.trim() || null, contact_email: fields.contact_email.trim() || null, contact_phone: fields.contact_phone.trim() || null }
    try {
      if (editingId) await api.patch(`${base}/${editingId}`, payload)
      else await api.post(base, payload)
      setMessage(editingId ? 'Branch updated.' : 'Branch created.')
      setEditingId(null)
      setFields(blank)
      await load()
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not save this branch.') } finally { setSaving(false) }
  }

  async function toggle(branch: Branch) {
    setSaving(true)
    setError('')
    setMessage('')
    try {
      await api.patch(`${base}/${branch.id}`, { is_active: !branch.is_active })
      setMessage(`${branch.name} ${branch.is_active ? 'deactivated' : 'activated'}.`)
      await load()
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not update branch status.') } finally { setSaving(false) }
  }

  return <div className="branch-manager">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    <div className="master-content">
      <form className="master-form" onSubmit={event => void save(event)}>
        <h3><Plus size={17} />{editingId ? 'Edit branch' : 'Add branch'}</h3>
        <label>Branch name<input required minLength={2} maxLength={120} value={fields.name} onChange={event => setFields(current => ({ ...current, name: event.target.value }))} placeholder="Central campus" /></label>
        <label>Code <span>(optional)</span><input maxLength={32} value={fields.code} onChange={event => setFields(current => ({ ...current, code: event.target.value }))} placeholder="CENTRAL" /></label>
        <label>Address <span>(optional)</span><textarea maxLength={500} rows={3} value={fields.address} onChange={event => setFields(current => ({ ...current, address: event.target.value }))} /></label>
        <label>Contact email <span>(optional)</span><input type="email" maxLength={254} value={fields.contact_email} onChange={event => setFields(current => ({ ...current, contact_email: event.target.value }))} /></label>
        <label>Contact phone <span>(optional)</span><input type="tel" maxLength={32} value={fields.contact_phone} onChange={event => setFields(current => ({ ...current, contact_phone: event.target.value }))} /></label>
        <div className="batch-form-actions"><button className="master-submit" disabled={saving}>{saving ? <><LoaderCircle className="master-spin" size={16} />Saving…</> : <><Plus size={16} />{editingId ? 'Save changes' : 'Create branch'}</>}</button>{editingId && <button type="button" className="master-record-action" onClick={cancelEdit}>Cancel</button>}</div>
      </form>
      <div className="master-list">
        <div className="master-list-heading"><div><h3>Organization branches</h3><span>{meta.total} record{meta.total === 1 ? '' : 's'}</span></div></div>
        <form className="batch-search" onSubmit={event => { event.preventDefault(); setPage(1); setSearch(searchInput.trim()) }}><label><Search size={15} /><input aria-label="Search branches" value={searchInput} onChange={event => setSearchInput(event.target.value)} placeholder="Search name or code" /></label><button type="submit">Search</button></form>
        <div className="batch-filters"><label>Status<select value={status} onChange={event => { setStatus(event.target.value); setPage(1) }}><option value="">All statuses</option><option value="1">Active</option><option value="0">Inactive</option></select></label></div>
        {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading branches…</p> : branches.length === 0 ? <p className="master-empty"><Building2 size={18} />No branches match these filters.</p> : <ul>{branches.map(branch => <li key={branch.id}><span className="master-record-icon"><Building2 size={17} /></span><span className="master-record-main"><b>{branch.name}{branch.code ? ` · ${branch.code}` : ''}</b><small>{[branch.address, branch.contact_email, branch.contact_phone].filter(Boolean).join(' · ') || 'No contact details'}</small></span><span className={`master-status ${branch.is_active ? 'active' : ''}`}>{branch.is_active ? 'Active' : 'Inactive'}</span><button className="master-record-action" type="button" disabled={saving} onClick={() => edit(branch)} aria-label={`Edit ${branch.name}`}><Pencil size={14} />Edit</button><button className="master-record-action" type="button" disabled={saving} onClick={() => void toggle(branch)}>{branch.is_active ? 'Deactivate' : 'Activate'}</button></li>)}</ul>}
        {meta.last_page > 1 && <div className="dash-table-footer"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage(value => value - 1)}>Previous</button><span>Page {page} of {meta.last_page}</span><button type="button" disabled={page >= meta.last_page || loading} onClick={() => setPage(value => value + 1)}>Next</button></div>}
      </div>
    </div>
  </div>
}
