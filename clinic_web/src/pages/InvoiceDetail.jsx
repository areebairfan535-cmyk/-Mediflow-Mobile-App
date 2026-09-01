import { useState } from 'react'
import { useEffect } from 'react'
import { api, openPdf } from '../api.js'
import {
  Card, Badge, Loading, ErrorBox, Modal, dateOf, initials,
} from '../components.jsx'
import { money, STATUS_TONE } from './Billing.jsx'

/**
 * One invoice: lines, totals, payment ledger, and the actions its current
 * status allows.
 *
 * Which buttons appear is driven by the invoice's own status, not by hope —
 * the API refuses the rest anyway, so the screen simply reflects the same
 * rules rather than inventing its own.
 */
export default function InvoiceDetail({ invoiceId, session, go }) {
  const [state, setState] = useState({ loading: true })
  const [notice, setNotice] = useState(null)
  const [modal, setModal] = useState(null)
  const [busy, setBusy] = useState(false)

  async function load() {
    try {
      const res = await api.invoice(invoiceId)
      setState({ loading: false, inv: res.data.invoice })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [invoiceId])

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  const inv = state.inv
  const cur = inv.currency_code
  const isDraft = inv.status === 'draft'
  const payable = ['issued', 'partially_paid', 'overdue'].includes(inv.status)

  async function act(fn, message) {
    setBusy(true)
    setNotice(null)
    try {
      await fn()
      if (message) setNotice({ ok: true, message })
      await load()
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <div className="page-head">
        <div>
          <button className="btn btn-sm btn-secondary" onClick={() => go('billing')}>
            ← Billing
          </button>
        </div>
        <Badge tone={STATUS_TONE[inv.status]}>{inv.status.replace(/_/g, ' ')}</Badge>
      </div>

      <div className="patient-header">
        <div className="avatar">{initials(inv.patient_name)}</div>
        <div>
          <h1 className="mono">{inv.invoice_no}</h1>
          <div className="meta">
            {inv.patient_name} · <span className="mono">{inv.mrn}</span>
            {inv.encounter_no && <> · visit <span className="mono">{inv.encounter_no}</span></>}
            {inv.issue_date && <> · issued {dateOf(inv.issue_date)}</>}
          </div>
        </div>
        <div className="spacer" />
        <div style={{ textAlign: 'right' }}>
          <div className="hint">Balance due</div>
          <div style={{ fontSize: 24, fontWeight: 680, letterSpacing: '-0.02em' }}>
            {money(inv.balance_due, cur)}
          </div>
        </div>
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      <div className="row" style={{ marginBottom: 16 }}>
        {!isDraft && (
          <button className="btn btn-secondary"
                  onClick={() => openPdf(`/invoices/${inv.id}/pdf`)
                    .catch((e) => setNotice({ ok: false, message: e.message }))}>
            Print / PDF
          </button>
        )}
        {isDraft && session.can('invoice.issue') && (
          <button className="btn" disabled={busy}
                  onClick={() => act(() => api.issueInvoice(inv.id), 'Invoice issued.')}>
            Issue invoice
          </button>
        )}
        {payable && session.can('payment.create') && (
          <button className="btn btn-ok" onClick={() => setModal('payment')}>
            Record payment
          </button>
        )}
        {inv.status !== 'cancelled' && session.can('invoice.cancel') && (
          <button className="btn btn-secondary" disabled={busy}
                  onClick={() => {
                    const reason = window.prompt('Why is this invoice being cancelled?')
                    if (!reason) return
                    act(() => api.cancelInvoice(inv.id, reason), 'Invoice cancelled.')
                  }}>
            Cancel
          </button>
        )}
        <button className="btn btn-secondary" onClick={() => window.print()}>Print</button>
      </div>

      {/* §8: what the patient's insurer would cover, and raising the claim. */}
      {inv.status !== 'draft' && inv.status !== 'cancelled' && session.can('policy.view') && (
        <InsurancePanel
          invoice={inv}
          canClaim={session.can('claim.create')}
          onClaimed={(claimId) => go('claim', { claimId })}
          onError={(m) => setNotice({ ok: false, message: m })}
        />
      )}

      {isDraft && (
        <div className="alert" style={{ background: 'var(--warn-soft)', color: 'var(--warn)',
                                        borderColor: 'rgba(180,106,0,0.2)' }}>
          This is a <strong>draft</strong>. It has no real invoice number and cannot take payment
          until it is issued. Issuing locks the lines.
        </div>
      )}

      <Card title="Lines" bodyless>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Code</th><th>Description</th>
                <th style={{ textAlign: 'right' }}>Qty</th>
                <th style={{ textAlign: 'right' }}>Unit</th>
                <th style={{ textAlign: 'right' }}>Discount</th>
                <th style={{ textAlign: 'right' }}>Tax</th>
                <th style={{ textAlign: 'right' }}>Total</th>
              </tr>
            </thead>
            <tbody>
              {inv.items.map((it) => (
                <tr key={it.id}>
                  <td className="mono">{it.service_code || '—'}</td>
                  <td className="strong">{it.description}</td>
                  <td className="mono" style={{ textAlign: 'right' }}>{Number(it.quantity)}</td>
                  <td className="mono" style={{ textAlign: 'right' }}>{money(it.unit_price)}</td>
                  <td className="mono" style={{ textAlign: 'right' }}>
                    {Number(it.discount_amount) > 0 ? `−${money(it.discount_amount)}` : '—'}
                  </td>
                  <td className="mono" style={{ textAlign: 'right' }}>
                    {money(it.tax_amount)}
                    {Number(it.tax_rate) > 0 && (
                      <span className="hint"> ({(Number(it.tax_rate) * 100).toFixed(0)}%)</span>
                    )}
                  </td>
                  <td className="mono strong" style={{ textAlign: 'right' }}>
                    {money(it.line_total)}
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr>
                <td colSpan={6} style={{ textAlign: 'right' }} className="strong">Subtotal</td>
                <td className="mono" style={{ textAlign: 'right' }}>{money(inv.subtotal)}</td>
              </tr>
              {Number(inv.discount_total) > 0 && (
                <tr>
                  <td colSpan={6} style={{ textAlign: 'right' }} className="strong">Discount</td>
                  <td className="mono" style={{ textAlign: 'right' }}>−{money(inv.discount_total)}</td>
                </tr>
              )}
              <tr>
                <td colSpan={6} style={{ textAlign: 'right' }} className="strong">Tax</td>
                <td className="mono" style={{ textAlign: 'right' }}>{money(inv.tax_total)}</td>
              </tr>
              <tr>
                <td colSpan={6} style={{ textAlign: 'right', fontWeight: 700 }}>Total</td>
                <td className="mono" style={{ textAlign: 'right', fontWeight: 700, fontSize: 15 }}>
                  {money(inv.grand_total, cur)}
                </td>
              </tr>
              <tr>
                <td colSpan={6} style={{ textAlign: 'right' }} className="strong">Paid</td>
                <td className="mono" style={{ textAlign: 'right' }}>−{money(inv.paid_total)}</td>
              </tr>
              <tr>
                <td colSpan={6} style={{ textAlign: 'right', fontWeight: 700 }}>Balance due</td>
                <td className="mono" style={{ textAlign: 'right', fontWeight: 700, fontSize: 15 }}>
                  {money(inv.balance_due, cur)}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </Card>

      <div style={{ marginTop: 16 }}>
        <Card title={`Payments (${inv.payments.length})`} bodyless>
          {inv.payments.length === 0 ? (
            <p className="hint" style={{ padding: 18 }}>Nothing received yet.</p>
          ) : (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Receipt</th><th>Date</th><th>Method</th>
                    <th style={{ textAlign: 'right' }}>Amount</th>
                    <th>Taken by</th><th>Status</th>
                    {session.can('refund.create') && <th />}
                  </tr>
                </thead>
                <tbody>
                  {inv.payments.map((p) => (
                    <tr key={p.id}>
                      <td className="mono">{p.receipt_no}</td>
                      <td>{dateOf(p.paid_at || p.created_at)}</td>
                      <td>{p.method.replace(/_/g, ' ')}</td>
                      <td className="mono strong" style={{ textAlign: 'right' }}>
                        {money(p.amount)}
                      </td>
                      <td>{p.received_by_name || '—'}</td>
                      <td><Badge>{p.status}</Badge></td>
                      {session.can('refund.create') && (
                        <td>
                          {p.status === 'succeeded' && (
                            <button className="btn btn-sm btn-secondary"
                                    onClick={() => setModal({ refund: p })}>
                              Refund
                            </button>
                          )}
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </div>

      {inv.refunds.length > 0 && (
        <div style={{ marginTop: 16 }}>
          <Card title={`Refunds (${inv.refunds.length})`} bodyless>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr><th>Date</th><th style={{ textAlign: 'right' }}>Amount</th>
                      <th>Reason</th><th>Status</th></tr>
                </thead>
                <tbody>
                  {inv.refunds.map((r) => (
                    <tr key={r.id}>
                      <td>{dateOf(r.created_at)}</td>
                      <td className="mono" style={{ textAlign: 'right' }}>{money(r.amount)}</td>
                      <td>{r.reason}</td>
                      <td><Badge tone={r.status === 'completed' ? 'ok'
                                       : r.status === 'rejected' ? 'danger' : 'warn'}>
                        {r.status}
                      </Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </div>
      )}

      {modal === 'payment' && (
        <PaymentModal
          invoice={inv}
          onClose={() => setModal(null)}
          onSaved={() => { setModal(null); setNotice({ ok: true, message: 'Payment recorded.' }); load() }}
        />
      )}

      {modal?.refund && (
        <RefundModal
          payment={modal.refund}
          onClose={() => setModal(null)}
          onSaved={() => {
            setModal(null)
            setNotice({ ok: true, message: 'Refund requested — it needs approval before it takes effect.' })
            load()
          }}
        />
      )}
    </>
  )
}

/**
 * Eligibility and claim status for one invoice.
 *
 * The split is shown line by line — deductible, copay, ceiling — because a
 * biller arguing with an insurer needs the working, not just the answer.
 */
function InsurancePanel({ invoice, canClaim, onClaimed, onError }) {
  const [state, setState] = useState({ loading: true })
  const [claim, setClaim] = useState(null)
  const [busy, setBusy] = useState(false)
  const [warnings, setWarnings] = useState([])

  useEffect(() => {
    let alive = true
    Promise.all([
      api.eligibility(invoice.id).catch(() => null),
      api.claims({ per_page: 100 }).catch(() => null),
    ]).then(([elig, claims]) => {
      if (!alive) return
      const existing = claims?.data.find((c) => Number(c.invoice_id) === Number(invoice.id))
      setClaim(existing || null)
      setState({ loading: false, e: elig?.data ?? null })
    })
    return () => { alive = false }
  }, [invoice.id])

  if (state.loading) return null

  const cov = state.e?.coverage
  const cur = invoice.currency_code

  return (
    <div style={{ marginBottom: 16 }}>
      <Card title="Insurance">
        {claim ? (
          <div className="row">
            <span>
              Claim <strong className="mono">{claim.claim_no}</strong> ·{' '}
              {money(claim.claimed_amount, cur)} claimed ·{' '}
              <Badge>{claim.status.replace(/_/g, ' ')}</Badge>
            </span>
            <button className="btn btn-sm" onClick={() => onClaimed(claim.id)}>
              Open claim
            </button>
          </div>
        ) : !cov ? (
          <p className="hint">Could not read the patient's cover.</p>
        ) : !cov.eligible ? (
          <>
            <p style={{ margin: 0, color: 'var(--body)' }}>
              <strong>Not claimable.</strong> The patient owes the full{' '}
              {money(cov.patient_responsibility, cur)}.
            </p>
            <ul className="hint" style={{ margin: '6px 0 0 16px', padding: 0 }}>
              {cov.reasons.map((r, i) => <li key={i}>{r}</li>)}
            </ul>
          </>
        ) : (
          <>
            <div className="table-wrap">
              <table>
                <tbody>
                  <tr>
                    <td className="strong" style={{ width: 210 }}>Insurer</td>
                    <td>
                      {state.e.policy.provider_name}{' '}
                      <span className="hint mono">{state.e.policy.policy_number}</span>
                    </td>
                  </tr>
                  <tr>
                    <td className="strong">Billed</td>
                    <td className="mono">{money(cov.billed, cur)}</td>
                  </tr>
                  {Number(cov.deductible_applied) > 0 && (
                    <tr>
                      <td className="strong">Less deductible</td>
                      <td className="mono">−{money(cov.deductible_applied)}</td>
                    </tr>
                  )}
                  {Number(cov.copay_amount) > 0 && (
                    <tr>
                      <td className="strong">Less copay ({Number(cov.copay_percent)}%)</td>
                      <td className="mono">−{money(cov.copay_amount)}</td>
                    </tr>
                  )}
                  {Number(cov.capped_by_ceiling) > 0 && (
                    <tr>
                      <td className="strong">Above the policy ceiling</td>
                      <td className="mono">−{money(cov.capped_by_ceiling)}</td>
                    </tr>
                  )}
                  <tr>
                    <td className="strong">Insurer would pay</td>
                    <td className="mono strong">{money(cov.insurance_payable, cur)}</td>
                  </tr>
                  <tr>
                    <td className="strong">Patient would owe</td>
                    <td className="mono strong">{money(cov.patient_responsibility, cur)}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            {warnings.length > 0 && (
              <div className="alert" style={{ background: 'var(--warn-soft)', color: 'var(--warn)',
                                              borderColor: 'rgba(180,106,0,0.2)', marginTop: 12 }}>
                <ul style={{ margin: '0 0 0 16px', padding: 0 }}>
                  {warnings.map((w, i) => <li key={i}>{w}</li>)}
                </ul>
              </div>
            )}

            {canClaim && (
              <button className="btn mt" disabled={busy}
                      onClick={async () => {
                        setBusy(true)
                        try {
                          const res = await api.createClaim({ invoice_id: invoice.id })
                          if (res.data.warnings?.length) setWarnings(res.data.warnings)
                          onClaimed(res.data.claim.id)
                        } catch (err) {
                          onError(err.message)
                          setBusy(false)
                        }
                      }}>
                {busy ? 'Raising…' : 'Raise a claim'}
              </button>
            )}
          </>
        )}
      </Card>
    </div>
  )
}

function PaymentModal({ invoice, onClose, onSaved }) {
  const [amount, setAmount] = useState(String(invoice.balance_due))
  const [method, setMethod] = useState('cash')
  const [notes, setNotes] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.recordPayment(invoice.id, { amount, method, notes: notes || undefined })
      onSaved()
    } catch (err) {
      setError(err)
      setBusy(false)
    }
  }

  return (
    <Modal
      title="Record payment"
      onClose={onClose}
      footer={
        <>
          <button className="btn btn-secondary" onClick={onClose}>Cancel</button>
          <button className="btn btn-ok" form="pay-form" disabled={busy}>
            {busy ? 'Saving…' : 'Record payment'}
          </button>
        </>
      }
    >
      <form id="pay-form" onSubmit={submit}>
        {error && <div className="alert">{error.message}</div>}

        <p className="hint" style={{ marginTop: 0 }}>
          Outstanding on {invoice.invoice_no}: <strong>{money(invoice.balance_due, invoice.currency_code)}</strong>
        </p>

        <div className="grid-2">
          <div className="field">
            <label>Amount ({invoice.currency_code}) *</label>
            <input type="number" step="0.01" min="0.01" value={amount} required
                   onChange={(e) => setAmount(e.target.value)} />
          </div>
          <div className="field">
            <label>Method *</label>
            <select value={method} onChange={(e) => setMethod(e.target.value)}>
              <option value="cash">Cash</option>
              <option value="card">Card</option>
              <option value="bank_transfer">Bank transfer</option>
              <option value="online">Online</option>
              <option value="insurance">Insurance</option>
              <option value="adjustment">Adjustment</option>
            </select>
          </div>
        </div>

        <div className="field">
          <label>Notes</label>
          <input value={notes} onChange={(e) => setNotes(e.target.value)}
                 placeholder="Reference, cheque number…" />
        </div>

        <p className="hint">
          Part payments are fine — the invoice stays open until the balance reaches zero.
        </p>
      </form>
    </Modal>
  )
}

function RefundModal({ payment, onClose, onSaved }) {
  const [amount, setAmount] = useState(String(payment.amount))
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.requestRefund(payment.id, { amount, reason })
      onSaved()
    } catch (err) {
      setError(err)
      setBusy(false)
    }
  }

  return (
    <Modal
      title={`Refund ${payment.receipt_no}`}
      onClose={onClose}
      footer={
        <>
          <button className="btn btn-secondary" onClick={onClose}>Cancel</button>
          <button className="btn" form="refund-form" disabled={busy}>
            {busy ? 'Requesting…' : 'Request refund'}
          </button>
        </>
      }
    >
      <form id="refund-form" onSubmit={submit}>
        {error && <div className="alert">{error.message}</div>}

        <div className="field">
          <label>Amount *</label>
          <input type="number" step="0.01" min="0.01" max={payment.amount} value={amount}
                 required onChange={(e) => setAmount(e.target.value)} />
        </div>

        <div className="field">
          <label>Reason *</label>
          <input value={reason} required onChange={(e) => setReason(e.target.value)}
                 placeholder="Why is this being refunded?" />
        </div>

        <p className="hint">
          The refund is created <strong>pending</strong>. Someone with approval rights must
          release it before the money comes off the invoice.
        </p>
      </form>
    </Modal>
  )
}
