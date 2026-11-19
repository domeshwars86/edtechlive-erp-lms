import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, Coins, LoaderCircle, Pencil, Plus, Search } from 'lucide-react'
import { api } from '../lib/api'

type Category = { id: number; name: string; code: string | null; description: string | null; is_active: boolean }
type Page = { current_page: number; last_page: number; total: number }
type Fields = { name: string; code: string; description: string }
const blank: Fields = { name: '', code: '', description: '' }

export default function FeeCategoryManager({ organizationId }: { organizationId: number }) {
  const base = `/api/v1/organizations/${organizationId}/fee-categories`
  const [categories, setCategories] = useState<Category[]>([])
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
      const result = await api.get<{ data: Category[]; meta: Page }>(`${base}?${query}`)
      setCategories(result.data)
      setMeta(result.meta)
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not load fee categories.') } finally { setLoading(false) }
  }, [base, page, search, status])

  useEffect(() => { void load() }, [load])

  function edit(category: Category) {
    setEditingId(category.id)
    setFields({ name: category.name, code: category.code ?? '', description: category.description ?? '' })
    setMessage('')
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true); setError(''); setMessage('')
    const payload = { name: fields.name.trim(), code: fields.code.trim() || null, description: fields.description.trim() || null }
    try {
      if (editingId) await api.patch(`${base}/${editingId}`, payload)
      else await api.post(base, payload)
      setMessage(editingId ? 'Fee category updated.' : 'Fee category created.')
      setEditingId(null); setFields(blank)
      await load()
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not save this fee category.') } finally { setSaving(false) }
  }

  async function toggle(category: Category) {
    setSaving(true); setError(''); setMessage('')
    try {
      await api.patch(`${base}/${category.id}`, { is_active: !category.is_active })
      setMessage(`${category.name} ${category.is_active ? 'deactivated' : 'activated'}.`)
      await load()
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not update category status.') } finally { setSaving(false) }
  }

  return <div className="fee-category-manager">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    <div className="master-content">
      <form className="master-form" onSubmit={event => void save(event)}>
        <h3><Plus size={17} />{editingId ? 'Edit fee category' : 'Add fee category'}</h3>
        <label>Category name<input required minLength={2} maxLength={120} value={fields.name} onChange={event => setFields(current => ({ ...current, name: event.target.value }))} placeholder="Tuition" /></label>
        <label>Code <span>(optional)</span><input maxLength={32} value={fields.code} onChange={event => setFields(current => ({ ...current, code: event.target.value }))} placeholder="TUITION" /></label>
        <label>Description <span>(optional)</span><textarea rows={3} maxLength={500} value={fields.description} onChange={event => setFields(current => ({ ...current, description: event.target.value }))} placeholder="Annual tuition fees" /></label>
        <div className="batch-form-actions"><button className="master-submit" disabled={saving}>{saving ? <><LoaderCircle className="master-spin" size={16} />Saving…</> : <><Plus size={16} />{editingId ? 'Save changes' : 'Create category'}</>}</button>{editingId && <button type="button" className="master-record-action" onClick={() => { setEditingId(null); setFields(blank) }}>Cancel</button>}</div>
      </form>
      <div className="master-list">
        <div className="master-list-heading"><div><h3>Fee categories</h3><span>{meta.total} record{meta.total === 1 ? '' : 's'}</span></div></div>
        <form className="batch-search" onSubmit={event => { event.preventDefault(); setPage(1); setSearch(searchInput.trim()) }}><label><Search size={15} /><input aria-label="Search fee categories" value={searchInput} onChange={event => setSearchInput(event.target.value)} placeholder="Search name or code" /></label><button type="submit">Search</button></form>
        <div className="batch-filters"><label>Status<select value={status} onChange={event => { setStatus(event.target.value); setPage(1) }}><option value="">All statuses</option><option value="1">Active</option><option value="0">Inactive</option></select></label></div>
        {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading categories…</p> : categories.length === 0 ? <p className="master-empty"><Coins size={18} />No fee categories match these filters.</p> : <ul>{categories.map(category => <li key={category.id}><span className="master-record-icon"><Coins size={17} /></span><span className="master-record-main"><b>{category.name}{category.code ? ` · ${category.code}` : ''}</b><small>{category.description || 'No description'}</small></span><span className={`master-status ${category.is_active ? 'active' : ''}`}>{category.is_active ? 'Active' : 'Inactive'}</span><button className="master-record-action" type="button" disabled={saving} onClick={() => edit(category)} aria-label={`Edit ${category.name}`}><Pencil size={14} />Edit</button><button className="master-record-action" type="button" disabled={saving} onClick={() => void toggle(category)}>{category.is_active ? 'Deactivate' : 'Activate'}</button></li>)}</ul>}
        {meta.last_page > 1 && <div className="dash-table-footer"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage(value => value - 1)}>Previous</button><span>Page {page} of {meta.last_page}</span><button type="button" disabled={page >= meta.last_page || loading} onClick={() => setPage(value => value + 1)}>Next</button></div>}
      </div>
    </div>
  </div>
}
