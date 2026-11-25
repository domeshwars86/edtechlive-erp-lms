import { lazy, Suspense, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { AlertCircle, Check, LoaderCircle, Plus, Wallet } from 'lucide-react'
import { api } from '../lib/api'
import FinanceSummary from './FinanceSummary'
import FinanceExpenses from './FinanceExpenses'

const BankReconciliation = lazy(() => import('./BankReconciliation'))

type Student = { id: number; name: string; admission_number: string }
type FeeCategory = { id: number; name: string; code: string | null }
type Payment = { id: number; amount: string; method: string; reference: string | null; received_at: string }
type FeeRecord = { id: number; student: Student; title: string; category: FeeCategory | null; amount: string; paid_amount: string; balance: string; due_on: string | null; status: 'unpaid' | 'partial' | 'paid'; payments: Payment[] }
type RazorpayCheckoutConfig = { enabled: boolean; key_id: string | null; brand_name: string; currency: 'INR'; mode: 'test' | 'disabled' }
type RazorpayOrder = { order_id: string; amount: number; currency: string; key_id: string; brand_name: string; status: string }
type RazorpaySuccess = { razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }
type RazorpayInstance = { open: () => void }
type RazorpayOptions = {
  key: string; amount: number; currency: string; name: string; description: string; order_id: string
  handler: (response: RazorpaySuccess) => void | Promise<void>
  modal: { ondismiss: () => void }
  theme: { color: string }
}

declare global {
  interface Window { Razorpay?: new (options: RazorpayOptions) => RazorpayInstance }
}

const money = (value: string) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 }).format(Number(value))

