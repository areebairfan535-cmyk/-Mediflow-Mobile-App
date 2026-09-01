import { useEffect, useState } from 'react'
import { api } from '../api.js'
import {
  Card, Badge, Loading, Empty, ErrorBox, Modal,
  AllergyBanner, dateOf, initials,
} from '../components.jsx'
import { AiPatientSummary } from '../ai.jsx'

/**
 * The patient chart (§5): demographics, allergies, conditions, visit history
 * and prescriptions. This is the "medical history / previous visits" step of
 * the §4 consultation workflow.
 */
export default function PatientChart({ patientId, session, go }) {
  const [state, setState] = useState({ loading: true })
  const [tab, setTab] = useState('summary')
  const [modal, setModal] = useState(null)
  const [notice, setNotice] = useState(null)

  async function load() {
    setState({ loading: true })
    try {
      const [patient, prescriptions] = await Promise.all([
        api.patient(patientId),
        api.patientPrescriptions(patientId).catch(() => null),
      ])
      setState({
        loading: false,
        patient: patient.data.patient,
        prescriptions: prescriptions?.data.prescriptions ?? [],
      })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [patientId])

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  const p = state.patient

  return (
    <>
      <div className="page-head">
        <div>
          <button className="btn btn-sm btn-secondary" onClick={() => go('patients')}>
            ← Patients
          </button>
        </div>
      </div>

      <div className="patient-header">
        <div className="avatar">{initials(`${p.first_name} ${p.last_name}`)}</div>
        <div>
          <h1>{p.first_name} {p.last_name}</h1>
          <div className="meta">
            <span className="mono">{p.mrn}</span>
            {p.age != null && ` · ${p.age} yrs`}
            {p.gender !== 'unknown' && ` · ${p.gender}`}
            {p.blood_group && ` · ${p.blood_group}`}
            {p.phone && ` · ${p.phone}`}
          </div>
        </div>
        <div className="spacer" />
        {session.can('patient.update') && (
          <button className="btn btn-sm btn-secondary" style={{ marginRight: 10 }}
                  onClick={() => setModal('details')}>
            Edit details
          </button>
        )}
        <Badge>{p.status}</Badge>
      </div>

      <AllergyBanner allergies={p.allergies} />

      {/* §25: the chart in the order it should be read. Renders nothing when no
          AI provider is configured. */}
      <AiPatientSummary patientId={patientId} session={session} />

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      <div className="row" style={{ marginBottom: 14 }}>
        {['summary', 'visits', 'prescriptions'].map((t) => (
          <button key={t}
                  className={`btn btn-sm ${tab === t ? '' : 'btn-secondary'}`}
                  onClick={() => setTab(t)}>
            {t[0].toUpperCase() + t.slice(1)}
          </button>
        ))}
      </div>

      {tab === 'summary' && (
        <div className="grid-2">
          <Card
            title="Allergies"
            action={session.can('patient.update') && (
              <button className="btn btn-sm btn-secondary" onClick={() => setModal('allergy')}>
                Add
              </button>
            )}
            bodyless
          >
            {(p.allergies || []).length === 0 ? (
              <Empty icon="✓" title="No known allergies" />
            ) : (
              <div style={{ padding: 12 }}>
                {p.allergies.map((a) => (
                  <div className="line-item" key={a.id}>
                    <div className="body">
                      <div className="title">{a.substance}</div>
                      <div className="sub">
                        {a.reaction || 'No reaction recorded'} · noted {dateOf(a.noted_on)}
                      </div>
                    </div>
                    <Badge tone={
                      a.severity === 'life_threatening' || a.severity === 'severe' ? 'danger'
                        : a.severity === 'moderate' ? 'warn' : 'neutral'
                    }>
                      {a.severity.replace(/_/g, ' ')}
                    </Badge>
                    {session.can('patient.update') && (
                      <button className="icon-btn" title="Mark inactive"
                              onClick={async () => {
                                try {
                                  await api.removeAllergy(patientId, a.id)
                                  setNotice({ ok: true, message: 'Allergy marked inactive.' })
                                  load()
                                } catch (e) { setNotice({ ok: false, message: e.message }) }
                              }}>✕</button>
                    )}
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card
            title="Medical conditions"
            action={session.can('patient.update') && (
              <button className="btn btn-sm btn-secondary" onClick={() => setModal('condition')}>
                Add
              </button>
            )}
            bodyless
          >
            {(p.conditions || []).length === 0 ? (
              <Empty icon="✓" title="No ongoing conditions" />
            ) : (
              <div style={{ padding: 12 }}>
                {p.conditions.map((c) => (
                  <div className="line-item" key={c.id}>
                    <div className="body">
                      <div className="title">{c.name}</div>
                      <div className="sub">
                        {c.icd10_code && <span className="mono">{c.icd10_code}</span>}
                        {c.diagnosed_on && ` · since ${dateOf(c.diagnosed_on)}`}
                      </div>
                    </div>
                    <Badge tone={c.status === 'chronic' ? 'warn' : undefined}>{c.status}</Badge>
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card title="Contact">
            <table>
              <tbody>
                <tr><td className="strong" style={{ width: 120 }}>Phone</td><td>{p.phone || '—'}</td></tr>
                <tr><td className="strong">Email</td><td>{p.email || '—'}</td></tr>
                <tr><td className="strong">Address</td><td>{p.address || '—'}</td></tr>
                <tr><td className="strong">City</td><td>{p.city || '—'}</td></tr>
              </tbody>
            </table>
          </Card>

          <Card title="Emergency contact">
            <table>
              <tbody>
                <tr><td className="strong" style={{ width: 120 }}>Name</td><td>{p.emergency_name || '—'}</td></tr>
                <tr><td className="strong">Phone</td><td>{p.emergency_phone || '—'}</td></tr>
                <tr><td className="strong">Relation</td><td>{p.emergency_relation || '—'}</td></tr>
                <tr><td className="strong">Date of birth</td><td>{p.date_of_birth ? dateOf(p.date_of_birth) : '—'}</td></tr>
              </tbody>
            </table>
          </Card>
        </div>
      )}

      {tab === 'visits' && (
        <Card title="Visit history" bodyless>
          {(p.recent_encounters || []).length === 0 ? (
            <Empty icon="📋" title="No visits recorded yet" />
          ) : (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr><th>Encounter</th><th>Date</th><th>Doctor</th><th>Complaint</th><th>Status</th><th /></tr>
                </thead>
                <tbody>
                  {p.recent_encounters.map((e) => (
                    <tr key={e.id}>
                      <td className="mono">{e.encounter_no}</td>
                      <td>{dateOf(e.created_at)}</td>
                      <td>{e.doctor_name} <span className="hint">{e.specialty}</span></td>
                      <td>{e.chief_complaint || '—'}</td>
                      <td><Badge tone={e.status === 'open' ? 'warn' : undefined}>{e.status}</Badge></td>
                      <td>
                        <button className="btn btn-sm btn-secondary"
                                onClick={() => go('consultation', { encounterId: e.id })}>
                          Open
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {tab === 'prescriptions' && (
        <Card title="Prescriptions" bodyless>
          {state.prescriptions.length === 0 ? (
            <Empty icon="💊" title="No prescriptions yet" />
          ) : (
            <div style={{ padding: 14 }}>
              {state.prescriptions.map((rx) => (
                <div className="card" key={rx.id} style={{ marginBottom: 12 }}>
                  <div className="card-head">
                    <div>
                      <span className="mono strong">{rx.prescription_no}</span>
                      <span className="hint"> · {dateOf(rx.created_at)} · {rx.doctor_name}</span>
                    </div>
                    <Badge tone={rx.status === 'issued' ? 'ok' : rx.status === 'cancelled' ? 'danger' : 'warn'}>
                      {rx.status}
                    </Badge>
                  </div>
                  <div className="card-body">
                    {rx.items.map((it) => (
                      <div className="line-item" key={it.id}>
                        <div className="body">
                          <div className="title">{it.medication_name}</div>
                          <div className="sub">
                            {[it.dosage, it.frequency, it.duration].filter(Boolean).join(' · ') || '—'}
                            {it.instructions ? ` — ${it.instructions}` : ''}
                          </div>
                        </div>
                      </div>
                    ))}
                    {rx.general_advice && (
                      <p className="hint" style={{ marginTop: 8 }}>Advice: {rx.general_advice}</p>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {modal === 'allergy' && (
        <QuickForm
          title="Record allergy"
          fields={[
            { key: 'substance', label: 'Substance', required: true, placeholder: 'Penicillin' },
            { key: 'reaction', label: 'Reaction', placeholder: 'Rash, swelling' },
            {
              key: 'severity', label: 'Severity', type: 'select', default: 'mild',
              options: ['mild', 'moderate', 'severe', 'life_threatening'],
            },
          ]}
          onClose={() => setModal(null)}
          onSubmit={async (body) => {
            await api.addAllergy(patientId, body)
            setModal(null)
            setNotice({ ok: true, message: 'Allergy recorded.' })
            load()
          }}
        />
      )}

      {/* The patient app lets a patient correct their own contact details and
          nothing else (§3). Everything else on the record — the spelling of a
          name, a date of birth, a blood group — is the clinic's to fix, and
          this is where they fix it. `keepEmpty` matters: clearing a field has
          to mean "remove this", not "leave it as it was". */}
      {modal === 'details' && (
        <QuickForm
          title={`Edit ${p.first_name} ${p.last_name}`}
          keepEmpty
          fields={[
            { key: 'first_name', label: 'First name', required: true, default: p.first_name },
            { key: 'last_name', label: 'Last name', required: true, default: p.last_name },
            { key: 'date_of_birth', label: 'Date of birth', type: 'date', default: p.date_of_birth || '' },
            {
              key: 'gender', label: 'Gender', type: 'select', default: p.gender || 'unknown',
              options: ['male', 'female', 'other', 'unknown'],
            },
            { key: 'blood_group', label: 'Blood group', default: p.blood_group || '', placeholder: 'B+' },
            { key: 'phone', label: 'Phone', default: p.phone || '' },
            { key: 'email', label: 'Email', default: p.email || '' },
            { key: 'address', label: 'Address', default: p.address || '' },
            { key: 'city', label: 'City', default: p.city || '' },
            { key: 'emergency_name', label: 'Emergency contact', default: p.emergency_name || '' },
            { key: 'emergency_phone', label: 'Their phone', default: p.emergency_phone || '' },
            { key: 'emergency_relation', label: 'Relation', default: p.emergency_relation || '' },
          ]}
          onClose={() => setModal(null)}
          onSubmit={async (body) => {
            await api.updatePatient(patientId, body)
            setModal(null)
            setNotice({ ok: true, message: 'Patient details updated.' })
            load()
          }}
        />
      )}

      {modal === 'condition' && (
        <QuickForm
          title="Record medical condition"
          fields={[
            { key: 'name', label: 'Condition', required: true, placeholder: 'Type 2 Diabetes' },
            { key: 'icd10_code', label: 'ICD-10 code', placeholder: 'E11' },
            {
              key: 'status', label: 'Status', type: 'select', default: 'active',
              options: ['active', 'chronic', 'resolved'],
            },
            { key: 'diagnosed_on', label: 'Diagnosed on', type: 'date' },
          ]}
          onClose={() => setModal(null)}
          onSubmit={async (body) => {
            await api.addCondition(patientId, body)
            setModal(null)
            setNotice({ ok: true, message: 'Condition recorded.' })
            load()
          }}
        />
      )}
    </>
  )
}

/** Small generic form-in-a-modal, so simple records don't each need a component. */
function QuickForm({ title, fields, onClose, onSubmit, keepEmpty = false }) {
  const [values, setValues] = useState(
    Object.fromEntries(fields.map((f) => [f.key, f.default ?? ''])),
  )
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      // Creating: an empty box means "not recorded", so it is left out.
      // Editing: an emptied box means "delete what was there", so it is sent.
      await onSubmit(keepEmpty ? values : Object.fromEntries(
        Object.entries(values).filter(([, v]) => String(v).trim() !== ''),
      ))
    } catch (err) {
      setError(err)
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
          <button className="btn" form="quick-form" disabled={busy}>
            {busy ? 'Saving…' : 'Save'}
          </button>
        </>
      }
    >
      <form id="quick-form" onSubmit={submit}>
        {error && <div className="alert">{error.message}</div>}
        {fields.map((f) => (
          <div className="field" key={f.key}>
            <label>{f.label}{f.required ? ' *' : ''}</label>
            {f.type === 'select' ? (
              <select value={values[f.key]}
                      onChange={(e) => setValues({ ...values, [f.key]: e.target.value })}>
                {f.options.map((o) => (
                  <option key={o} value={o}>{o.replace(/_/g, ' ')}</option>
                ))}
              </select>
            ) : (
              <input type={f.type || 'text'} value={values[f.key]} required={f.required}
                     placeholder={f.placeholder}
                     onChange={(e) => setValues({ ...values, [f.key]: e.target.value })} />
            )}
          </div>
        ))}
      </form>
    </Modal>
  )
}
