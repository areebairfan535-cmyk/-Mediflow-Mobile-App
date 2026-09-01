import { useEffect, useState } from 'react'
import { api } from '../api.js'
import {
  Card, Badge, Loading, ErrorBox, Modal, dateOf, initials,
} from '../components.jsx'
import { money } from './Billing.jsx'
import { CLAIM_TONE } from './Claims.jsx'
import { AiClaimReview } from '../ai.jsx'

/**
 * One claim, and the actions its current status allows (§8).
 *
 * The buttons mirror the server's transition table rather than inventing a
 * parallel one — anything else and the UI offers actions the API will refuse.
 */
export default function ClaimDetail({ claimId, session, go }) {
  const [state, setState] = useState({ loading: true })
  const [notice, setNotice] = useState(null)
  const [modal, setModal] = useState(null)
  const [busy, setBusy] = useState(false)

  async function load() {
    try {
      const res = await api.claim(claimId)
      setState({ loading: false, cl: res.data.claim })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [claimId])

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  const cl = state.cl
  const cur = cl.currency_code

  async function act(fn, message) {
    setBusy(true)
    setNotice(null)
    try {
      const result = await fn()
      if (message) setNotice({ ok: true, message })
      await load()
      return result
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    } finally {
      setBusy(false)
    }
  }

  const can = (slug) => session.can(slug)

  return (
    <>
      <div className="page-head">
        <div>
          <button className="btn btn-sm btn-secondary" onClick={() => go('claims')}>
            ← Claims
          </button>
        </div>
        <Badge tone={CLAIM_TONE[cl.status]}>{cl.status.replace(/_/g, ' ')}</Badge>
      </div>

      <div className="patient-header">
        <div className="avatar">{initials(cl.patient_name)}</div>
        <div>
          <h1 className="mono">{cl.claim_no}</h1>
          <div className="meta">
            {cl.patient_name} · <span className="mono">{cl.mrn}</span>
            {' · '}{cl.provider_name}
            {cl.external_claim_no && <> · insurer ref <span className="mono">{cl.external_claim_no}</span></>}
          </div>
        </div>
        <div className="spacer" />
        <div style={{ textAlign: 'right' }}>
          <div className="hint">Claimed</div>
          <div style={{ fontSize: 24, fontWeight: 680, letterSpacing: '-0.02em' }}>
            {money(cl.claimed_amount, cur)}
          </div>
        </div>
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      {cl.rejection_reason && (
        <div className="allergy-banner">
          <div className="title">
            {cl.status === 'rejected' ? '✕ Rejected' : '⚠ Partially approved'}
            {cl.rejection_code ? ` — ${cl.rejection_code}` : ''}
          </div>
          <div className="item">{cl.rejection_reason}</div>
        </div>
      )}

      <div className="row" style={{ marginBottom: 16 }}>
        {cl.status === 'draft' && can('claim.submit') && (
          <button className="btn" disabled={busy} onClick={() => setModal('submit')}>
            Submit to insurer
          </button>
        )}
        {cl.status === 'submitted' && can('claim.update') && (
          <button className="btn btn-secondary" disabled={busy}
                  onClick={() => act(() => api.claimProcessing(cl.id), 'Marked as processing.')}>
            Mark processing
          </button>
        )}
        {['submitted', 'processing'].includes(cl.status) && can('claim.update') && (
          <button className="btn" disabled={busy} onClick={() => setModal('decision')}>
            Record decision
          </button>
        )}
        {['approved', 'partially_approved'].includes(cl.status) && can('claim.update') && (
          <button className="btn btn-ok" disabled={busy} onClick={() => setModal('paid')}>
            Record payment
          </button>
        )}
        {cl.status === 'rejected' && can('claim.submit') && (
          <button className="btn" disabled={busy}
                  onClick={() => act(
                    async () => {
                      const res = await api.resubmitClaim(cl.id)
                      go('claim', { claimId: res.data.claim.id })
                    },
                    'Resubmission created.',
                  )}>
            Resubmit
          </button>
        )}
        {cl.status === 'resubmission' && can('claim.submit') && (
          <button className="btn" disabled={busy} onClick={() => setModal('submit')}>
            Send resubmission
          </button>
        )}
      </div>

      <div className="grid-2">
        <Card title="Money">
          <table>
            <tbody>
              <tr>
                <td className="strong" style={{ width: 170 }}>Invoice total</td>
                <td className="mono">{money(cl.invoice_total, cur)}</td>
              </tr>
              <tr>
                <td className="strong">Claimed from insurer</td>
                <td className="mono">{money(cl.claimed_amount, cur)}</td>
              </tr>
              <tr>
                <td className="strong">Approved</td>
                <td className="mono">
                  {Number(cl.approved_amount) > 0 ? money(cl.approved_amount, cur) : '—'}
                </td>
              </tr>
              <tr>
                <td className="strong">Paid</td>
                <td className="mono">
                  {Number(cl.paid_amount) > 0 ? money(cl.paid_amount, cur) : '—'}
                </td>
              </tr>
              <tr>
                <td className="strong">Patient owes</td>
                <td className="mono strong">{money(cl.patient_responsibility, cur)}</td>
              </tr>
            </tbody>
          </table>
        </Card>

        <Card title="Policy">
          <table>
            <tbody>
              <tr>
                <td className="strong" style={{ width: 150 }}>Insurer</td>
                <td>{cl.provider_name}</td>
              </tr>
              <tr><td className="strong">Policy no</td><td className="mono">{cl.policy_number}</td></tr>
              <tr><td className="strong">Member id</td><td className="mono">{cl.member_id || '—'}</td></tr>
              <tr><td className="strong">Copay</td><td>{Number(cl.copay_percent)}%</td></tr>
              <tr>
                <td className="strong">Cover used</td>
                <td className="mono">
                  {money(cl.coverage_used)} / {cl.coverage_amount ? money(cl.coverage_amount) : '∞'}
                </td>
              </tr>
              <tr>
                <td className="strong">Typical settlement</td>
                <td>{cl.avg_settle_days ? `${cl.avg_settle_days} days` : '—'}</td>
              </tr>
            </tbody>
          </table>
        </Card>
      </div>

      {/* §9: worth reading before it goes out, useless after. Only shown
          while the claim can still be changed. */}
      {['draft', 'rejected', 'resubmission'].includes(cl.status) && (
        <div style={{ marginTop: 16 }}>
          <AiClaimReview claim={cl} session={session} />
        </div>
      )}

      <div style={{ marginTop: 16 }}>
        <Card title={`Claim lines (${cl.items.length})`} bodyless>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Code</th><th>Description</th><th>Diagnosis</th>
                  <th style={{ textAlign: 'right' }}>Claimed</th>
                  <th style={{ textAlign: 'right' }}>Approved</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {cl.items.map((it) => (
                  <tr key={it.id}>
                    <td className="mono">{it.billing_code || '—'}</td>
                    <td className="strong">{it.description}</td>
                    <td className="mono">{it.diagnosis_code || <span className="hint">none</span>}</td>
                    <td className="mono" style={{ textAlign: 'right' }}>
                      {money(it.claimed_amount)}
                    </td>
                    <td className="mono" style={{ textAlign: 'right' }}>
                      {Number(it.approved_amount) > 0 ? money(it.approved_amount) : '—'}
                    </td>
                    <td><Badge>{it.status}</Badge></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      </div>

      {cl.resubmissions?.length > 0 && (
        <div style={{ marginTop: 16 }}>
          <Card title="Resubmissions" bodyless>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr><th>Claim</th><th>Raised</th>
                      <th style={{ textAlign: 'right' }}>Claimed</th><th>Status</th><th /></tr>
                </thead>
                <tbody>
                  {cl.resubmissions.map((r) => (
                    <tr key={r.id}>
                      <td className="mono">{r.claim_no}</td>
                      <td>{dateOf(r.created_at)}</td>
                      <td className="mono" style={{ textAlign: 'right' }}>
                        {money(r.claimed_amount)}
                      </td>
                      <td><Badge tone={CLAIM_TONE[r.status]}>{r.status.replace(/_/g, ' ')}</Badge></td>
                      <td>
                        <button className="btn btn-sm btn-secondary"
                                onClick={() => go('claim', { claimId: r.id })}>Open</button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </div>
      )}

      {modal === 'submit' && (
        <SimpleModal
          title="Submit to insurer"
          hint="Submitting reserves this amount against the policy's ceiling."
          fields={[{ key: 'external_claim_no', label: 'Insurer reference (if you have one)' }]}
          onClose={() => setModal(null)}
          onSubmit={async (body) => {
            await act(() => api.submitClaim(cl.id, body.external_claim_no || null), 'Claim submitted.')
            setModal(null)
          }}
        />
      )}

      {modal === 'decision' && (
        <SimpleModal
          title="Record the insurer's decision"
          hint={`Claimed: ${money(cl.claimed_amount, cur)}. Enter 0 to record a full rejection. `
              + 'Anything the insurer will not pay moves to the patient.'}
          fields={[
            { key: 'approved_amount', label: 'Approved amount', required: true,
              type: 'number', default: String(cl.claimed_amount) },
            { key: 'rejection_code', label: 'Code (if not paid in full)', placeholder: 'DOC-01' },
            { key: 'rejection_reason', label: 'Reason (if not paid in full)', type: 'textarea' },
          ]}
          onClose={() => setModal(null)}
          onSubmit={async (body) => {
            await act(() => api.decideClaim(cl.id, body), 'Decision recorded.')
            setModal(null)
          }}
        />
      )}

      {modal === 'paid' && (
        <SimpleModal
          title="Record the insurer's payment"
          hint={`This posts a payment of up to ${money(cl.approved_amount, cur)} against `
              + `invoice ${cl.invoice_no}, through the normal payment ledger.`}
          fields={[
            { key: 'amount', label: 'Amount received', required: true,
              type: 'number', default: String(cl.approved_amount) },
            { key: 'reference', label: 'Bank / remittance reference', placeholder: 'NEFT-…' },
          ]}
          onClose={() => setModal(null)}
          onSubmit={async (body) => {
            await act(() => api.payClaim(cl.id, body), 'Payment recorded against the invoice.')
            setModal(null)
          }}
        />
      )}
    </>
  )
}

function SimpleModal({ title, hint, fields, onClose, onSubmit }) {
  const [values, setValues] = useState(
    Object.fromEntries(fields.map((f) => [f.key, f.default ?? ''])),
  )
  const [busy, setBusy] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    try {
      await onSubmit(Object.fromEntries(
        Object.entries(values).filter(([, v]) => String(v).trim() !== ''),
      ))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      title={title}
      onClose={onClose}
      footer={
        <>
          <button className="btn btn-secondary" onClick={onClose}>Cancel</button>
          <button className="btn" form="claim-form" disabled={busy}>
            {busy ? 'Saving…' : 'Save'}
          </button>
        </>
      }
    >
      <form id="claim-form" onSubmit={submit}>
        {hint && <p className="hint" style={{ marginTop: 0 }}>{hint}</p>}
        {fields.map((f) => (
          <div className="field" key={f.key}>
            <label>{f.label}{f.required ? ' *' : ''}</label>
            {f.type === 'textarea' ? (
              <textarea value={values[f.key]} placeholder={f.placeholder}
                        onChange={(e) => setValues({ ...values, [f.key]: e.target.value })} />
            ) : (
              <input type={f.type || 'text'} step={f.type === 'number' ? '0.01' : undefined}
                     value={values[f.key]} required={f.required} placeholder={f.placeholder}
                     onChange={(e) => setValues({ ...values, [f.key]: e.target.value })} />
            )}
          </div>
        ))}
      </form>
    </Modal>
  )
}
