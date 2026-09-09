import { useEffect, useRef, useState } from 'react'
import { api, openPdf } from '../api.js'
import {
  Card, Badge, Loading, ErrorBox, Modal, AllergyBanner, ConditionBanner,
  dateOf, initials,
} from '../components.jsx'
import { money } from './Billing.jsx'
import { ClinicalNotes, AiBillingSuggestions } from '../ai.jsx'

/**
 * The consultation screen — the §4 workflow in one page:
 *
 *   patient → history → symptoms/vitals → diagnosis → prescription
 *   → lab/procedure → follow-up → complete
 *
 * Design principle from §4: "minimize doctor typing". Medicines come from the
 * catalogue with dosage/frequency/duration pre-filled, so the common case is
 * two clicks rather than four text fields.
 */
export default function Consultation({ encounterId, session, go }) {
  const [state, setState] = useState({ loading: true })
  const [notice, setNotice] = useState(null)
  const [modal, setModal] = useState(null)
  const [warnings, setWarnings] = useState([])

  async function load() {
    try {
      const res = await api.encounter(encounterId)
      setState({ loading: false, e: res.data.encounter })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [encounterId])

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  const e = state.e
  const open = e.status === 'open'
  const rx = e.prescriptions?.[0] ?? null

  async function act(fn, successMessage) {
    setNotice(null)
    try {
      const result = await fn()
      if (successMessage) setNotice({ ok: true, message: successMessage })
      await load()
      return result
    } catch (error) {
      setNotice({ ok: false, message: error.message })
      throw error
    }
  }

  return (
    <>
      <div className="page-head">
        <div>
          <button className="btn btn-sm btn-secondary" onClick={() => go('dashboard')}>
            ← My day
          </button>
        </div>
        <Badge tone={open ? 'warn' : e.status === 'completed' ? 'ok' : 'danger'}>
          {e.status}
        </Badge>
      </div>

      <div className="patient-header">
        <div className="avatar">{initials(e.patient_name)}</div>
        <div>
          <h1>{e.patient_name}</h1>
          <div className="meta">
            <span className="mono">{e.mrn}</span>
            {e.patient_age != null && ` · ${e.patient_age} yrs`}
            {e.gender !== 'unknown' && ` · ${e.gender}`}
            {e.blood_group && ` · ${e.blood_group}`}
            {' · '}<span className="mono">{e.encounter_no}</span>
          </div>
        </div>
        <div className="spacer" />
        <button className="btn btn-sm btn-secondary"
                onClick={() => go('chart', { patientId: e.patient_id })}>
          Full chart
        </button>
      </div>

      <PatientHistory patientId={e.patient_id} />

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      {warnings.length > 0 && (
        <div className="allergy-banner">
          <div className="title">⚠ Prescription warnings</div>
          {warnings.map((w, i) => <div className="item" key={i}>{w}</div>)}
          <button className="btn btn-sm btn-secondary" style={{ marginTop: 8 }}
                  onClick={() => setWarnings([])}>Acknowledge</button>
        </div>
      )}

      {!open && (
        <div className="alert" style={{ background: 'var(--bg)', color: 'var(--body)',
                                        borderColor: 'var(--border)' }}>
          This consultation is {e.status} and is now read-only.
          {e.completed_at && ` Completed ${dateOf(e.completed_at)}.`}
        </div>
      )}

      {/* §27: the completed visit becomes an invoice. */}
      {e.status === 'completed' && session.can('invoice.create') && (
        <BillVisit
          encounter={e}
          session={session}
          onInvoiced={(invoiceId) => go('invoice', { invoiceId })}
          onError={(m) => setNotice({ ok: false, message: m })}
        />
      )}

      {/* ---------- 0. What happened last time ---------- */}
      <PreviousVisits visits={e.previous_visits} go={go} />

      {/* ---------- 1. Complaint, symptoms, vitals ---------- */}
      <div className="step">
        <div className="step-title"><span className="step-num">1</span> Complaint &amp; examination</div>
        <Card>
          <Findings encounter={e} disabled={!open}
                    onSave={(body) => act(() => api.updateEncounter(e.id, body), 'Findings saved.')} />
        </Card>
      </div>

      {/* ---------- 2. Diagnosis ---------- */}
      <div className="step">
        <div className="step-title"><span className="step-num">2</span> Diagnosis</div>
        <Card
          bodyless
          action={open && session.can('diagnosis.manage') && (
            <button className="btn btn-sm btn-secondary" onClick={() => setModal('diagnosis')}>
              Add diagnosis
            </button>
          )}
          title={`${e.diagnoses.length} recorded`}
        >
          <div style={{ padding: e.diagnoses.length ? 12 : 0 }}>
            {e.diagnoses.length === 0 ? (
              <p className="hint" style={{ padding: 16 }}>No diagnosis recorded yet.</p>
            ) : e.diagnoses.map((d) => (
              <div className="line-item" key={d.id}>
                <div className="body">
                  <div className="title">{d.description}</div>
                  <div className="sub">
                    {d.icd10_code && <span className="mono">{d.icd10_code}</span>}
                    {d.notes ? ` · ${d.notes}` : ''}
                  </div>
                </div>
                <Badge tone={d.type === 'primary' ? 'accent' : 'neutral'}>{d.type}</Badge>
                {open && (
                  <button className="icon-btn" title="Remove"
                          onClick={() => act(
                            () => api.removeChild(e.id, 'diagnosis', d.id), 'Diagnosis removed.')}>
                    ✕
                  </button>
                )}
              </div>
            ))}
          </div>
        </Card>
      </div>

      {/* ---------- 3. Prescription ---------- */}
      <div className="step">
        <div className="step-title"><span className="step-num">3</span> Prescription</div>
        <Prescription
          encounter={e}
          prescription={rx}
          open={open}
          canPrescribe={session.can('prescription.create')}
          onChanged={(w) => { setWarnings(w || []); load() }}
          onError={(m) => setNotice({ ok: false, message: m })}
        />
      </div>

      {/* ---------- 4. Procedures & labs ---------- */}
      <div className="step">
        <div className="step-title"><span className="step-num">4</span> Procedures &amp; lab orders</div>
        <div className="grid-2">
          <Card
            title={`Procedures (${e.procedures.length})`}
            bodyless
            action={open && session.can('procedure.manage') && (
              <button className="btn btn-sm btn-secondary" onClick={() => setModal('procedure')}>
                Add
              </button>
            )}
          >
            <div style={{ padding: e.procedures.length ? 12 : 16 }}>
              {e.procedures.length === 0 ? (
                <p className="hint">None recorded.</p>
              ) : e.procedures.map((p) => (
                <div className="line-item" key={p.id}>
                  <div className="body">
                    <div className="title">{p.name}</div>
                    <div className="sub">
                      {p.site ? `Site ${p.site}` : ''}{p.outcome ? ` · ${p.outcome}` : ''}
                    </div>
                  </div>
                  {open && (
                    <button className="icon-btn" title="Remove"
                            onClick={() => act(
                              () => api.removeChild(e.id, 'procedure', p.id), 'Procedure removed.')}>
                      ✕
                    </button>
                  )}
                </div>
              ))}
            </div>
          </Card>

          <Card
            title={`Lab orders (${e.lab_orders.length})`}
            bodyless
            action={open && session.can('lab.create') && (
              <button className="btn btn-sm btn-secondary"
                      onClick={() => act(
                        () => api.orderLab(e.id, { priority: 'routine' }), 'Lab test ordered.')}>
                Order test
              </button>
            )}
          >
            <div style={{ padding: e.lab_orders.length ? 12 : 16 }}>
              {e.lab_orders.length === 0 ? (
                <p className="hint">None ordered.</p>
              ) : e.lab_orders.map((l) => (
                <div className="line-item" key={l.id}>
                  <div className="body">
                    <div className="title mono">{l.order_no}</div>
                    <div className="sub">
                      {l.priority} · {l.result_count} result(s)
                      {l.clinical_notes ? ` · ${l.clinical_notes}` : ''}
                    </div>
                  </div>
                  <Badge>{l.status.replace(/_/g, ' ')}</Badge>
                </div>
              ))}
            </div>
          </Card>
        </div>
      </div>

      {/* ---------- 5. Clinical note (§5, and the §9 documentation assistant) ---------- */}
      <div className="step">
        <div className="step-title"><span className="step-num">5</span> Clinical note</div>
        <ClinicalNotes
          encounter={e}
          open={open}
          session={session}
          onChanged={load}
          onError={(m) => setNotice({ ok: false, message: m })}
        />
      </div>

      {/* ---------- 6. Complete ---------- */}
      {open && (
        <div className="step">
          <div className="step-title"><span className="step-num">6</span> Finish</div>
          <Card>
            <CompleteVisit
              onComplete={(followupOn) =>
                act(() => api.completeEncounter(e.id, { followup_on: followupOn || undefined }),
                    'Consultation completed.')}
              onCancel={(reason) =>
                act(() => api.cancelEncounter(e.id, reason), 'Consultation cancelled.')}
            />
          </Card>
        </div>
      )}

      {modal === 'diagnosis' && (
        <SimpleModal
          title="Add diagnosis"
          picker={{
            label: 'What this clinic diagnoses',
            placeholder: 'Start typing — pulpitis, caries…',
            search: (q) => api.commonDiagnoses(q).then((r) => r.data.diagnoses),
            render: (d) => ({
              title: d.description,
              sub: d.icd10_code || 'no code recorded',
              meta: `${d.times_used}×`,
            }),
            fill: (d) => ({ description: d.description, icd10_code: d.icd10_code || '' }),
          }}
          fields={[
            { key: 'description', label: 'Diagnosis', required: true,
              placeholder: 'Irreversible pulpitis #26' },
            { key: 'icd10_code', label: 'ICD-10 code', placeholder: 'K04.0' },
            { key: 'type', label: 'Type', type: 'select', default: 'primary',
              options: ['primary', 'secondary', 'provisional', 'differential'] },
            { key: 'notes', label: 'Notes', type: 'textarea' },
          ]}
          onClose={() => setModal(null)}
          onSubmit={async (body) => {
            await act(() => api.addDiagnosis(e.id, body), 'Diagnosis added.')
            setModal(null)
          }}
        />
      )}

      {modal === 'procedure' && (
        <SimpleModal
          title="Record procedure"
          // The catalogue the clinic already maintains for billing. Picking
          // from it carries `service_id` through, so the same choice that
          // records the procedure is the one that prices it later.
          picker={{
            label: 'From the service catalogue',
            placeholder: 'Start typing — pulpotomy, scaling…',
            search: (q) => api.services({ category: 'procedure', search: q })
              .then((r) => r.data.services),
            render: (s) => ({
              title: s.name,
              sub: s.code || 'no code',
              meta: s.price ? money(s.price, s.currency_code) : undefined,
            }),
            fill: (s) => ({ name: s.name, cpt_code: s.code || '', service_id: s.id }),
          }}
          fields={[
            { key: 'name', label: 'Procedure', required: true, placeholder: 'Pulpotomy' },
            { key: 'site', label: 'Site', placeholder: '#26' },
            { key: 'cpt_code', label: 'Code', placeholder: 'D3220' },
            { key: 'outcome', label: 'Outcome', type: 'textarea', placeholder: 'Uneventful' },
          ]}
          onClose={() => setModal(null)}
          onSubmit={async (body) => {
            await act(() => api.addProcedure(e.id, body), 'Procedure recorded.')
            setModal(null)
          }}
        />
      )}
    </>
  )
}

/* ------------------------------------------------------------------ */

/**
 * Turns a completed visit into a draft invoice.
 *
 * Anything the API could not price automatically comes back in `skipped` and
 * is shown rather than swallowed — a biller needs to know what still has to be
 * added by hand.
 */
function BillVisit({ encounter, session, onInvoiced, onError }) {
  const encounterId = encounter.id
  const [busy, setBusy] = useState(false)
  const [existing, setExisting] = useState(null)
  const [skipped, setSkipped] = useState([])

  useEffect(() => {
    api.invoices({ per_page: 100 })
      .then((r) => {
        const match = r.data.find((i) => Number(i.encounter_id) === Number(encounterId))
        if (match) setExisting(match)
      })
      .catch(() => {})
  }, [encounterId])

  if (existing) {
    return (
      <div className="alert alert-ok" style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        <span>
          Invoiced as <strong className="mono">{existing.invoice_no}</strong> —{' '}
          {existing.currency_code} {Number(existing.grand_total).toLocaleString()}
          {' · '}{existing.status.replace(/_/g, ' ')}
        </span>
        <button className="btn btn-sm" onClick={() => onInvoiced(existing.id)}>
          Open invoice
        </button>
      </div>
    )
  }

  return (
    <div className="step">
      <div className="step-title"><span className="step-num">7</span> Billing</div>
      <Card>
        <p style={{ margin: '0 0 12px', color: 'var(--body)' }}>
          Build a draft invoice from this visit — the consultation fee, each procedure
          recorded, and each lab ordered.
        </p>

        {skipped.length > 0 && (
          <div className="alert" style={{ background: 'var(--warn-soft)', color: 'var(--warn)',
                                          borderColor: 'rgba(180,106,0,0.2)' }}>
            <strong>Add these by hand:</strong>
            <ul style={{ margin: '6px 0 0 16px', padding: 0 }}>
              {skipped.map((s, i) => <li key={i}>{s}</li>)}
            </ul>
          </div>
        )}

        <button className="btn" disabled={busy}
                onClick={async () => {
                  setBusy(true)
                  try {
                    const res = await api.invoiceFromEncounter(encounterId)
                    if (res.data.skipped?.length) {
                      setSkipped(res.data.skipped)
                    }
                    onInvoiced(res.data.invoice.id)
                  } catch (err) {
                    onError(err.message)
                    setBusy(false)
                  }
                }}>
          {busy ? 'Generating…' : 'Generate invoice'}
        </button>

        {/* §9: the same visit, read by the billing assistant. Suggestions
            only — the invoice is still created by a person pressing a button. */}
        <AiBillingSuggestions
          encounter={encounter}
          session={session}
          onInvoiced={onInvoiced}
          onError={onError}
        />
      </Card>
    </div>
  )
}

/**
 * §4 step one is the patient's medical history, and that is both halves of it.
 *
 * The call already returned the conditions — the chart is fetched whole — and
 * this threw them away, so a doctor about to prescribe saw the allergies and
 * nothing about the diabetes. Finding that out meant opening the full chart,
 * which leaves the consultation.
 */
function PatientHistory({ patientId }) {
  const [history, setHistory] = useState({ allergies: [], conditions: [] })

  useEffect(() => {
    let alive = true
    api.patient(patientId)
      .then((r) => {
        if (!alive) return
        const p = r.data.patient
        setHistory({ allergies: p.allergies || [], conditions: p.conditions || [] })
      })
      .catch(() => {})
    return () => { alive = false }
  }, [patientId])

  return (
    <>
      <AllergyBanner allergies={history.allergies} />
      <ConditionBanner conditions={history.conditions} />
    </>
  )
}

/**
 * The last few completed visits, above the workflow rather than inside it.
 *
 * §4 puts "check previous visits" before diagnosing, and the only way to do
 * that used to be the Full chart button — which leaves the consultation. So it
 * sits here instead, collapsed: present when the doctor wants it, out of the
 * way when they already know the patient.
 *
 * Closed by default on purpose. A doctor mid-consultation is looking for the
 * next empty field, and a wall of history above it is something to scroll past
 * every time.
 */
function PreviousVisits({ visits, go }) {
  const [open, setOpen] = useState(false)
  if (!visits || visits.length === 0) return null

  return (
    <div className="step">
      <div className="step-title">
        <span className="step-num">↺</span> Previous visits
        <button className="btn btn-sm btn-secondary" style={{ marginLeft: 'auto' }}
                onClick={() => setOpen(!open)}>
          {open ? 'Hide' : `Show ${visits.length}`}
        </button>
      </div>

      {open && (
        <Card bodyless>
          {visits.map((v) => (
            <div className="slot-row" key={v.id}>
              <div className="slot-time">{dateOf(v.completed_at || v.created_at)}</div>
              <div className="slot-main">
                <div className="who">
                  {/* The conclusion first. A date and a complaint are what the
                      patient said; the diagnosis is what was decided, and that
                      is what a doctor is scanning this list for. */}
                  {v.diagnoses?.length
                    ? v.diagnoses.map((d) => d.description).join(' · ')
                    : 'No diagnosis recorded'}
                </div>
                <div className="why">
                  {v.chief_complaint || 'No complaint recorded'}
                  {v.doctor_name ? ` · ${v.doctor_name}` : ''}
                </div>
              </div>
              <div className="slot-actions">
                <button className="btn btn-sm btn-secondary"
                        onClick={() => go('consultation', { encounterId: v.id })}>
                  Open
                </button>
              </div>
            </div>
          ))}
        </Card>
      )}
    </div>
  )
}

function Findings({ encounter, disabled, onSave }) {
  const [form, setForm] = useState({
    chief_complaint: encounter.chief_complaint || '',
    symptoms: encounter.symptoms || '',
    examination: encounter.examination || '',
    bp_systolic: encounter.bp_systolic ?? '',
    bp_diastolic: encounter.bp_diastolic ?? '',
    pulse: encounter.pulse ?? '',
    temperature_c: encounter.temperature_c ?? '',
    weight_kg: encounter.weight_kg ?? '',
    height_cm: encounter.height_cm ?? '',
  })
  const [busy, setBusy] = useState(false)
  const set = (k) => (ev) => setForm({ ...form, [k]: ev.target.value })

  async function save(ev) {
    ev.preventDefault()
    setBusy(true)
    try {
      const body = Object.fromEntries(
        Object.entries(form).filter(([, v]) => String(v).trim() !== ''),
      )
      await onSave(body)
    } catch { /* the parent shows the error */ } finally { setBusy(false) }
  }

  return (
    <form onSubmit={save}>
      <div className="field">
        <label>Chief complaint</label>
        <input value={form.chief_complaint} onChange={set('chief_complaint')} disabled={disabled}
               placeholder="What brought the patient in" />
      </div>
      <div className="grid-2">
        <div className="field">
          <label>Symptoms</label>
          <textarea value={form.symptoms} onChange={set('symptoms')} disabled={disabled}
                    placeholder="Onset, duration, character…" />
        </div>
        <div className="field">
          <label>Examination</label>
          <textarea value={form.examination} onChange={set('examination')} disabled={disabled}
                    placeholder="Findings on examination" />
        </div>
      </div>

      <label style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--body)' }}>Vitals</label>
      <div className="vitals-grid mt">
        {[
          ['bp_systolic', 'BP sys'], ['bp_diastolic', 'BP dia'], ['pulse', 'Pulse'],
          ['temperature_c', 'Temp °C'], ['weight_kg', 'Weight kg'], ['height_cm', 'Height cm'],
        ].map(([key, label]) => (
          <div className="field" key={key} style={{ marginBottom: 0 }}>
            <label>{label}</label>
            <input type="number" step="0.1" value={form[key]} onChange={set(key)} disabled={disabled} />
          </div>
        ))}
      </div>

      {!disabled && (
        <button className="btn mt" disabled={busy}>{busy ? 'Saving…' : 'Save findings'}</button>
      )}
    </form>
  )
}

