import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, FileUp, LoaderCircle, RefreshCw, Scale } from 'lucide-react'
import { api } from '../lib/api'

type Candidate = { type: 'fee_payment' | 'expense'; id: number; amount: string; date: string; description: string }
type Line = { id: number; transacted_on: string; description: string; direction: 'credit' | 'debit'; amount: string; reference: string | null; status: 'matched' | 'unmatched'; match: Candidate | null }
type Statement = { id: number; account_label: string; period_from: string; period_to: string; opening_balance: string; closing_balance: string; line_count: number; matched_count: number; lines: Line[] }

const money = (value: string) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 }).format(Number(value))
const today = () => { const date = new Date(); return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}` }

function parseCsv(value: string): string[][] {
  const rows: string[][] = []
  let row: string[] = []; let field = ''; let quoted = false
  for (let index = 0; index < value.length; index += 1) {
    const char = value[index]
    if (char === '"' && quoted && value[index + 1] === '"') { field += '"'; index += 1 }
    else if (char === '"') quoted = !quoted
    else if (char === ',' && !quoted) { row.push(field.trim()); field = '' }
    else if ((char === '\n' || char === '\r') && !quoted) {
      if (char === '\r' && value[index + 1] === '\n') index += 1
      row.push(field.trim()); if (row.some(item => item !== '')) rows.push(row); row = []; field = ''
    } else field += char
  }
  if (quoted) throw new Error('CSV contains an unclosed quoted field.')
  row.push(field.trim()); if (row.some(item => item !== '')) rows.push(row)
  return rows
}

export default function BankReconciliation({ organizationId, canManage = true }: { organizationId: number; canManage?: boolean }) {
  const base = `/api/v1/organizations/${organizationId}`
  const [statements, setStatements] = useState<Statement[]>([])
  const [accountLabel, setAccountLabel] = useState('')
  const [periodFrom, setPeriodFrom] = useState(today())
  const [periodTo, setPeriodTo] = useState(today())
  const [opening, setOpening] = useState('0.00')
  const [closing, setClosing] = useState('0.00')
  const [csv, setCsv] = useState('')
  const [candidates, setCandidates] = useState<Record<number, Candidate[]>>({})
  const [selection, setSelection] = useState<Record<number, string>>({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  async function refresh() {
    const result = await api.get<{ data: Statement[] }>(`${base}/bank-statements`)
    setStatements(result.data)
  }

  useEffect(() => {
    let cancelled = false
    api.get<{ data: Statement[] }>(`${base}/bank-statements`).then(result => { if (!cancelled) setStatements(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load bank statements.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base])

  async function importStatement(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setSaving(true); setError(''); setMessage('')
    try {
      const rows = parseCsv(csv)
      const expected = ['date', 'description', 'direction', 'amount', 'reference']
      if (rows.length < 2 || rows[0].map(value => value.toLowerCase())?.join('|') !== expected.join('|')) throw new Error('Use the CSV header: date,description,direction,amount,reference')
      if (rows.length > 501) throw new Error('A statement can contain at most 500 transactions.')
      const lines = rows.slice(1).map((row, index) => {
        if (row.length !== 5 || !['credit', 'debit'].includes(row[2].toLowerCase())) throw new Error(`CSV row ${index + 2} must have a date, description, credit/debit direction, amount, and reference.`)
        return { transacted_on: row[0], description: row[1], direction: row[2].toLowerCase(), amount: row[3], reference: row[4] || null }
      })
      await api.post(`${base}/bank-statements`, { account_label: accountLabel.trim(), period_from: periodFrom, period_to: periodTo, opening_balance: opening, closing_balance: closing, lines })
      await refresh(); setCsv(''); setAccountLabel(''); setMessage('Statement imported. Match the lines below to recorded receipts and expenses.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not import the statement.') } finally { setSaving(false) }
  }

  async function loadCandidates(line: Line) {
    setError(''); setMessage('')
    try {
      const result = await api.get<{ data: Candidate[] }>(`${base}/bank-statement-lines/${line.id}/candidates`)
      setCandidates(current => ({ ...current, [line.id]: result.data }))
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not find matching ledger entries.') }
  }

  async function matchLine(line: Line) {
    const [ledger_type, ledgerId] = (selection[line.id] ?? '').split(':')
    if (!ledger_type || !ledgerId) return
    setSaving(true); setError('')
    try {
      await api.post(`${base}/bank-statement-lines/${line.id}/match`, { ledger_type, ledger_id: Number(ledgerId) })
      await refresh(); setCandidates(current => ({ ...current, [line.id]: [] })); setMessage('Statement line reconciled.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not reconcile this line.') } finally { setSaving(false) }
  }

  async function unmatchLine(line: Line) {
    setSaving(true); setError('')
    try { await api.delete(`${base}/bank-statement-lines/${line.id}/match`); await refresh(); setMessage('Statement line returned to unmatched status.') }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not undo this match.') } finally { setSaving(false) }
  }

  return <section className="dash-panel dash-masters-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">BANK RECONCILIATION</span><h2>Reconcile a statement</h2><p className="dash-record-subtitle">Import a bank CSV and match transactions with existing ledger entries. This does not connect to your bank.</p></div></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    {canManage && <form className="fee-create-form" onSubmit={event => void importStatement(event)}>
      <label>Account label<input value={accountLabel} onChange={event => setAccountLabel(event.target.value)} required minLength={2} maxLength={120} placeholder="Main operating account" /></label>
      <label>Statement from<input type="date" value={periodFrom} max={periodTo} onChange={event => setPeriodFrom(event.target.value)} required /></label>
      <label>Through<input type="date" value={periodTo} min={periodFrom} max={today()} onChange={event => setPeriodTo(event.target.value)} required /></label>
      <label>Opening balance (INR)<input type="number" value={opening} onChange={event => setOpening(event.target.value)} step="0.01" required /></label>
      <label>Closing balance (INR)<input type="number" value={closing} onChange={event => setClosing(event.target.value)} step="0.01" required /></label>
      <label className="bank-csv-label">Statement CSV<input type="file" accept=".csv,text/csv" onChange={event => { const file = event.target.files?.[0]; if (file && file.size <= 200_000) void file.text().then(setCsv); else if (file) setError('Choose a CSV file smaller than 200 KB.') }} /></label>
      <label className="bank-csv-label">CSV data<textarea value={csv} onChange={event => setCsv(event.target.value)} required maxLength={200_000} rows={5} placeholder={'date,description,direction,amount,reference\n2026-11-19,Tuition receipt,credit,2500.00,UPI-123\n2026-11-19,Stationery,debit,400.00,BILL-42'} /></label>
      <p className="dash-record-subtitle">Use `credit` for deposits and `debit` for withdrawals. Opening balance + credits − debits must equal closing balance. Review sensitive statement data before importing.</p>
      <button className="master-submit" disabled={saving || !csv.trim()}><FileUp size={15} />Import statement</button>
    </form>}
    <div className="dash-panel-heading bank-statement-heading"><div><h3>Imported statements</h3></div><button className="fee-cancel" type="button" onClick={() => { setLoading(true); void refresh().catch(reason => setError(reason instanceof Error ? reason.message : 'Could not refresh statements.')).finally(() => setLoading(false)) }}><RefreshCw size={14} /> Refresh</button></div>
    {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading statements…</p> : statements.length === 0 ? <p className="master-empty"><Scale size={18} />No bank statements imported.</p> : statements.map(statement => <article className="bank-statement-card" key={statement.id}>
      <header><div><b>{statement.account_label}</b><small>{statement.period_from} – {statement.period_to} · {statement.matched_count}/{statement.line_count} matched</small></div><span>{money(statement.opening_balance)} → {money(statement.closing_balance)}</span></header>
      <div className="dash-table-scroll attendance-table"><table><thead><tr><th>DATE</th><th>DESCRIPTION</th><th>DIRECTION</th><th>AMOUNT</th><th>MATCH</th>{canManage && <th>ACTION</th>}</tr></thead><tbody>{statement.lines.map(line => <tr key={line.id}><td>{line.transacted_on}</td><td>{line.description}{line.reference && <small className="expense-note">{line.reference}</small>}</td><td>{line.direction}</td><td><b>{money(line.amount)}</b></td><td>{line.match ? <>{line.match.description}<small className="expense-note">{line.match.date} · {line.match.type === 'expense' ? 'Expense' : 'Fee receipt'}</small></> : canManage && candidates[line.id] ? candidates[line.id].length ? <select aria-label={`Match ${line.description}`} value={selection[line.id] ?? ''} onChange={event => setSelection(current => ({ ...current, [line.id]: event.target.value }))}><option value="">Choose exact amount and nearby date…</option>{candidates[line.id].map(item => <option key={`${item.type}:${item.id}`} value={`${item.type}:${item.id}`}>{item.description} · {item.date} · {money(item.amount)}</option>)}</select> : <small>No unmatched ledger candidates within 7 days.</small> : <span>Unmatched</span>}</td>{canManage && <td>{line.status === 'matched' ? <button type="button" className="fee-cancel" disabled={saving} onClick={() => void unmatchLine(line)}>Undo match</button> : candidates[line.id] ? <button type="button" className="master-submit" disabled={saving || !selection[line.id]} onClick={() => void matchLine(line)}>Match</button> : <button type="button" className="fee-cancel" onClick={() => void loadCandidates(line)}>Find matches</button>}</td>}</tr>)}</tbody></table></div>
    </article>)}
  </section>
}