export default function FeeWorkspace({ organizationId, mode }: { organizationId: number; mode: 'manage' | 'read' | 'family' }) {
  const base = `/api/v1/organizations/${organizationId}`
  const canManage = mode === 'manage'
  const organizationFinance = mode !== 'family'
  const [records, setRecords] = useState<FeeRecord[]>([])
  const [students, setStudents] = useState<Student[]>([])
  const [categories, setCategories] = useState<FeeCategory[]>([])
  const [studentId, setStudentId] = useState('')
  const [categoryId, setCategoryId] = useState('')
  const [title, setTitle] = useState('')
  const [amount, setAmount] = useState('')
  const [dueOn, setDueOn] = useState('')
  const [paymentFeeId, setPaymentFeeId] = useState<number | null>(null)
  const [paymentAmount, setPaymentAmount] = useState('')
  const [paymentMethod, setPaymentMethod] = useState('upi')
  const [paymentReference, setPaymentReference] = useState('')
  const [paymentRequestKey, setPaymentRequestKey] = useState<string | null>(null)
  const [gatewayConfig, setGatewayConfig] = useState<RazorpayCheckoutConfig | null>(null)
  const [pendingCheckout, setPendingCheckout] = useState<{ feeId: number; idempotencyKey: string } | null>(null)
  const [pendingVerification, setPendingVerification] = useState<{ feeId: number; response: RazorpaySuccess } | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    const requests: [Promise<{ data: FeeRecord[] }>, Promise<{ data: Student[] }> | null, Promise<{ data: FeeCategory[] }> | null] = [
      api.get<{ data: FeeRecord[] }>(`${base}/fees?per_page=100`),
      canManage ? api.get<{ data: Student[] }>(`${base}/students?status=active&per_page=100`) : null,
      canManage ? api.get<{ data: FeeCategory[] }>(`${base}/fee-categories?is_active=1&per_page=100`) : null,
    ]
    Promise.all([requests[0], requests[1] ?? Promise.resolve(null), requests[2] ?? Promise.resolve(null)])
      .then(([feeResult, studentResult, categoryResult]) => {
        if (!cancelled) {
          setRecords(feeResult.data); setStudents(studentResult?.data ?? [])
          const availableCategories = categoryResult?.data ?? []
          setCategories(availableCategories)
          setCategoryId(current => current || String(availableCategories[0]?.id ?? ''))
        }
      })
      .catch(reason => { if (!cancelled) setError(reason instanceof Error ? reason.message : 'Could not load fee records.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [base, canManage, mode])

  useEffect(() => {
    let cancelled = false
    if (mode === 'family') {
      api.get<{ data: RazorpayCheckoutConfig }>(`${base}/payments/razorpay/config`)
        .then(result => { if (!cancelled) setGatewayConfig(result.data) })
        .catch(() => { if (!cancelled) setGatewayConfig(null) })
    }
    return () => { cancelled = true }
  }, [base, mode])

  async function refreshFeeRecords() {
    const result = await api.get<{ data: FeeRecord[] }>(`${base}/fees?per_page=100`)
    setRecords(result.data)
  }

  function loadRazorpayCheckout(): Promise<boolean> {
    if (window.Razorpay) return Promise.resolve(true)
    const existing = document.querySelector<HTMLScriptElement>('script[data-razorpay-checkout]')
    if (existing) return new Promise(resolve => { existing.addEventListener('load', () => resolve(Boolean(window.Razorpay)), { once: true }); existing.addEventListener('error', () => resolve(false), { once: true }) })
    return new Promise(resolve => {
      const script = document.createElement('script')
      script.src = 'https://checkout.razorpay.com/v1/checkout.js'
      script.async = true
      script.dataset.razorpayCheckout = 'true'
      script.onload = () => resolve(Boolean(window.Razorpay))
      script.onerror = () => resolve(false)
      document.head.append(script)
    })
  }

  async function verifyOnlinePayment(feeId: number, response: RazorpaySuccess) {
    setSaving(true); setError(''); setMessage('Confirming the captured test payment…')
    try {
      await api.post(`${base}/fees/${feeId}/razorpay/verify`, response)
      await refreshFeeRecords()
      setPendingCheckout(null)
      setPendingVerification(null)
      setMessage('Test payment confirmed. The fee balance has been updated.')
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not confirm the payment. Retry confirmation or wait for the payment update.')
    } finally {
      setSaving(false)
    }
  }

  async function payOnline(record: FeeRecord) {
    if (!gatewayConfig?.enabled || saving || pendingVerification?.feeId === record.id) return
    setSaving(true); setError(''); setMessage('')
    const idempotencyKey = pendingCheckout?.feeId === record.id ? pendingCheckout.idempotencyKey : crypto.randomUUID()
    setPendingCheckout({ feeId: record.id, idempotencyKey })
    try {
      const result = await api.post<{ data: RazorpayOrder }>(`${base}/fees/${record.id}/razorpay/checkout`, {}, { 'Idempotency-Key': idempotencyKey })
      if (result.data.status === 'captured') {
        await refreshFeeRecords()
        setPendingCheckout(null)
        setMessage('This test payment was already confirmed.')
        setSaving(false)
        return
      }
      const loaded = await loadRazorpayCheckout()
      if (!loaded || !window.Razorpay) throw new Error('Razorpay Checkout could not load. Check your connection and try again.')
      const checkout = new window.Razorpay({
        key: result.data.key_id,
        amount: result.data.amount,
        currency: result.data.currency,
        name: result.data.brand_name,
        description: record.title,
        order_id: result.data.order_id,
        theme: { color: '#2858b8' },
        modal: { ondismiss: () => setSaving(false) },
        handler: async response => {
          setPendingVerification({ feeId: record.id, response })
          await verifyOnlinePayment(record.id, response)
        },
      })
      checkout.open()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not start the test payment.')
      setSaving(false)
    }
  }

  async function createFee(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.post<{ data: FeeRecord }>(`${base}/fees`, {
        student_id: Number(studentId), fee_category_id: Number(categoryId), title: title.trim(), amount, due_on: dueOn || null,
      })
      setRecords(current => [result.data, ...current]); setTitle(''); setAmount(''); setDueOn('')
      setMessage('Fee record created. Record received money only after it has actually been collected.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not create the fee record.') } finally { setSaving(false) }
  }

  async function recordPayment(event: FormEvent<HTMLFormElement>, record: FeeRecord) {
    event.preventDefault()
    if (!paymentRequestKey) {
      setError('Start a new receipt entry before saving this payment.')
      return
    }
    setSaving(true); setError(''); setMessage('')
    try {
      const result = await api.post<{ data: FeeRecord }>(`${base}/fees/${record.id}/payments`, {
        amount: paymentAmount, method: paymentMethod, reference: paymentReference.trim() || null,
      }, { 'Idempotency-Key': paymentRequestKey })
      setRecords(current => current.map(item => item.id === record.id ? result.data : item))
      setPaymentFeeId(null); setPaymentRequestKey(null); setPaymentAmount(''); setPaymentReference(''); setMessage('Payment receipt saved.')
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Could not record the payment.') } finally { setSaving(false) }
  }

  return <div className="fee-workspace">
    {error && <div className="master-feedback master-error" role="alert"><AlertCircle size={16} />{error}</div>}
    {message && <div className="master-feedback master-success" role="status"><Check size={16} />{message}</div>}
    {pendingVerification && <div className="master-feedback master-error" role="status"><AlertCircle size={16} /><span>Payment submitted. The fee ledger will change only after Razorpay confirms capture.</span><button type="button" className="fee-cancel" disabled={saving} onClick={() => void verifyOnlinePayment(pendingVerification.feeId, pendingVerification.response)}>Retry confirmation</button></div>}
    {organizationFinance && <FinanceSummary organizationId={organizationId} />}
    {organizationFinance && <FinanceExpenses organizationId={organizationId} canManage={canManage} />}
    {organizationFinance && <Suspense fallback={<section className="dash-panel dash-masters-panel"><p className="master-empty">Loading reconciliation workspace…</p></section>}><BankReconciliation organizationId={organizationId} canManage={canManage} /></Suspense>}
    {canManage && <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">FINANCE</span><h2>Issue a fee record</h2><p className="dash-record-subtitle">Create a receivable for an active learner. Payments are staff-recorded; no card or bank charge is initiated here.</p></div></div><form className="fee-create-form" onSubmit={event => void createFee(event)}><label>Student<select value={studentId} onChange={event => setStudentId(event.target.value)} required><option value="">Select an active student</option>{students.map(student => <option key={student.id} value={student.id}>{student.name} · {student.admission_number}</option>)}</select></label><label>Fee category<select value={categoryId} onChange={event => setCategoryId(event.target.value)} required><option value="">Select a category</option>{categories.map(category => <option key={category.id} value={category.id}>{category.name}{category.code ? ` · ${category.code}` : ''}</option>)}</select></label><label>Fee description<input value={title} onChange={event => setTitle(event.target.value)} required minLength={2} maxLength={160} placeholder="Term tuition, books, transport…" /></label><label>Amount (INR)<input type="number" value={amount} onChange={event => setAmount(event.target.value)} required min="0.01" max="99999999.99" step="0.01" /></label><label>Due date<input type="date" value={dueOn} onChange={event => setDueOn(event.target.value)} /></label><button className="master-submit" disabled={saving || students.length === 0 || categories.length === 0}><Plus size={15} />Create fee record</button>{categories.length === 0 && <p className="master-hint">Create an active fee category in Academic masters before issuing a categorized fee record.</p>}</form></section>}
    <section className="dash-panel dash-masters-panel"><div className="dash-panel-heading"><div><span className="dash-panel-kicker">{organizationFinance ? 'FEE LEDGER' : 'FAMILY FINANCE'}</span><h2>{mode === 'manage' ? 'Fee records' : mode === 'read' ? 'Finance records' : 'Fee information'}</h2><p className="dash-record-subtitle">{mode === 'manage' ? 'Review balances and record payments received by your institution.' : mode === 'read' ? 'Review organization fee records without changing the ledger.' : 'View fee balances linked to your learner or children.'}</p></div></div>
      {mode === 'family' && gatewayConfig?.enabled && <div className="fee-test-mode-note" role="note">Razorpay Test Mode only. The checkout uses simulated transactions and will not collect real money.</div>}
      {loading ? <p className="master-empty"><LoaderCircle className="master-spin" size={18} />Loading fee records…</p> : records.length === 0 ? <p className="master-empty"><Wallet size={18} />No fee records are available.</p> : <div className="fee-record-list">{records.map(record => <article className="fee-record" key={record.id}><header><div><span className={`fee-status fee-${record.status}`}>{record.status}</span><h3>{record.title}{record.category && <small className="fee-category-label">{record.category.name}</small>}</h3><p>{record.student.name} · {record.student.admission_number}{record.due_on ? ` · Due ${record.due_on}` : ''}</p></div><strong>{money(record.balance)} due</strong></header><div className="fee-record-amounts"><span>Total <b>{money(record.amount)}</b></span><span>Paid <b>{money(record.paid_amount)}</b></span><span>Balance <b>{money(record.balance)}</b></span></div>{record.payments.length > 0 && <details><summary>Receipts ({record.payments.length})</summary><ul>{record.payments.map(payment => <li key={payment.id}>{money(payment.amount)} · {payment.method.replace('_', ' ')} · {new Date(payment.received_at).toLocaleDateString()}{payment.reference ? ` · ${payment.reference}` : ''}</li>)}</ul></details>}{canManage && record.status !== 'paid' && (paymentFeeId === record.id ? <form className="fee-payment-form" onSubmit={event => void recordPayment(event, record)}><label>Received amount<input type="number" value={paymentAmount} onChange={event => setPaymentAmount(event.target.value)} required min="0.01" max={record.balance} step="0.01" /></label><label>Method<select value={paymentMethod} onChange={event => setPaymentMethod(event.target.value)}><option value="upi">UPI</option><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="other">Other</option></select></label><label>Reference (optional)<input value={paymentReference} onChange={event => setPaymentReference(event.target.value)} maxLength={120} /></label><button className="master-submit" disabled={saving}>Save receipt</button><button type="button" className="fee-cancel" onClick={() => { setPaymentFeeId(null); setPaymentRequestKey(null) }}>Cancel</button></form> : <button className="master-submit fee-record-action" onClick={() => { setPaymentFeeId(record.id); setPaymentAmount(record.balance); setPaymentRequestKey(crypto.randomUUID()) }}>Record payment received</button>)}{mode === 'family' && gatewayConfig?.enabled && record.status !== 'paid' && Number(record.balance) >= 1 && <button className="master-submit fee-record-action" disabled={saving} onClick={() => void payOnline(record)}>{saving && pendingCheckout?.feeId === record.id ? 'Opening secure test checkout…' : 'Pay online · Test Mode'}</button>}</article>)}</div>}
    </section>
  </div>
}