/** Prescription builder with catalogue autocomplete (§4: select, don't type). */
function Prescription({ encounter, prescription, open, canPrescribe, onChanged, onError }) {
  const [items, setItems] = useState(prescription?.items ?? [])
  const [advice, setAdvice] = useState(prescription?.general_advice ?? '')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    setItems(prescription?.items ?? [])
    setAdvice(prescription?.general_advice ?? '')
  }, [prescription?.id, prescription?.items?.length])

  const issued = prescription?.status === 'issued'
  const editable = open && canPrescribe && !issued

  async function save(nextItems = items, nextAdvice = advice) {
    if (nextItems.length === 0) {
      onError('Add at least one medicine.')
      return
    }
    setBusy(true)
    try {
      const body = {
        items: nextItems.map((i) => ({
          medication_id: i.medication_id ?? undefined,
          medication_name: i.medication_name,
          dosage: i.dosage, frequency: i.frequency,
          duration: i.duration, instructions: i.instructions,
        })),
        general_advice: nextAdvice || undefined,
      }
      const res = prescription
        ? await api.updatePrescription(prescription.id, body)
        : await api.createPrescription({ ...body, encounter_id: encounter.id })
      onChanged(res.data.warnings)
    } catch (err) {
      onError(err.message)
    } finally {
      setBusy(false)
    }
  }

  async function issue() {
    setBusy(true)
    try {
      await api.issuePrescription(prescription.id)
      onChanged([])
    } catch (err) {
      onError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card
      title={prescription
        ? `${prescription.prescription_no} · ${prescription.status}`
        : 'No prescription yet'}
      action={prescription && (
        <div className="row">
          {issued && (
            <button className="btn btn-sm btn-secondary"
                    onClick={() => openPdf(`/prescriptions/${prescription.id}/pdf`)
                      .catch((e) => onError(e.message))}>
              Print / PDF
            </button>
          )}
          <Badge tone={issued ? 'ok' : 'warn'}>{prescription.status}</Badge>
        </div>
      )}
    >
      {items.length === 0 && !editable && <p className="hint">Nothing prescribed.</p>}

      {items.map((item, index) => (
        <div className="line-item" key={index}>
          <div className="body">
            <div className="title">{item.medication_name}</div>
            <div className="sub">
              {[item.dosage, item.frequency, item.duration].filter(Boolean).join(' · ') || '—'}
              {item.instructions ? ` — ${item.instructions}` : ''}
            </div>
          </div>
          {editable && (
            <button className="icon-btn" title="Remove"
                    onClick={() => setItems(items.filter((_, i) => i !== index))}>✕</button>
          )}
        </div>
      ))}

      {editable && (
        <>
          <MedicinePicker onAdd={(item) => setItems([...items, item])} />

          <div className="field mt">
            <label>General advice</label>
            <input value={advice} onChange={(e) => setAdvice(e.target.value)}
                   placeholder="Soft diet for 3 days, warm salt rinses" />
          </div>

          <div className="row mt">
            <button className="btn" disabled={busy || items.length === 0} onClick={() => save()}>
              {busy ? 'Saving…' : prescription ? 'Update prescription' : 'Save prescription'}
            </button>
            {prescription && !issued && (
              <button className="btn btn-ok" disabled={busy} onClick={issue}>
                Issue to patient
              </button>
            )}
          </div>
          <p className="hint mt">
            Issuing locks the prescription — after that it can only be cancelled and rewritten.
          </p>
        </>
      )}
    </Card>
  )
}

