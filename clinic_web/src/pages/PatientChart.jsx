import { useEffect, useState } from 'react'
import { api, openPdf } from '../api.js'
import {
  Card, Badge, Loading, Empty, ErrorBox, Modal,
  AllergyBanner, dateOf, initials,
} from '../components.jsx'
import { money } from './Billing.jsx'
import { AiPatientSummary } from '../ai.jsx'

/**
 * The patient chart (§5): demographics, allergies, conditions, visit history,
 * prescriptions, insurance cover and the documents filed against the record.
 * This is the "medical history / previous visits" step of the §4 consultation
 * workflow.
 */
export default function PatientChart({ patientId, session, go }) {
  const [state, setState] = useState({ loading: true })
  const [tab, setTab] = useState('summary')
  const [modal, setModal] = useState(null)
  // The policy the edit form is open on. Kept beside `modal` rather than
  // inside it so the other modals stay the plain strings they already are.
  const [policy, setPolicy] = useState(null)
  // Likewise the lab order the results form is being filled in against.
  const [labOrder, setLabOrder] = useState(null)
  const [notice, setNotice] = useState(null)

  async function load() {
    setState({ loading: true })
    try {
      // Only the patient itself is required. The rest are side panels: a role
      // that cannot see policies or documents is not an error, it just gets a
      // chart without those tabs, so each one settles to an empty list.
      const [patient, prescriptions, labs, policies, documents, insurers] = await Promise.all([
        api.patient(patientId),
        api.patientPrescriptions(patientId).catch(() => null),
        session.can('lab.view')
          ? api.labOrders({ patient_id: patientId }).catch(() => null) : null,
        session.can('policy.view') ? api.policies(patientId).catch(() => null) : null,
        session.can('document.view') ? api.documents(patientId).catch(() => null) : null,
        session.can('policy.manage') ? api.insurers().catch(() => null) : null,
      ])
      setState({
        loading: false,
        patient: patient.data.patient,
        prescriptions: prescriptions?.data.prescriptions ?? [],
        labs: labs?.data.lab_orders ?? [],
        policies: policies?.data.policies ?? [],
        documents: documents?.data.documents ?? [],
        insurers: insurers?.data.providers ?? [],
      })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [patientId])

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  const p = state.patient

  // Hiding a tab is a courtesy, the same as hiding a nav item — the API
  // refuses the call regardless of what this array says.
  const tabs = ['summary', 'visits', 'prescriptions']
  if (session.can('lab.view')) tabs.push('labs')
  if (session.can('policy.view')) tabs.push('insurance')
  if (session.can('document.view')) tabs.push('documents')

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
        {tabs.map((t) => (
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

      {/* §19 lab reports, as history rather than as one visit's loose end.
          The consultation screen shows an order and a count of results; this
          is where the values themselves live, and where they are entered. */}
      {tab === 'labs' && (
        <Card title="Lab orders" bodyless>
          {state.labs.length === 0 ? (
            <Empty icon="🧪" title="No lab tests ordered"
                   hint="Tests are ordered from an open consultation." />
          ) : (
            <div style={{ padding: 14 }}>
              {state.labs.map((lo) => (
                <div className="card" key={lo.id} style={{ marginBottom: 12 }}>
                  <div className="card-head">
                    <div>
                      <span className="mono strong">{lo.order_no}</span>
                      <span className="hint"> · ordered {dateOf(lo.ordered_at || lo.created_at)}</span>
                      {lo.clinical_notes && <span className="hint"> · {lo.clinical_notes}</span>}
                    </div>
                    <div className="row">
                      {lo.priority !== 'routine' && (
                        <Badge tone={lo.priority === 'stat' ? 'danger' : 'warn'}>
                          {lo.priority}
                        </Badge>
                      )}
                      <Badge tone={lo.status === 'completed' ? 'ok'
                        : lo.status === 'cancelled' ? 'danger' : 'warn'}>
                        {lo.status.replace(/_/g, ' ')}
                      </Badge>
                      {/* Results go in once. After that the order is closed
                          and the API refuses a second set, so the button goes
                          rather than failing when it is pressed. */}
                      {session.can('lab.result') && lo.status !== 'completed'
                        && lo.status !== 'cancelled' && (
                        <button className="btn btn-sm btn-secondary"
                                onClick={() => { setLabOrder(lo); setModal('lab-results') }}>
                          Enter results
                        </button>
                      )}
                    </div>
                  </div>
                  <div className="card-body">
                    {(lo.results || []).length === 0 ? (
                      <p className="hint">Nothing reported against this order yet.</p>
                    ) : (
                      <div className="table-wrap">
                        <table>
                          <thead>
                            <tr>
                              <th>Test</th><th>Result</th><th>Reference</th>
                              <th>Flag</th><th>Reported</th>
                            </tr>
                          </thead>
                          <tbody>
                            {lo.results.map((r) => (
                              <tr key={r.id}>
                                <td className="strong">{r.test_name}</td>
                                <td className="mono">
                                  {r.value ?? '—'}{r.unit ? ` ${r.unit}` : ''}
                                </td>
                                <td className="hint mono">{r.reference_range || '—'}</td>
                                <td>
                                  {/* A flag is the only reason to scan this
                                      table at speed, so normal stays quiet. */}
                                  {!r.flag || r.flag === 'normal' ? (
                                    <span className="hint">normal</span>
                                  ) : (
                                    <Badge tone={r.flag === 'critical' ? 'danger' : 'warn'}>
                                      {r.flag}
                                    </Badge>
                                  )}
                                </td>
                                <td className="hint">{dateOf(r.reported_at || r.created_at)}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                    {lo.results?.some((r) => r.comments) && (
                      <div style={{ marginTop: 10 }}>
                        {lo.results.filter((r) => r.comments).map((r) => (
                          <p className="hint" key={`c-${r.id}`}>
                            <strong>{r.test_name}:</strong> {r.comments}
                          </p>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* §3 insurance profile. Cover has to be on the record before a claim
          can be raised against it, so this is where a policy is first entered
          — Claims only ever reads what is filed here. */}
      {tab === 'insurance' && (
        <Card
          title="Insurance policies"
          action={session.can('policy.manage') && (
            <button className="btn btn-sm btn-secondary" onClick={() => setModal('policy')}>
              Add policy
            </button>
          )}
          bodyless
        >
          {state.policies.length === 0 ? (
            <Empty icon="🛡" title="No policy on file"
                   hint="Record the patient's cover so invoices can be claimed against it." />
          ) : (
            <div style={{ padding: 14 }}>
              {state.policies.map((pol) => (
                <div className="card" key={pol.id} style={{ marginBottom: 12 }}>
                  <div className="card-head">
                    <div>
                      <span className="strong">{pol.provider_name}</span>
                      <span className="hint"> · <span className="mono">{pol.policy_number}</span></span>
                    </div>
                    <div className="row">
                      {Number(pol.is_primary) === 1 && <Badge tone="ok">primary</Badge>}
                      <Badge tone={
                        pol.status === 'active' ? undefined
                          : pol.status === 'expired' ? 'danger' : 'warn'
                      }>
                        {pol.status}
                      </Badge>
                      {session.can('policy.manage') && (
                        <button className="btn btn-sm btn-secondary"
                                onClick={() => { setPolicy(pol); setModal('policy-edit') }}>
                          Edit
                        </button>
                      )}
                    </div>
                  </div>
                  <div className="card-body">
                    <table>
                      <tbody>
                        <tr>
                          <td className="strong" style={{ width: 150 }}>Member ID</td>
                          <td className="mono">{pol.member_id || '—'}</td>
                          <td className="strong" style={{ width: 150 }}>Group no</td>
                          <td className="mono">{pol.group_number || '—'}</td>
                        </tr>
                        <tr>
                          <td className="strong">Policy holder</td>
                          <td>{pol.policy_holder_name || '—'}</td>
                          <td className="strong">Relation</td>
                          <td>{pol.relation_to_patient || '—'}</td>
                        </tr>
                        <tr>
                          <td className="strong">Cover type</td>
                          <td>{pol.coverage_type || '—'}</td>
                          <td className="strong">Annual ceiling</td>
                          <td className="mono">
                            {pol.coverage_amount == null ? '—' : money(pol.coverage_amount)}
                          </td>
                        </tr>
                        <tr>
                          <td className="strong">Used</td>
                          <td className="mono">{money(pol.coverage_used)}</td>
                          <td className="strong">Remaining</td>
                          <td className="mono strong">
                            {pol.coverage_remaining == null ? '—' : money(pol.coverage_remaining)}
                          </td>
                        </tr>
                        <tr>
                          <td className="strong">Patient co-pay</td>
                          <td>{pol.copay_percent == null ? '—' : `${pol.copay_percent}%`}</td>
                          <td className="strong">Deductible</td>
                          <td className="mono">
                            {pol.deductible == null ? '—' : money(pol.deductible)}
                          </td>
                        </tr>
                        <tr>
                          <td className="strong">Valid</td>
                          <td colSpan={3}>
                            {pol.valid_from ? dateOf(pol.valid_from) : '—'}
                            {' → '}
                            {pol.valid_to ? dateOf(pol.valid_to) : 'open ended'}
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* §3, §19: scans filed against the patient — an insurance card, a
          consent form, an outside lab's report. `patient_visible` is the
          switch that puts one in the patient's own app. */}
      {tab === 'documents' && (
        <Card
          title="Documents"
          action={session.can('document.upload') && (
            <button className="btn btn-sm btn-secondary" onClick={() => setModal('upload')}>
              Upload
            </button>
          )}
          bodyless
        >
          {state.documents.length === 0 ? (
            <Empty icon="📄" title="No documents filed"
                   hint="Insurance cards, consent forms and outside reports live here." />
          ) : (
            <div style={{ padding: 12 }}>
              {state.documents.map((d) => (
                <div className="line-item" key={d.id}>
                  <div className="body">
                    <div className="title">{d.title}</div>
                    <div className="sub">
                      {String(d.category).replace(/_/g, ' ')} · {fileSize(d.size_bytes)}
                      {' · '}{dateOf(d.created_at)}
                    </div>
                  </div>
                  {d.visibility === 'patient_visible' && <Badge tone="ok">patient can see</Badge>}
                  <button className="btn btn-sm btn-secondary"
                          onClick={async () => {
                            try { await openPdf(`/documents/${d.id}/download`) }
                            catch (e) { setNotice({ ok: false, message: e.message }) }
                          }}>
                    Open
                  </button>
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

      {/* A policy has to name an insurer, and the list is the clinic's own
          plus the platform-wide ones. With neither there is nothing to point
          at, so say that instead of failing on submit. */}
      {modal === 'policy' && state.insurers.length === 0 && (
        <Modal
          title="No insurer available"
          onClose={() => setModal(null)}
          footer={<button className="btn" onClick={() => setModal(null)}>Close</button>}
        >
          <p>
            A policy is filed against an insurer, and none is on file for this
            clinic yet. Add the insurer first, then record the patient's policy.
          </p>
        </Modal>
      )}

      {modal === 'policy' && state.insurers.length > 0 && (
        <QuickForm
          title="Add insurance policy"
          fields={[
            {
              key: 'insurance_provider_id', label: 'Insurer', type: 'select', required: true,
              default: String(state.insurers[0].id),
              options: state.insurers.map((i) => ({ value: String(i.id), label: i.name })),
            },
            ...policyFields(),
            {
              key: 'is_primary', label: 'Cover', type: 'select', default: '1',
              options: [
                { value: '1', label: 'Primary policy' },
                { value: '0', label: 'Secondary policy' },
              ],
            },
          ]}
          onClose={() => setModal(null)}
          onSubmit={async (body) => {
            await api.createPolicy(patientId, body)
            setModal(null)
            setNotice({ ok: true, message: 'Insurance policy recorded.' })
            load()
          }}
        />
      )}

      {/* The insurer and the primary flag are absent on purpose: swapping
          either one makes it a different policy, and the API will not take
          them here. An emptied box leaves the stored value alone. */}
      {modal === 'policy-edit' && policy && (
        <QuickForm
          title={`Edit ${policy.provider_name} policy`}
          fields={[
            ...policyFields(policy),
            {
              key: 'status', label: 'Status', type: 'select', default: policy.status,
              options: ['active', 'expired', 'suspended'],
            },
          ]}
          onClose={() => setModal(null)}
          onSubmit={async (body) => {
            await api.updatePolicy(policy.id, body)
            setModal(null)
            setNotice({ ok: true, message: 'Policy updated.' })
            load()
          }}
        />
      )}

      {modal === 'lab-results' && labOrder && (
        <LabResultsForm
          order={labOrder}
          onClose={() => setModal(null)}
          onSubmit={async (results) => {
            await api.recordLabResults(labOrder.id, results)
            setModal(null)
            setNotice({ ok: true, message: `Results recorded against ${labOrder.order_no}.` })
            load()
          }}
        />
      )}

      {modal === 'upload' && (
        <UploadForm
          onClose={() => setModal(null)}
          onSubmit={async (form) => {
            await api.uploadDocument(patientId, form)
            setModal(null)
            setNotice({ ok: true, message: 'Document uploaded.' })
            load()
          }}
        />
      )}
    </>
  )
}

/**
 * Report results against one lab order (§19).
 *
 * One order carries several tests — a CBC is a dozen lines — so this is a
 * growing list of rows rather than a fixed form, and they are sent together:
 * the API closes the order on the first set it accepts, so a half-entered
 * panel would leave the rest with nowhere to go.
 *
 * Only the test name is required. A lab reporting "sample haemolysed" against
 * a named test and no value is saying something worth keeping.
 */
function LabResultsForm({ order, onClose, onSubmit }) {
  const blank = { test_name: '', value: '', unit: '', reference_range: '', flag: 'normal', comments: '' }
  const [rows, setRows] = useState([{ ...blank }])
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
      await onSubmit(named.map((r) => Object.fromEntries(
        Object.entries(r).filter(([, v]) => String(v).trim() !== ''),
      )))
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
          one go.
        </p>

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

/**
 * The boxes an insurance policy is entered through, shared by the add and the
 * edit form so the two cannot drift apart. Pass the policy to edit one.
 */
function policyFields(pol = {}) {
  const v = (key) => pol[key] ?? ''

  return [
    { key: 'policy_number', label: 'Policy number', required: true,
      default: v('policy_number'), placeholder: 'POL-4471' },
    { key: 'member_id', label: 'Member ID', default: v('member_id') },
    { key: 'group_number', label: 'Group number', default: v('group_number') },
    { key: 'policy_holder_name', label: 'Policy holder', default: v('policy_holder_name'),
      placeholder: 'Leave blank if the patient' },
    {
      key: 'relation_to_patient', label: 'Relation to patient', type: 'select',
      default: pol.relation_to_patient || 'self',
      options: ['self', 'spouse', 'child', 'parent', 'other'],
    },
    { key: 'coverage_type', label: 'Cover type', default: v('coverage_type'),
      placeholder: 'Outpatient + inpatient' },
    { key: 'coverage_amount', label: 'Annual ceiling', type: 'number', step: 'any',
      default: v('coverage_amount') },
    { key: 'copay_percent', label: "Patient's co-pay %", type: 'number', step: 'any',
      default: v('copay_percent') },
    { key: 'deductible', label: 'Deductible', type: 'number', step: 'any',
      default: v('deductible') },
    { key: 'valid_from', label: 'Valid from', type: 'date', default: v('valid_from') },
    { key: 'valid_to', label: 'Valid to', type: 'date', default: v('valid_to') },
  ]
}

/**
 * Upload one document against the patient.
 *
 * It cannot reuse QuickForm: the bytes go up as multipart, so the payload is a
 * FormData rather than the JSON object every other form here builds.
 *
 * `visibility` defaults to clinic_only. Putting a file in front of the patient
 * is a decision somebody makes, never something that happens because a default
 * was left alone.
 */
function UploadForm({ onClose, onSubmit }) {
  const [file, setFile] = useState(null)
  const [values, setValues] = useState({
    title: '', category: 'insurance', visibility: 'clinic_only',
  })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function submit(e) {
    e.preventDefault()
    if (!file) {
      setError(new Error('Choose a file to upload.'))
      return
    }

    setBusy(true)
    setError(null)
    try {
      const form = new FormData()
      form.append('file', file)
      // The filename stands in for a title nobody bothered to type.
      form.append('title', values.title.trim() || file.name)
      form.append('category', values.category)
      form.append('visibility', values.visibility)
      await onSubmit(form)
    } catch (err) {
      setError(err)
      setBusy(false)
    }
  }

  return (
    <Modal
      title="Upload document"
      onClose={onClose}
      footer={
        <>
          <button className="btn btn-secondary" onClick={onClose}>Cancel</button>
          <button className="btn" form="upload-form" disabled={busy}>
            {busy ? 'Uploading…' : 'Upload'}
          </button>
        </>
      }
    >
      <form id="upload-form" onSubmit={submit}>
        {error && <div className="alert">{error.message}</div>}

        <div className="field">
          <label>File *</label>
          <input type="file" accept=".pdf,image/jpeg,image/png,image/webp"
                 onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          {/* No size is quoted: the ceiling is an env setting the browser is
              never told, and the server names it exactly when one is over. */}
          <div className="hint">PDF, JPG, PNG or WebP.</div>
        </div>

        <div className="field">
          <label>Title</label>
          <input type="text" value={values.title} placeholder={file?.name || 'Insurance card'}
                 onChange={(e) => setValues({ ...values, title: e.target.value })} />
        </div>

        <div className="field">
          <label>Category</label>
          <select value={values.category}
                  onChange={(e) => setValues({ ...values, category: e.target.value })}>
            {['insurance', 'lab_report', 'imaging', 'prescription', 'invoice',
              'discharge', 'consent', 'other'].map((c) => (
                <option key={c} value={c}>{c.replace(/_/g, ' ')}</option>
              ))}
          </select>
        </div>

        <div className="field">
          <label>Who can see it</label>
          <select value={values.visibility}
                  onChange={(e) => setValues({ ...values, visibility: e.target.value })}>
            <option value="clinic_only">Clinic only</option>
            <option value="patient_visible">Clinic and the patient</option>
          </select>
        </div>
      </form>
    </Modal>
  )
}

/** Bytes as the size a person reads off a file listing. */
function fileSize(bytes) {
  const n = Number(bytes || 0)
  if (n < 1024) return `${n} B`
  if (n < 1048576) return `${(n / 1024).toFixed(0)} KB`
  return `${(n / 1048576).toFixed(1)} MB`
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
                {/* A plain string is its own value and label; anything whose
                    label differs from what is sent — an insurer's name against
                    its id — is given as { value, label }. */}
                {f.options.map((o) => {
                  const opt = typeof o === 'string'
                    ? { value: o, label: o.replace(/_/g, ' ') }
                    : o
                  return <option key={opt.value} value={opt.value}>{opt.label}</option>
                })}
              </select>
            ) : (
              <input type={f.type || 'text'} value={values[f.key]} required={f.required}
                     placeholder={f.placeholder} step={f.step}
                     onChange={(e) => setValues({ ...values, [f.key]: e.target.value })} />
            )}
          </div>
        ))}
      </form>
    </Modal>
  )
}
