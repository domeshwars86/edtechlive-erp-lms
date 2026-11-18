import { useEffect, useState } from 'react'
import { AlertCircle, Download, LoaderCircle } from 'lucide-react'
import { api } from '../lib/api'
import { downloadCsv } from '../lib/csv'

type Summary = { fee_records: number; unpaid_records: number; partial_records: number; paid_records: number; issued_amount: string; received_amount: string; expense_amount: string; net_cash_flow: string; outstanding_amount: string; overdue_records: number; overdue_balance: string }
type Report = { as_of: string; currency: 'INR'; collections_period: { from: string; to: string; receipt_count: number; received_amount: string; expense_count: number; expense_amount: string; net_cash_flow: string }; summary: Summary }
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
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">FINANCE OVERVIEW</span><h2>Fee ledger summary</h2><p className="dash-record-subtitle">Manual fee records and receipts across this organization · totals as of {report?.as_of ?? 'today'}.</p></div><button className="dash-secondary-button" type="button" disabled={loading || !report} onClick={() => report && downloadCsv(`finance-summary-${from}-to-${to}.csv`, ['Scope', 'Metric', 'Count', 'Amount (INR)', 'From', 'Through'], [['All time', 'Fee records', report.summary.fee_records, '', '', ''], ['All time', 'Issued', '', report.summary.issued_amount, '', ''], ['All time', 'Received', '', report.summary.received_amount, '', ''], ['All time', 'Expenses', '', report.summary.expense_amount, '', ''], ['All time', 'Net cash flow', '', report.summary.net_cash_flow, '', ''], ['All time', 'Outstanding', report.summary.unpaid_records + report.summary.partial_records, report.summary.outstanding_amount, '', ''], ['All time', 'Overdue balance', report.summary.overdue_records, report.summary.overdue_balance, '', ''], ['Selected period', 'Receipts', report.collections_period.receipt_count, report.collections_period.received_amount, report.collections_period.from, report.collections_period.to], ['Selected period', 'Expenses', report.collections_period.expense_count, report.collections_period.expense_amount, report.collections_period.from, report.collections_period.to], ['Selected period', 'Net cash flow', '', report.collections_period.net_cash_flow, report.collections_period.from, report.collections_period.to]])}><Download size={14} />Export CSV</button></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    <div className="report-filters"><label>Cash flow from<input type="date" value={from} max={to} onChange={event => setFrom(event.target.value)} /></label><label>Through<input type="date" value={to} min={from} onChange={event => setTo(event.target.value)} /></label><span>{report && !loading ? `${money(report.collections_period.received_amount)} received · ${money(report.collections_period.expense_amount)} spent · net ${money(report.collections_period.net_cash_flow)}` : 'Choose a date range to review recorded cash flow.'}</span></div>
    {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading finance summary…</p> : report && <>
      <div className="report-stat-grid"><article><small>ISSUED</small><b>{money(report.summary.issued_amount)}</b><small>{report.summary.fee_records} fee records</small></article><article><small>RECEIVED</small><b>{money(report.summary.received_amount)}</b><small>{report.summary.paid_records} paid in full</small></article><article><small>EXPENSES</small><b>{money(report.summary.expense_amount)}</b><small>All recorded outflows</small></article><article><small>NET CASH FLOW</small><b>{money(report.summary.net_cash_flow)}</b><small>Receipts minus expenses</small></article><article><small>OUTSTANDING</small><b>{money(report.summary.outstanding_amount)}</b><small>{report.summary.unpaid_records} unpaid · {report.summary.partial_records} partial</small></article><article><small>OVERDUE BALANCE</small><b>{money(report.summary.overdue_balance)}</b><small>{report.summary.overdue_records} overdue records</small></article></div>
      <p className="dash-record-subtitle">Cash flow uses staff-entered fee receipts and expense records. It is not a bank reconciliation and does not initiate payment.</p>
    </>}
  </section>
}