function MedicinePicker({ onAdd }) {
  const [query, setQuery] = useState('')
  const [options, setOptions] = useState([])
  const [draft, setDraft] = useState(null)
  const boxRef = useRef(null)

  useEffect(() => {
    if (!query.trim()) { setOptions([]); return }
    const t = setTimeout(() => {
      api.medications(query).then((r) => setOptions(r.data.medications)).catch(() => setOptions([]))
    }, 200)
    return () => clearTimeout(t)
  }, [query])

  function choose(med) {
    // Pre-fill from the catalogue defaults — this is the §4 "minimise typing"
    // rule in practice: the doctor usually just clicks Add.
    setDraft({
      medication_id: med.id,
      medication_name: med.brand_name ? `${med.name} (${med.brand_name})` : med.name,
      dosage: med.default_dosage || '',
      frequency: med.default_frequency || '',
      duration: med.default_duration || '',
      instructions: '',
    })
    setQuery('')
    setOptions([])
  }

  function addFreeText() {
    if (!query.trim()) return
    setDraft({
      medication_id: null, medication_name: query.trim(),
      dosage: '', frequency: '', duration: '', instructions: '',
    })
    setQuery('')
    setOptions([])
  }

  if (draft) {
    const set = (k) => (e) => setDraft({ ...draft, [k]: e.target.value })
    return (
      <div className="card mt" style={{ background: '#fafbfd' }}>
        <div className="card-body">
          <div className="field">
            <label>Medicine</label>
            <input value={draft.medication_name} onChange={set('medication_name')} />
          </div>
          <div className="grid-3">
            <div className="field">
              <label>Dosage</label>
              <input value={draft.dosage} onChange={set('dosage')} placeholder="1 tablet" />
            </div>
            <div className="field">
              <label>Frequency</label>
              <input value={draft.frequency} onChange={set('frequency')} placeholder="twice a day" />
            </div>
            <div className="field">
              <label>Duration</label>
              <input value={draft.duration} onChange={set('duration')} placeholder="7 days" />
            </div>
          </div>
          <div className="field">
            <label>Instructions</label>
            <input value={draft.instructions} onChange={set('instructions')}
                   placeholder="After meals" />
          </div>
          <div className="row">
            <button className="btn btn-sm" onClick={() => { onAdd(draft); setDraft(null) }}>
              Add to prescription
            </button>
            <button className="btn btn-sm btn-secondary" onClick={() => setDraft(null)}>
              Cancel
            </button>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="field autocomplete mt" ref={boxRef}>
      <label>Add medicine</label>
      <input value={query} onChange={(e) => setQuery(e.target.value)}
             onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addFreeText() } }}
             placeholder="Type to search the catalogue…" />
      {options.length > 0 && (
        <div className="autocomplete-list">
          {options.map((m) => (
            <div className="autocomplete-item" key={m.id} onClick={() => choose(m)}>
              <div className="name">
                {m.name}{m.brand_name ? ` — ${m.brand_name}` : ''} {m.strength}
              </div>
              <div className="detail">
                {[m.default_dosage, m.default_frequency, m.default_duration]
                  .filter(Boolean).join(' · ') || m.form}
              </div>
            </div>
          ))}
        </div>
      )}
      {query.trim() && options.length === 0 && (
        <p className="hint" style={{ marginTop: 5 }}>
          Not in the catalogue —{' '}
          <a href="#" onClick={(e) => { e.preventDefault(); addFreeText() }}>
            add &quot;{query}&quot; as free text
          </a>
        </p>
      )}
    </div>
  )
}

