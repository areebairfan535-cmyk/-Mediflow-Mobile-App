import { useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, Empty, ErrorBox } from '../components.jsx'
import { money } from './Billing.jsx'

/**
 * One box, several surfaces (§25).
 *
 * The patient list already finds people by name. What it cannot answer is
 * "who did I diagnose with this", "who is on that medicine", or "whose
 * invoice was INV-000123" — questions a clinic asks daily and that live in
 * five different tables.
 *
 * Not an AI feature despite §25 filing it under the AI phase: a receptionist
 * with a phone number on the line needs the same answer in the same instant
 * every time, which is what SQL gives and a language model does not.
 */
export default function Search({ session, go }) {
  const [q, setQ] = useState('')
  const [state, setState] = useState({ idle: true })

  async function run(e) {
    e?.preventDefault()
    if (q.trim().length < 2) return
    setState({ loading: true })
    try {
      const res = await api.search(q.trim())
      setState({ loading: false, ...res.data })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  const r = state.results

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Search</h1>
          <p>
            Patients, invoices, prescriptions, diagnoses and medicines — the same
            box for all of them.
          </p>
        </div>
      </div>

      <Card>
        <form onSubmit={run} className="row">
          <input
            autoFocus
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="A name, MRN, phone, INV-000123, “pulpitis”, “Amoxicillin”…"
            style={{ flex: 1 }}
          />
          <button className="btn" disabled={q.trim().length < 2}>Search</button>
        </form>
      </Card>

      {state.loading && <Loading />}
      {state.error && <ErrorBox error={state.error} onRetry={run} />}

      {r && state.total === 0 && (
        <Card>
          <Empty icon="🔍" title={`Nothing matches “${state.query}”`}
                 hint="Try part of a name, a document number, or a diagnosis." />
        </Card>
      )}

      {r?.patients?.length > 0 && (
        <Card title={`Patients (${r.patients.length})`} bodyless>
          <div style={{ padding: 12 }}>
            {r.patients.map((p) => (
              <button key={p.id} className="line-item"
                      style={{ width: '100%', textAlign: 'left', background: 'none', border: 0, cursor: 'pointer' }}
                      onClick={() => go('chart', { patientId: p.id })}>
                <div className="body">
                  <div className="title">{p.first_name} {p.last_name}</div>
                  <div className="sub">
                    <span className="mono">{p.mrn}</span>
                    {p.phone ? ` · ${p.phone}` : ''}
                    {p.email ? ` · ${p.email}` : ''}
                  </div>
                </div>
                <Badge tone={p.status === 'active' ? 'ok' : 'neutral'}>{p.status}</Badge>
              </button>
            ))}
          </div>
        </Card>
      )}

      {r?.diagnoses?.length > 0 && (
        <Card title={`Diagnoses (${r.diagnoses.length})`} bodyless>
          <div style={{ padding: 12 }}>
            {r.diagnoses.map((d, i) => (
              <button key={i} className="line-item"
                      style={{ width: '100%', textAlign: 'left', background: 'none', border: 0, cursor: 'pointer' }}
                      onClick={() => go('consultation', { encounterId: d.encounter_id })}>
                <div className="body">
                  <div className="title">{d.description}</div>
                  <div className="sub">
                    {d.patient_name}
                    {d.icd10_code ? ` · ${d.icd10_code}` : ''}
                    {d.completed_at ? ` · ${String(d.completed_at).slice(0, 10)}` : ''}
                  </div>
                </div>
                <span className="mono">{d.encounter_no}</span>
              </button>
            ))}
          </div>
        </Card>
      )}

      {r?.medicines?.length > 0 && (
        <Card title={`Prescribed medicines (${r.medicines.length})`} bodyless>
          <div style={{ padding: 12 }}>
            {r.medicines.map((m, i) => (
              <div className="line-item" key={i}>
                <div className="body">
                  <div className="title">{m.medication_name} {m.dosage}</div>
                  <div className="sub">{m.patient_name}</div>
                </div>
                <span className="mono">{m.prescription_no}</span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {r?.invoices?.length > 0 && (
        <Card title={`Invoices (${r.invoices.length})`} bodyless>
          <div style={{ padding: 12 }}>
            {r.invoices.map((i) => (
              <button key={i.id} className="line-item"
                      style={{ width: '100%', textAlign: 'left', background: 'none', border: 0, cursor: 'pointer' }}
                      onClick={() => go('invoice', { invoiceId: i.id })}>
                <div className="body">
                  <div className="title mono">{i.invoice_no}</div>
                  <div className="sub">{i.patient_name}</div>
                </div>
                <span className="mono">{money(i.grand_total, i.currency_code)}</span>
                <Badge>{i.status.replace(/_/g, ' ')}</Badge>
              </button>
            ))}
          </div>
        </Card>
      )}

      {r?.prescriptions?.length > 0 && (
        <Card title={`Prescriptions (${r.prescriptions.length})`} bodyless>
          <div style={{ padding: 12 }}>
            {r.prescriptions.map((p) => (
              <div className="line-item" key={p.id}>
                <div className="body">
                  <div className="title mono">{p.prescription_no}</div>
                  <div className="sub">{p.patient_name}</div>
                </div>
                <Badge>{p.status}</Badge>
              </div>
            ))}
          </div>
        </Card>
      )}
    </>
  )
}
