import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, Empty, ErrorBox, dateOf } from '../components.jsx'
import { LabResultsForm } from '../LabResultsForm.jsx'
import { money } from './Billing.jsx'

/**
 * The lab's worklist (§4, §5): what the doctors have asked for, and what
 * has come back.
 *
 * Lab staff never needed a patient chart to do their job — they need the
 * order, the tests on it, and a box to put the values in. Open orders sit
 * first; a completed one shows where it was done and what it cost, which is
 * also what the owner was told when it was filed.
 */
export default function Lab({ session, go }) {
  const [state, setState] = useState({ loading: true })
  const [tab, setTab] = useState('open')
  const [order, setOrder] = useState(null)
  const [notice, setNotice] = useState(null)

  async function load() {
    setState((s) => ({ ...s, loading: !s.rows }))
    try {
      const res = await api.labOrders()
      setState({ loading: false, rows: res.data.lab_orders || [] })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [])

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  const open = state.rows.filter((o) => !['completed', 'cancelled'].includes(o.status))
  const done = state.rows.filter((o) => o.status === 'completed')
  const shown = tab === 'open' ? open : done

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Lab</h1>
          <p>Tests the doctors recommended. Enter the results and the patient, the doctor and the owner are told.</p>
        </div>
        <button className="btn btn-secondary btn-sm" onClick={load}>Refresh</button>
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      <div className="row" style={{ marginBottom: 14 }}>
        <button className={`btn btn-sm ${tab === 'open' ? '' : 'btn-secondary'}`} onClick={() => setTab('open')}>
          To do ({open.length})
        </button>
        <button className={`btn btn-sm ${tab === 'done' ? '' : 'btn-secondary'}`} onClick={() => setTab('done')}>
          Completed ({done.length})
        </button>
      </div>

      <Card title={tab === 'open' ? `${open.length} waiting` : `${done.length} completed`} bodyless>
        {shown.length === 0 ? (
          <Empty icon="🧪" title={tab === 'open' ? 'Nothing waiting' : 'Nothing completed yet'} />
        ) : shown.map((o) => (
          <div className="slot-row" key={o.id} style={{ alignItems: 'flex-start' }}>
            <div className="slot-time" style={{ minWidth: 110 }}>
              {dateOf(o.ordered_at || o.created_at)}<br />
              <span className="hint mono">{o.order_no}</span>
            </div>
            <div className="slot-main">
              <div className="who">
                {o.patient_name} <span className="hint mono">{o.mrn}</span>
              </div>
              <div className="why">
                {(o.tests || []).length
                  ? o.tests.map((t) => t.test_name + (t.price != null ? ` (${money(t.price)})` : '')).join(' · ')
                  : 'No tests named'}
              </div>
              {o.clinical_notes && <div className="why hint">Note: {o.clinical_notes}</div>}
              {o.status === 'completed' && (
                <div className="why hint">
                  Done at {o.lab_name || 'the clinic lab'}
                  {o.total_charge != null ? ` · charged ${money(o.total_charge)}` : ''}
                  {` · ${o.results.length} result(s)`}
                </div>
              )}
            </div>
            <Badge tone={o.priority === 'stat' ? 'danger' : o.priority === 'urgent' ? 'warn' : 'neutral'}>
              {o.priority}
            </Badge>
            <Badge tone={o.status === 'completed' ? 'ok' : 'warn'}>{o.status.replace(/_/g, ' ')}</Badge>
            <div className="slot-actions">
              {o.status !== 'completed' && o.status !== 'cancelled' && session.can('lab.result') && (
                <button className="btn btn-sm" onClick={() => setOrder(o)}>Enter results</button>
              )}
              {session.can('patient.view') && (
                <button className="btn btn-sm btn-secondary"
                        onClick={() => go('chart', { patientId: o.patient_id })}>Chart</button>
              )}
            </div>
          </div>
        ))}
      </Card>

      {order && (
        <LabResultsForm
          order={order}
          onClose={() => setOrder(null)}
          onSubmit={async (results, extra) => {
            await api.recordLabResults(order.id, results, extra)
            setOrder(null)
            setNotice({ ok: true, message: `Results filed for ${order.order_no}. Patient and owner have been told.` })
            load()
          }}
        />
      )}
    </>
  )
}
