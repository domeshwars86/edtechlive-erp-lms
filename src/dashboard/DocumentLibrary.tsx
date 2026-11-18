import { useEffect, useMemo, useState } from 'react'
import { Download, FileText, FolderOpen, LoaderCircle, RefreshCw, Search } from 'lucide-react'
import { api } from '../lib/api'

type DocumentAsset = {
  id: number
  title: string
  filename: string
  mime_type: string
  size_bytes: number
  is_published: boolean
  download_url: string
  course: { id: number; title: string }
}
type DocumentPage = { data: DocumentAsset[]; meta: { current_page: number; last_page: number; total: number } }

export default function DocumentLibrary({ organizationId }: { organizationId: number }) {
  const [assets, setAssets] = useState<DocumentAsset[]>([])
  const [query, setQuery] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [refresh, setRefresh] = useState(0)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    api.get<DocumentPage>(`/api/v1/organizations/${organizationId}/documents?per_page=100&page=${page}`)
      .then((result) => {
        if (cancelled) return
        setAssets((current) => page === 1 ? result.data : [...current, ...result.data])
        setLastPage(result.meta.last_page)
        setTotal(result.meta.total)
      })
      .catch((reason: unknown) => {
        if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load study materials.')
      })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [organizationId, page, refresh])

  const filtered = useMemo(() => {
    const search = query.trim().toLocaleLowerCase()
    return search ? assets.filter((asset) => `${asset.title} ${asset.filename} ${asset.course.title}`.toLocaleLowerCase().includes(search)) : assets
  }, [assets, query])

  function downloadUrl(path: string): string {
    const apiBase = import.meta.env.VITE_API_URL ?? ''
    return apiBase ? new URL(path, apiBase).toString() : path
  }

  return <section className="dash-panel dash-record-panel document-library">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">STUDY MATERIALS</span><h2>Documents</h2><p className="dash-record-subtitle">Files are private until a course editor publishes them.</p></div><button className="dash-icon-button" type="button" onClick={() => { setAssets([]); setPage(1); setRefresh((value) => value + 1) }} aria-label="Refresh documents"><RefreshCw size={16} /></button></div>
    <div className="dash-record-toolbar document-search"><label><Search size={15} /><input aria-label="Search documents" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search title, filename, or course…" /></label><span>{assets.length} of {total} files</span></div>
    {error && <div className="document-message document-error" role="alert">{error}</div>}
    {loading && assets.length === 0 ? <div className="document-message"><LoaderCircle className="master-spin" size={18} />Loading documents…</div>
      : filtered.length ? <div className="document-list">{filtered.map((asset) => <article className="document-card" key={asset.id}><span className="document-icon"><FileText size={18} /></span><div className="document-info"><b>{asset.title}</b><small>{asset.course.title} · {asset.filename} · {Math.max(1, Math.ceil(asset.size_bytes / 1024))} KB</small></div><span className={`document-visibility ${asset.is_published ? 'document-published' : ''}`}>{asset.is_published ? 'Shared' : 'Private'}</span><a className="document-download" href={downloadUrl(asset.download_url)} aria-label={`Download ${asset.title}`}><Download size={16} /><span>Download</span></a></article>)}</div>
        : !error && <div className="document-message document-empty"><FolderOpen size={20} /><b>{query ? 'No documents match this search.' : 'No course materials are available yet.'}</b><span>{query ? 'Try another title, filename, or course name.' : 'Published course files will appear here.'}</span></div>}
    {page < lastPage && <div className="document-more"><button type="button" className="master-action" disabled={loading} onClick={() => setPage((value) => value + 1)}>{loading ? 'Loading…' : 'Load more documents'}</button></div>}
  </section>
}
