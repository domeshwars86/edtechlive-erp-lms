import { useEffect, useState } from 'react'
import { AlertCircle, LoaderCircle } from 'lucide-react'
import { api } from '../lib/api'

type Summary = { fee_records: number; unpaid_records: number; partial_records: number; paid_records: number; issued_amount: string; received_amount: string; outstanding_amount: string; overdue_records: number; overdue_balance: string }
type Report = { as_of: string; currency: 'INR'; collections_period: { from: string; to: string; receipt_count: number; received_amount: string }; summary: Summary }
const money = (value: string) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 }).format(Number(value))
const dateValue = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`

export default function FinanceSummary({ organizationId }: { organizationId: number }) {
  const today = new Date()
  const prior = new Date(today)
  prior.setDate(prior.getDate() - 29)
  const [from, setFrom] = useState(dateValue(prior))
  const [to, setTo] = useState(dateValue(today))
  const [report, setReport] = useState<Report | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    setLoading(true); setError('')
    api.get<{ data: Report }>(`/api/v1/organizations/${organizationId}/reports/finance?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)
      .then(result => { if (!cancelled) setReport(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load finance summary.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [organizationId, from, to])

  return <section className="dash-panel dash-masters-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">FINANCE OVERVIEW</span><h2>Fee ledger summary</h2><p className="dash-record-subtitle">Manual fee records and receipts across this organization · totals as of {report?.as_of ?? 'today'}.</p></div></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    <div className="report-filters"><label>Receipts from<input type="date" value={from} max={to} onChange={event => setFrom(event.target.value)} /></label><label>Through<input type="date" value={to} min={from} onChange={event => setTo(event.target.value)} /></label><span>{report && !loading ? `${money(report.collections_period.received_amount)} received · ${report.collections_period.receipt_count} receipts in range` : 'Choose a date range to review recorded receipts.'}</span></div>
    {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading finance summary…</p> : report && <>
      <div className="report-stat-grid"><article><small>ISSUED</small><b>{money(report.summary.issued_amount)}</b><small>{report.summary.fee_records} fee records</small></article><article><small>RECEIVED</small><b>{money(report.summary.received_amount)}</b><small>{report.summary.paid_records} paid in full</small></article><article><small>OUTSTANDING</small><b>{money(report.summary.outstanding_amount)}</b><small>{report.summary.unpaid_records} unpaid · {report.summary.partial_records} partial</small></article><article><small>OVERDUE BALANCE</small><b>{money(report.summary.overdue_balance)}</b><small>{report.summary.overdue_records} overdue records</small></article></div>
      <p className="dash-record-subtitle">This report summarizes staff-entered receipts. It does not initiate or reconcile bank, card, or gateway payments.</p>
    </>}
  </section>
}