/**
 * How far ahead a follow-up is usually set. §4's design principle is to keep
 * the doctor off the keyboard, and "in two weeks" is a thought — turning it
 * into a date is arithmetic the screen can do.
 */
const FOLLOWUP_IN = [
  ['1 week',   7],
  ['2 weeks',  14],
  ['1 month',  30],
  ['3 months', 90],
  ['6 months', 182],
]

function CompleteVisit({ onComplete, onCancel }) {
  const [followup, setFollowup] = useState('')
  const [busy, setBusy] = useState(false)

  const inDays = (days) => {
    const d = new Date()
    d.setDate(d.getDate() + days)
    return d.toISOString().slice(0, 10)
  }

  return (
    <div>
      <div className="field">
        <label>Follow-up date (optional)</label>
        <div className="row" style={{ flexWrap: 'wrap', gap: 6, marginBottom: 8 }}>
          {FOLLOWUP_IN.map(([label, days]) => {
            const date = inDays(days)
            const picked = followup === date
            return (
              <button
                key={label}
                type="button"
                className={`btn btn-sm ${picked ? '' : 'btn-secondary'}`}
                /* Tapping the chip that is already on clears it, so "actually,
                   no follow-up" does not need a separate control. */
                onClick={() => setFollowup(picked ? '' : date)}
              >
                {label}
              </button>
            )
          })}
          {followup && (
            <button type="button" className="btn btn-sm btn-secondary"
                    onClick={() => setFollowup('')}>
              Clear
            </button>
          )}
        </div>
        <input type="date" value={followup} onChange={(e) => setFollowup(e.target.value)}
               style={{ maxWidth: 220 }} />
      </div>
      <div className="row">
        <button className="btn btn-ok" disabled={busy}
                onClick={async () => { setBusy(true); try { await onComplete(followup) } catch {} finally { setBusy(false) } }}>
          Complete consultation
        </button>
        <button className="btn btn-secondary" disabled={busy}
                onClick={async () => {
                  const reason = window.prompt('Reason for cancelling this consultation?')
                  if (reason === null) return
                  setBusy(true)
                  try { await onCancel(reason) } catch {} finally { setBusy(false) }
                }}>
          Cancel visit
        </button>
      </div>
      <p className="hint mt">
        A visit needs at least a diagnosis, examination or prescription before it can be completed.
      </p>
    </div>
  )
}

