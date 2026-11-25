import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, LoaderCircle, Plus, ReceiptText } from 'lucide-react'
import { api } from '../lib/api'

type Expense = { id: number; title: string; category: string; amount: string; incurred_on: string; payment_method: string; reference: string | null; notes: string | null }
const categories = ['salaries', 'utilities', 'supplies', 'maintenance', 'transport', 'technology', 'other']
const money = (amount: string) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 }).format(Number(amount))
const localToday = () => { const date = new Date(); return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}` }

export default function FinanceExpenses({ organizationId, canManage = true }: { organizationId: number; canManage?: boolean }) {
  const base = `/api/v1/organizations/${organizationId}/expenses`
  const [expenses, setExpenses] = useState<Expense[]>([])
  const [title, setTitle] = useState('')
  const [category, setCategory] = useState('utilities')
  const [amount, setAmount] = useState('')
  const [incurredOn, setIncurredOn] = useState(localToday())
  const [method, setMethod] = useState('bank_transfer')
  const [reference, setReference] = useState('')
  const [notes, setNotes] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  useEffect(() => {
    let cancelled = false
    api.get<{ data: Expense[] }>(`${base}?per_page=50`)
      .then(result => { if (!cancelled) setExpenses(result.data) })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load expense records.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base])

  async function createExpense(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setSaving(true); setError(''); setMessage('')
    try {
      const response = await api.post<{ data: Expense }>(base, { title: title.trim(), category, amount, incurred_on: incurredOn, payment_method: method, reference: reference.trim() || null, notes: notes.trim() || null })
      setExpenses(current => [response.data, ...current].slice(0, 50))
      setTitle(''); setAmount(''); setReference(''); setNotes('')
      setMessage('Expense recorded in the manual ledger.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not record the expense.') } finally { setSaving(false) }
  }

  return <section className="dash-panel dash-masters-panel">
    <div className="dash-panel-heading"><div><span className="dash-panel-kicker">CASH OUTFLOWS</span><h2>Operating expenses</h2><p className="dash-record-subtitle">Record expenses already paid by the institution. Entries do not initiate a payment.</p></div></div>
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    {canManage && <form className="fee-create-form" onSubmit={event => void createExpense(event)}>
      <label>Description<input value={title} onChange={event => setTitle(event.target.value)} required minLength={2} maxLength={160} placeholder="Electricity bill, classroom supplies…" /></label>
      <label>Category<select value={category} onChange={event => setCategory(event.target.value)}>{categories.map(value => <option key={value} value={value}>{value.replace('_', ' ')}</option>)}</select></label>
      <label>Amount (INR)<input type="number" value={amount} onChange={event => setAmount(event.target.value)} required min="0.01" max="99999999.99" step="0.01" /></label>
      <label>Paid on<input type="date" value={incurredOn} max={localToday()} onChange={event => setIncurredOn(event.target.value)} required /></label>
      <label>Payment method<select value={method} onChange={event => setMethod(event.target.value)}><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="card">Card</option><option value="upi">UPI</option><option value="other">Other</option></select></label>
      <label>Reference (optional)<input value={reference} onChange={event => setReference(event.target.value)} maxLength={120} /></label>
      <label>Notes (optional)<input value={notes} onChange={event => setNotes(event.target.value)} maxLength={2000} /></label>
      <button className="master-submit" disabled={saving}><Plus size={15} />Record expense</button>
    </form>}
    {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading expenses…</p> : expenses.length ? <div className="dash-table-scroll attendance-table"><table><thead><tr><th>DATE</th><th>DESCRIPTION</th><th>CATEGORY</th><th>METHOD</th><th>AMOUNT</th><th>REFERENCE</th></tr></thead><tbody>{expenses.map(expense => <tr key={expense.id}><td>{new Date(`${expense.incurred_on}T00:00:00`).toLocaleDateString()}</td><td>{expense.title}{expense.notes && <small className="expense-note">{expense.notes}</small>}</td><td>{expense.category.replace('_', ' ')}</td><td>{expense.payment_method.replace('_', ' ')}</td><td><b>{money(expense.amount)}</b></td><td>{expense.reference || '—'}</td></tr>)}</tbody></table></div> : <p className="master-empty"><ReceiptText size={18} />No expenses have been recorded.</p>}
  </section>
}
