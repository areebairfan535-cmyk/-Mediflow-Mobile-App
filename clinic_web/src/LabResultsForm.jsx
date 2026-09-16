import { useState } from 'react'
import { Modal } from './components.jsx'

/**
 * Entering a lab order's results — from the patient chart or the Lab page.
 *
 * The rows start as the tests the doctor named, so the lab fills in values
 * rather than retyping names. Where it was done and what it cost go on the
 * order: the patient may have used our lab or any other, and the owner is
 * told which, with the figure.
 */
export function LabResultsForm({ order, onClose, onSubmit }) {
  const blank = { test_name: '', value: '', unit: '', reference_range: '', flag: 'normal', comments: '' }
  const ordered = (order.tests || []).map((t) => ({ ...blank, test_name: t.test_name }))
  const [rows, setRows] = useState(ordered.length ? ordered : [{ ...blank }])
  const [labName, setLabName] = useState(order.lab_name || 'Clinic lab')
  // The charge starts as the sum of the prices the doctor put on the tests —
  // right when it was our lab, a starting point when it was someone else's.
  const quoted = (order.tests || []).reduce((sum, t) => sum + Number(t.price || 0), 0)
  const [charge, setCharge] = useState(order.total_charge ?? (quoted > 0 ? quoted : ''))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const named = rows.filter((r) => r.test_name.trim() !== '')

  function update(i, key, value) {
    setRows(rows.map((r, n) => (n === i ? { ...r, [key]: value } : r)))
  }

  async function submit(e) {
    e.preventDefault()
    if (named.length === 0) {
      setError(new Error('Add at least one test name.'))
      return
    }

    setBusy(true)
    setError(null)
    try {
      // Empty boxes are "not reported", so they are dropped rather than
      // stored as empty strings pretending to be readings.
      await onSubmit(
        named.map((r) => Object.fromEntries(
          Object.entries(r).filter(([, v]) => String(v).trim() !== ''),
        )),
        { lab_name: labName.trim() || undefined, total_charge: charge === '' ? undefined : Number(charge) },
      )
    } catch (err) {
      setError(err)
      setBusy(false)
    }
  }

  return (
    <Modal
      title={`Results for ${order.order_no}`}
      onClose={onClose}
      wide
      footer={
        <>
          <button className="btn btn-secondary" onClick={onClose}>Cancel</button>
          <button className="btn" form="lab-results-form" disabled={busy}>
            {busy ? 'Saving…' : `Record ${named.length || ''} result${named.length === 1 ? '' : 's'}`}
          </button>
        </>
      }
    >
      <form id="lab-results-form" onSubmit={submit}>
        {error && (
          <div className="alert">
            {error.message}
            {error.fieldMessages?.map((m) => <div key={m}>{m}</div>)}
          </div>
        )}

        <p className="hint" style={{ marginBottom: 12 }}>
          Recording results closes this order — everything on the panel goes in
          one go. The owner is told where it was done and what it cost.
        </p>

        <div className="row" style={{ alignItems: 'flex-end', marginBottom: 12 }}>
          <div className="field" style={{ flex: 2, marginBottom: 0 }}>
            <label>Done at</label>
            <input value={labName} placeholder="Clinic lab, or the lab the patient used"
                   onChange={(ev) => setLabName(ev.target.value)} />
          </div>
          <div className="field" style={{ flex: 1, marginBottom: 0 }}>
            <label>Total charge</label>
            <input type="number" min="0" value={charge} placeholder="2300"
                   onChange={(ev) => setCharge(ev.target.value)} />
          </div>
        </div>

        {rows.map((r, i) => (
          <div key={i} className="card" style={{ marginBottom: 10, padding: 12 }}>
            <div className="row" style={{ alignItems: 'flex-end' }}>
              <div className="field" style={{ flex: 2, marginBottom: 0 }}>
                <label>Test {i + 1} *</label>
                <input value={r.test_name} placeholder="Haemoglobin"
                       onChange={(ev) => update(i, 'test_name', ev.target.value)} />
              </div>
              <div className="field" style={{ flex: 1, marginBottom: 0 }}>
                <label>Value</label>
                <input value={r.value} placeholder="13.4"
                       onChange={(ev) => update(i, 'value', ev.target.value)} />
              </div>
              <div className="field" style={{ flex: 1, marginBottom: 0 }}>
                <label>Unit</label>
                <input value={r.unit} placeholder="g/dL"
                       onChange={(ev) => update(i, 'unit', ev.target.value)} />
              </div>
            </div>

            <div className="row" style={{ alignItems: 'flex-end', marginTop: 10 }}>
              <div className="field" style={{ flex: 1, marginBottom: 0 }}>
                <label>Reference range</label>
                <input value={r.reference_range} placeholder="12.0–15.5"
                       onChange={(ev) => update(i, 'reference_range', ev.target.value)} />
              </div>
              <div className="field" style={{ flex: 1, marginBottom: 0 }}>
                <label>Flag</label>
                <select value={r.flag}
                        onChange={(ev) => update(i, 'flag', ev.target.value)}>
                  {['normal', 'low', 'high', 'critical'].map((f) => (
                    <option key={f} value={f}>{f}</option>
                  ))}
                </select>
              </div>
              <div className="field" style={{ flex: 2, marginBottom: 0 }}>
                <label>Comment</label>
                <input value={r.comments} placeholder="Sample haemolysed"
                       onChange={(ev) => update(i, 'comments', ev.target.value)} />
              </div>
              {rows.length > 1 && (
                <button type="button" className="icon-btn" title="Remove this test"
                        onClick={() => setRows(rows.filter((_, n) => n !== i))}>✕</button>
              )}
            </div>
          </div>
        ))}

        <button type="button" className="btn btn-sm btn-secondary"
                onClick={() => setRows([...rows, { ...blank }])}>
          + Another test
        </button>
      </form>
    </Modal>
  )
}