/**
 * A form in a modal, optionally with a pick list above it.
 *
 * `picker` is §4's "minimise typing" rule applied to the two places that were
 * still pure typing. Pass { placeholder, search, render, fill } and the doctor
 * searches, clicks, and the boxes below are already filled — free text stays
 * available underneath for the thing that has not been seen before.
 */
function SimpleModal({ title, fields, onClose, onSubmit, picker }) {
  const [values, setValues] = useState(
    Object.fromEntries(fields.map((f) => [f.key, f.default ?? ''])),
  )
  const [busy, setBusy] = useState(false)
  const [query, setQuery] = useState('')
  const [options, setOptions] = useState([])
  const [picked, setPicked] = useState(false)

  // Same 200ms as the medication search: long enough not to fire on every
  // keystroke, short enough that it feels like the list is just there.
  useEffect(() => {
    if (!picker) return undefined
    let alive = true
    const t = setTimeout(() => {
      picker.search(query)
        .then((rows) => { if (alive) setOptions(rows || []) })
        .catch(() => { if (alive) setOptions([]) })
    }, 200)
    return () => { alive = false; clearTimeout(t) }
  }, [query, picker])

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    try {
      await onSubmit(Object.fromEntries(
        Object.entries(values).filter(([, v]) => String(v).trim() !== ''),
      ))
    } catch { setBusy(false) }
  }

  return (
    <Modal
      title={title}
      onClose={onClose}
      footer={
        <>
          <button className="btn btn-secondary" onClick={onClose}>Cancel</button>
          <button className="btn" form="simple-form" disabled={busy}>
            {busy ? 'Saving…' : 'Save'}
          </button>
        </>
      }
    >
      <form id="simple-form" onSubmit={submit}>
        {picker && (
          <div className="field">
            <label>{picker.label}</label>
            <input value={query} placeholder={picker.placeholder}
                   onChange={(ev) => setQuery(ev.target.value)} />

            {options.length > 0 && (
              <div style={{ marginTop: 8, maxHeight: 210, overflowY: 'auto' }}>
                {options.map((row, i) => {
                  const view = picker.render(row)
                  return (
                    <button
                      key={i}
                      type="button"
                      className="line-item"
                      style={{ width: '100%', textAlign: 'left', cursor: 'pointer',
                               font: 'inherit', background: 'var(--surface)' }}
                      onClick={() => {
                        setValues({ ...values, ...picker.fill(row) })
                        setPicked(true)
                        setQuery('')
                        setOptions([])
                      }}
                    >
                      <div className="body">
                        <div className="title">{view.title}</div>
                        {view.sub && <div className="sub">{view.sub}</div>}
                      </div>
                      {view.meta && <span className="hint">{view.meta}</span>}
                    </button>
                  )
                })}
              </div>
            )}

            <p className="hint" style={{ marginTop: 6 }}>
              {picked
                ? 'Filled in below — change anything that is not right.'
                : 'Pick one, or just type it in below.'}
            </p>
          </div>
        )}

        {fields.map((f) => (
          <div className="field" key={f.key}>
            <label>{f.label}{f.required ? ' *' : ''}</label>
            {f.type === 'select' ? (
              <select value={values[f.key]}
                      onChange={(e) => setValues({ ...values, [f.key]: e.target.value })}>
                {f.options.map((o) => <option key={o} value={o}>{o}</option>)}
              </select>
            ) : f.type === 'textarea' ? (
              <textarea value={values[f.key]} placeholder={f.placeholder}
                        onChange={(e) => setValues({ ...values, [f.key]: e.target.value })} />
            ) : (
              <input value={values[f.key]} required={f.required} placeholder={f.placeholder}
                     onChange={(e) => setValues({ ...values, [f.key]: e.target.value })} />
            )}
          </div>
        ))}
      </form>
    </Modal>
  )
}
