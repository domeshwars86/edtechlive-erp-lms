import { useCallback, useEffect, useState } from 'react'
import { AlertCircle, Archive, CheckCircle2, ChevronLeft, ChevronRight, LoaderCircle, Mail, Search } from 'lucide-react'
import { api } from '../lib/api'

type EnquiryStatus = 'new' | 'in_progress' | 'resolved' | 'archived'
type Enquiry = { id: number; name: string; email: string; phone: string | null; organization_name: string | null; role: string; category: string; message: string; status: EnquiryStatus; created_at: string; reviewed_at: string | null; reviewer: { id: number; name: string } | null }
type EnquiryPage = { data: Enquiry[]; current_page: number; last_page: number; total: number }
const statuses: { value: EnquiryStatus; label: string }[] = [{ value: 'new', label: 'New' }, { value: 'in_progress', label: 'In progress' }, { value: 'resolved', label: 'Resolved' }, { value: 'archived', label: 'Archived' }]

export default function EnquiryInbox() {
  const [result, setResult] = useState<EnquiryPage | null>(null)
  const [status, setStatus] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [savingId, setSavingId] = useState<number | null>(null)
  const [error, setError] = useState('')

  const refresh = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const params = new URLSearchParams({ per_page: '50' })
      params.set('page', String(page))
      if (status) params.set('status', status)
      if (query.trim()) params.set('q', query.trim())
      setResult(await api.get<EnquiryPage>(`/api/v1/enquiries?${params}`))
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not load enquiries.')
    } finally {
      setLoading(false)
    }
  }, [status, query, page])

  useEffect(() => { void refresh() }, [refresh])

  async function updateStatus(enquiry: Enquiry, next: EnquiryStatus) {
    setSavingId(enquiry.id)
    setError('')
    try {
      await api.patch(`/api/v1/enquiries/${enquiry.id}`, { status: next })
      await refresh()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not update this enquiry.')
    } finally {
      setSavingId(null)
    }
  }

  return <section className="dash-panel dash-inbox-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">PLATFORM CONTACT</span><h2>Enquiry inbox</h2><p className="dash-record-subtitle">Review consented contact requests and track follow-up status. Email replies open in your mail app.</p></div><span className="dash-inbox-count">{result?.total ?? '—'} enquiries</span></div>
    <div className="dash-inbox-toolbar"><label><Search size={15} /><input aria-label="Search enquiries" value={query} onChange={event => { setQuery(event.target.value); setPage(1) }} placeholder="Search name, email, organization…" /></label><select aria-label="Filter enquiry status" value={status} onChange={event => { setStatus(event.target.value); setPage(1) }}><option value="">All statuses</option>{statuses.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}</select></div>
    {error && <div className="dash-inbox-error" role="alert"><AlertCircle size={15} />{error}</div>}
    {loading ? <div className="dash-inbox-empty"><LoaderCircle className="dash-spin" size={18} />Loading enquiries…</div>
      : result?.data.length ? <div className="dash-inbox-list">{result.data.map(enquiry => <article className="dash-inbox-card" key={enquiry.id}>
        <header><div><span className={`dash-enquiry-status status-${enquiry.status}`}>{statuses.find(item => item.value === enquiry.status)?.label}</span><h3>{enquiry.name}</h3><p>{enquiry.email}{enquiry.phone ? ` · ${enquiry.phone}` : ''}</p></div><time dateTime={enquiry.created_at}>{new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(enquiry.created_at))}</time></header>
        <div className="dash-inbox-meta"><span>{enquiry.category}</span><span>{enquiry.role}</span>{enquiry.organization_name && <span>{enquiry.organization_name}</span>}</div>
        <p className="dash-inbox-message">{enquiry.message}</p>
        <footer><span>{enquiry.reviewer ? `Last updated by ${enquiry.reviewer.name}` : 'Not yet reviewed'}</span><div><a className="dash-inbox-action" href={`mailto:${encodeURIComponent(enquiry.email)}?subject=${encodeURIComponent(`Re: edTechLive enquiry #${enquiry.id}`)}`}><Mail size={13} /> Reply</a><select aria-label={`Update ${enquiry.name} enquiry status`} value={enquiry.status} disabled={savingId === enquiry.id} onChange={event => void updateStatus(enquiry, event.target.value as EnquiryStatus)}>{statuses.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}</select>{savingId === enquiry.id && <LoaderCircle className="dash-spin" size={14} />}{enquiry.status === 'resolved' && <CheckCircle2 size={14} />}{enquiry.status === 'archived' && <Archive size={14} />}</div></footer>
      </article>)}</div> : <div className="dash-inbox-empty">No enquiries match these filters.</div>}
    {result && result.last_page > 1 && <div className="dash-inbox-pagination"><button disabled={result.current_page <= 1 || loading} onClick={() => setPage(current => Math.max(1, current - 1))}><ChevronLeft size={13} />Previous</button><span>Page {result.current_page} of {result.last_page} · {result.total} total</span><button disabled={result.current_page >= result.last_page || loading} onClick={() => setPage(current => Math.min(result.last_page, current + 1))}>Next<ChevronRight size={13} /></button></div>}
  </section>
}
