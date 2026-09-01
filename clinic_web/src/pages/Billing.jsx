import { useEffect, useState } from 'react'
import { api } from '../api.js'
import {
  Card, Stat, Badge, Loading, Empty, ErrorBox, dateOf, todayISO,
} from '../components.jsx'

const STATUS_TONE = {
  draft: 'neutral',
  issued: 'accent',
  partially_paid: 'warn',
  paid: 'ok',
  overdue: 'danger',
  cancelled: 'neutral',
  refunded: 'warn',
}

/** Currency formatting stays with the invoice's own currency code (§23). */
export function money(amount, currency = '') {
  const n = Number(amount ?? 0)
  return `${currency ? currency + ' ' : ''}${n.toLocaleString(undefined, {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
  })}`
}

export { STATUS_TONE }

export default function Billing({ session, go }) {
  const [filters, setFilters] = useState({ status: '', search: '', outstanding: '' })
  const [state, setState] = useState({ loading: true })
  const [report, setReport] = useState(null)

  async function load() {
    setState((s) => ({ ...s, loading: true }))
    try {
      const res = await api.invoices({ ...filters, per_page: 50 })
      setState({ loading: false, rows: res.data, meta: res.meta })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [filters.status, filters.outstanding])

  useEffect(() => {
    // Reports are permission-gated; a receptionist simply does not see them.
    if (!session.can('report.view')) return
    api.financialReport({ from: monthStart(), to: todayISO() })
      .then((r) => setReport(r.data))
      .catch(() => {})
  }, [])

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Billing</h1>
          <p>Invoices, payments and outstanding balances.</p>
        </div>
      </div>

      {report && (
        <div className="stat-grid">
          <Stat label="Billed this month" money
                value={money(report.summary.invoices.billed, report.summary.currency)}
                hint={`${report.summary.invoices.count} invoices`} />
          <Stat label="Collected" money
                value={money(report.summary.invoices.collected, report.summary.currency)}
                hint={`${report.summary.cash.payments} payments`} />
          <Stat label="Outstanding" money
                value={money(report.summary.invoices.outstanding, report.summary.currency)}
                hint="still to collect" />
          <Stat label="Refunded" money
                value={money(report.summary.cash.refunded, report.summary.currency)}
                hint={`${report.summary.cash.refunds} refunds`} />
        </div>
      )}

      <Card>
        <form className="filters" onSubmit={(e) => { e.preventDefault(); load() }}>
          <div className="field" style={{ flex: 1, minWidth: 200 }}>
            <label>Search</label>
            <input value={filters.search}
                   onChange={(e) => setFilters({ ...filters, search: e.target.value })}
                   placeholder="Invoice no, patient name or MRN…" />
          </div>
          <div className="field">
            <label>Status</label>
            <select value={filters.status}
                    onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
              <option value="">All</option>
              {Object.keys(STATUS_TONE).map((s) => (
                <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
              ))}
            </select>
          </div>
          <div className="field">
            <label>Only unpaid</label>
            <select value={filters.outstanding}
                    onChange={(e) => setFilters({ ...filters, outstanding: e.target.value })}>
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
            <Empty icon="🧾" title="No invoices"
                   hint="Complete a consultation, then bill it from the consultation screen." />
          </Card>
        ) : (
          <Card title={`${state.meta.total} invoices`} bodyless>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Invoice</th><th>Patient</th><th>Date</th>
                    <th style={{ textAlign: 'right' }}>Total</th>
                    <th style={{ textAlign: 'right' }}>Paid</th>
                    <th style={{ textAlign: 'right' }}>Balance</th>
                    <th>Status</th><th />
                  </tr>
                </thead>
                <tbody>
                  {state.rows.map((i) => (
                    <tr key={i.id}>
                      <td className="mono">{i.invoice_no}</td>
                      <td className="strong">
                        {i.patient_name} <span className="hint mono">{i.mrn}</span>
                      </td>
                      <td>{dateOf(i.created_at)}</td>
                      <td className="mono" style={{ textAlign: 'right' }}>
                        {money(i.grand_total, i.currency_code)}
                      </td>
                      <td className="mono" style={{ textAlign: 'right' }}>
                        {money(i.paid_total)}
                      </td>
                      <td className="mono strong" style={{ textAlign: 'right' }}>
                        {money(i.balance_due)}
                      </td>
                      <td><Badge tone={STATUS_TONE[i.status]}>{i.status.replace(/_/g, ' ')}</Badge></td>
                      <td>
                        <button className="btn btn-sm btn-secondary"
                                onClick={() => go('invoice', { invoiceId: i.id })}>
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
    </>
  )
}

function monthStart() {
  const t = todayISO()
  return `${t.slice(0, 8)}01`
}
