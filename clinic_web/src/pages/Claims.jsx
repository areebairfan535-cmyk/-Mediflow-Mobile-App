import { useEffect, useState } from 'react'
import { api } from '../api.js'
import {
  Card, Stat, Badge, Loading, Empty, ErrorBox, dateOf,
} from '../components.jsx'
import { money } from './Billing.jsx'

const CLAIM_TONE = {
  draft: 'neutral',
  submitted: 'accent',
  processing: 'warn',
  approved: 'ok',
  partially_approved: 'warn',
  rejected: 'danger',
  resubmission: 'warn',
  paid: 'ok',
}

export { CLAIM_TONE }

/**
 * The claims worklist (§8) plus the pipeline figures a billing manager
 * actually chases: what is outstanding, what got settled, and why claims are
 * being rejected.
 */
export default function Claims({ session, go }) {
  const [filters, setFilters] = useState({ status: '', search: '', open: '' })
  const [state, setState] = useState({ loading: true })
  const [pipeline, setPipeline] = useState(null)

  async function load() {
    setState((s) => ({ ...s, loading: true }))
    try {
      const res = await api.claims({ ...filters, per_page: 50 })
      setState({ loading: false, rows: res.data, meta: res.meta })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [filters.status, filters.open])

  useEffect(() => {
    api.claimPipeline().then((r) => setPipeline(r.data)).catch(() => {})
  }, [])

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Insurance claims</h1>
          <p>Submit, chase and settle what insurers owe the clinic.</p>
        </div>
      </div>

      {pipeline && (
        <div className="stat-grid">
          <Stat label="Awaiting a decision" money value={money(pipeline.outstanding)}
                hint="submitted or processing" />
          <Stat label="Approved, unpaid" money value={money(pipeline.approved)}
                hint="insurer has agreed" />
          <Stat label="Settled" money value={money(pipeline.settled)}
                hint="money received" />
          <Stat label="Rejected" money value={money(pipeline.rejected)}
                hint={pipeline.rejections?.length ? `${pipeline.rejections.length} reasons` : 'none'} />
        </div>
      )}

      <Card>
        <form className="filters" onSubmit={(e) => { e.preventDefault(); load() }}>
          <div className="field" style={{ flex: 1, minWidth: 200 }}>
            <label>Search</label>
            <input value={filters.search}
                   onChange={(e) => setFilters({ ...filters, search: e.target.value })}
                   placeholder="Claim no, invoice, insurer ref or patient…" />
          </div>
          <div className="field">
            <label>Status</label>
            <select value={filters.status}
                    onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
              <option value="">All</option>
              {Object.keys(CLAIM_TONE).map((s) => (
                <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
              ))}
            </select>
          </div>
          <div className="field">
            <label>Only open</label>
            <select value={filters.open}
                    onChange={(e) => setFilters({ ...filters, open: e.target.value })}>
              <option value="">No</option>
              <option value="1">Yes</option>
            </select>
          </div>
          <button className="btn">Search</button>
        </form>
      </Card>

      <div style={{ marginTop: 16 }}>
        {state.loading ? (
          <Loading />
        ) : state.error ? (
          <ErrorBox error={state.error} onRetry={load} />
        ) : state.rows.length === 0 ? (
          <Card bodyless>
            <Empty icon="🛡" title="No claims"
                   hint="Open an issued invoice and raise a claim against the patient's policy." />
          </Card>
        ) : (
          <Card title={`${state.meta.total} claims`} bodyless>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Claim</th><th>Patient</th><th>Insurer</th><th>Invoice</th>
                    <th style={{ textAlign: 'right' }}>Claimed</th>
                    <th style={{ textAlign: 'right' }}>Approved</th>
                    <th>Age</th><th>Status</th><th />
                  </tr>
                </thead>
                <tbody>
                  {state.rows.map((cl) => (
                    <tr key={cl.id}>
                      <td className="mono">{cl.claim_no}</td>
                      <td className="strong">
                        {cl.patient_name} <span className="hint mono">{cl.mrn}</span>
                      </td>
                      <td>
                        {cl.provider_name}
                        <div className="hint mono">{cl.policy_number}</div>
                      </td>
                      <td className="mono">{cl.invoice_no}</td>
                      <td className="mono" style={{ textAlign: 'right' }}>
                        {money(cl.claimed_amount, cl.currency_code)}
                      </td>
                      <td className="mono strong" style={{ textAlign: 'right' }}>
                        {Number(cl.approved_amount) > 0 ? money(cl.approved_amount) : '—'}
                      </td>
                      <td>
                        {cl.days_pending != null && cl.days_pending >= 0 ? (
                          <span className={cl.days_pending > 30 ? 'badge badge-danger' : 'hint'}>
                            {cl.days_pending}d
                          </span>
                        ) : '—'}
                      </td>
                      <td>
                        <Badge tone={CLAIM_TONE[cl.status]}>{cl.status.replace(/_/g, ' ')}</Badge>
                      </td>
                      <td>
                        <button className="btn btn-sm btn-secondary"
                                onClick={() => go('claim', { claimId: cl.id })}>
                          Open
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        )}
      </div>

      {/* §8 asks for rejection analytics, not just a rejected count. */}
      {pipeline?.rejections?.length > 0 && (
        <div style={{ marginTop: 20 }}>
          <Card title="Why claims are being rejected" bodyless>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Code</th><th>Reason</th><th>Insurer</th>
                    <th style={{ textAlign: 'right' }}>Claims</th>
                    <th style={{ textAlign: 'right' }}>Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {pipeline.rejections.map((r, i) => (
                    <tr key={i}>
                      <td className="mono">{r.code}</td>
                      <td>{r.rejection_reason || '—'}</td>
                      <td>{r.provider_name}</td>
                      <td className="mono" style={{ textAlign: 'right' }}>{r.claims}</td>
                      <td className="mono strong" style={{ textAlign: 'right' }}>
                        {money(r.amount)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </div>
      )}
    </>
  )
}
