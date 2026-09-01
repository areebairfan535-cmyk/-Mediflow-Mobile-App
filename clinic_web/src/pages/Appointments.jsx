import { useEffect, useState } from 'react'
import { api } from '../api.js'
import {
  Card, Badge, Loading, Empty, ErrorBox, Modal, timeOf, todayISO,
} from '../components.jsx'

export default function Appointments({ session, go }) {
  const [date, setDate] = useState(todayISO())
  const [doctorId, setDoctorId] = useState('')
  const [doctors, setDoctors] = useState([])
  const [state, setState] = useState({ loading: true })
  const [booking, setBooking] = useState(false)
  const [notice, setNotice] = useState(null)

  async function load() {
    setState((s) => ({ ...s, loading: true }))
    try {
      const res = await api.appointments({ date, doctor_id: doctorId || undefined })
      setState({ loading: false, rows: res.data.appointments })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => {
    api.doctors().then((r) => setDoctors(r.data.doctors)).catch(() => {})
  }, [])

  useEffect(() => { load() }, [date, doctorId])

  async function setStatus(id, status) {
    setNotice(null)
    try {
      await api.setAppointmentStatus(id, status)
      await load()
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    }
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Appointments</h1>
          <p>Booking checks the doctor&apos;s working hours and refuses overlaps.</p>
        </div>
        {session.can('appointment.create') && (
          <button className="btn" onClick={() => setBooking(true)}>Book appointment</button>
        )}
      </div>

      {notice && <div className="alert">{notice.message}</div>}

      <Card>
        <div className="filters">
          <div className="field">
            <label>Date</label>
            <input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          </div>
          <div className="field">
            <label>Doctor</label>
            <select value={doctorId} onChange={(e) => setDoctorId(e.target.value)}>
              <option value="">All doctors</option>
              {doctors.map((d) => (
                <option key={d.id} value={d.id}>{d.name} — {d.specialty}</option>
              ))}
            </select>
          </div>
          <button className="btn btn-secondary" onClick={() => setDate(todayISO())}>Today</button>
        </div>
      </Card>

      <div style={{ marginTop: 16 }}>
        {state.loading ? (
          <Loading />
        ) : state.error ? (
          <ErrorBox error={state.error} onRetry={load} />
        ) : state.rows.length === 0 ? (
          <Card bodyless>
            <Empty icon="📅" title="Nothing booked" hint="Pick another date, or book one." />
          </Card>
        ) : (
          <Card title={`${state.rows.length} appointments`} bodyless>
            {state.rows.map((a) => (
              <div className="slot-row" key={a.id}>
                <div className="slot-time">{timeOf(a.scheduled_at)}</div>
                <div className="slot-main">
                  <div className="who">
                    {a.patient_name} <span className="hint mono">{a.mrn}</span>
                  </div>
                  <div className="why">
                    {a.doctor_name} · {a.duration_minutes} min
                    {a.reason ? ` · ${a.reason}` : ''}
                  </div>
                </div>
                <Badge>{a.status.replace(/_/g, ' ')}</Badge>
                <div className="slot-actions">
                  {a.status === 'booked' && (
                    <button className="btn btn-sm btn-secondary"
                            onClick={() => setStatus(a.id, 'confirmed')}>Confirm</button>
                  )}
                  {(a.status === 'booked' || a.status === 'confirmed') && (
                    <>
                      <button className="btn btn-sm btn-secondary"
                              onClick={() => setStatus(a.id, 'arrived')}>Arrived</button>
                      <button className="btn btn-sm btn-secondary"
                              onClick={() => setStatus(a.id, 'cancelled')}>Cancel</button>
                    </>
                  )}
                  <button className="btn btn-sm btn-secondary"
                          onClick={() => go('chart', { patientId: a.patient_id })}>Chart</button>
                </div>
              </div>
            ))}
          </Card>
        )}
      </div>

      {booking && (
        <BookAppointment
          doctors={doctors}
          onClose={() => setBooking(false)}
          onBooked={(appointment) => {
            setBooking(false)
            setDate(String(appointment.scheduled_at).slice(0, 10))
            setNotice({ ok: true, message: 'Appointment booked.' })
            load()
          }}
        />
      )}
    </>
  )
}

/**
 * Booking flow: pick patient → pick doctor + date → pick from the free slots
 * the API computes. The slot list is why double-booking is hard to do by
 * accident: only genuinely free times are offered.
 */
function BookAppointment({ doctors, onClose, onBooked }) {
  const [search, setSearch] = useState('')
  const [results, setResults] = useState([])
  const [patient, setPatient] = useState(null)
  const [doctorId, setDoctorId] = useState(doctors[0]?.id ?? '')
  const [date, setDate] = useState(todayISO())
  const [slots, setSlots] = useState({ loading: false, list: [] })
  const [slot, setSlot] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    if (!search.trim()) { setResults([]); return }
    const t = setTimeout(() => {
      api.patients({ search, per_page: 8 })
        .then((r) => setResults(r.data))
        .catch(() => setResults([]))
    }, 250)
    return () => clearTimeout(t)
  }, [search])

  useEffect(() => {
    if (!doctorId || !date) return
    setSlot('')
    setSlots({ loading: true, list: [] })
    api.availableSlots(doctorId, date)
      .then((r) => setSlots({ loading: false, list: r.data.slots }))
      .catch(() => setSlots({ loading: false, list: [] }))
  }, [doctorId, date])

  async function submit(e) {
    e.preventDefault()
    if (!patient || !slot) return
    setBusy(true)
    setError(null)
    try {
      const res = await api.book({
        patient_id: patient.id,
        doctor_id: Number(doctorId),
        scheduled_at: slot,
        reason: reason || undefined,
      })
      onBooked(res.data.appointment)
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      title="Book appointment"
      onClose={onClose}
      wide
      footer={
        <>
          <button className="btn btn-secondary" onClick={onClose}>Cancel</button>
          <button className="btn" form="book-form" disabled={busy || !patient || !slot}>
            {busy ? 'Booking…' : 'Book'}
          </button>
        </>
      }
    >
      <form id="book-form" onSubmit={submit}>
        {error && (
          <div className="alert">
            {error.message}
            {error.fieldMessages.length > 0 && (
              <ul style={{ margin: '6px 0 0 16px', padding: 0 }}>
                {error.fieldMessages.map((m, i) => <li key={i}>{m}</li>)}
              </ul>
            )}
          </div>
        )}

        <div className="field autocomplete">
          <label>Patient *</label>
          {patient ? (
            <div className="line-item">
              <div className="body">
                <div className="title">{patient.first_name} {patient.last_name}</div>
                <div className="sub mono">{patient.mrn} · {patient.phone || 'no phone'}</div>
              </div>
              <button type="button" className="btn btn-sm btn-secondary"
                      onClick={() => { setPatient(null); setSearch('') }}>Change</button>
            </div>
          ) : (
            <>
              <input value={search} onChange={(e) => setSearch(e.target.value)}
                     placeholder="Search name, MRN or phone…" />
              {results.length > 0 && (
                <div className="autocomplete-list">
                  {results.map((p) => (
                    <div className="autocomplete-item" key={p.id}
                         onClick={() => { setPatient(p); setResults([]) }}>
                      <div className="name">{p.first_name} {p.last_name}</div>
                      <div className="detail mono">{p.mrn} · {p.phone || 'no phone'}</div>
                    </div>
                  ))}
                </div>
              )}
            </>
          )}
        </div>

        <div className="grid-2">
          <div className="field">
            <label>Doctor *</label>
            <select value={doctorId} onChange={(e) => setDoctorId(e.target.value)}>
              {doctors.map((d) => (
                <option key={d.id} value={d.id}>{d.name} — {d.specialty}</option>
              ))}
            </select>
          </div>
          <div className="field">
            <label>Date *</label>
            <input type="date" value={date} min={todayISO()}
                   onChange={(e) => setDate(e.target.value)} />
          </div>
        </div>

        <div className="field">
          <label>Available slots *</label>
          {slots.loading ? (
            <p className="hint">Loading slots…</p>
          ) : slots.list.length === 0 ? (
            <p className="hint">
              No free slots — the doctor may not work this day, or the day is full.
            </p>
          ) : (
            <div className="slot-picker">
              {slots.list.map((s) => (
                <button key={s.start} type="button"
                        className={`slot-chip${slot === s.start ? ' selected' : ''}`}
                        onClick={() => setSlot(s.start)}>
                  {timeOf(s.start)}
                </button>
              ))}
            </div>
          )}
        </div>

        <div className="field">
          <label>Reason</label>
          <input value={reason} onChange={(e) => setReason(e.target.value)}
                 placeholder="Toothache, follow-up…" />
        </div>
      </form>
    </Modal>
  )
}
