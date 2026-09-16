import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Loading, ErrorBox, Badge } from '../components.jsx'

/**
 * When a doctor is in (§3, §4).
 *
 * The weekly windows a patient can book into — Monday 10:00–14:00, and so
 * on — and the switch that says whether the doctor takes bookings at all.
 * The patient app only ever offers slots inside these windows, so what is
 * saved here is exactly what a patient can pick from.
 *
 * A doctor edits their own week. An owner or receptionist picks whose.
 */
const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

// Every half hour from 07:00 to 22:00. A dropdown, not a native time box:
// the browser's time input wants hour, minute and AM/PM typed into three
// invisible cells, and half the people who tried it could not get a value in.
const TIMES = []
for (let h = 7; h <= 22; h++) for (const m of ['00', '30']) TIMES.push(String(h).padStart(2, '0') + ':' + m)
function label12(t) {
  const [h, m] = t.split(':').map(Number)
  return (h % 12 || 12) + ':' + String(m).padStart(2, '0') + (h < 12 ? ' AM' : ' PM')
}
function TimeSelect({ value, onChange, disabled }) {
  const options = TIMES.includes(value) ? TIMES : [value, ...TIMES]
  return (
    <select value={value} disabled={disabled} onChange={(e) => onChange(e.target.value)} style={{ width: 130 }}>
      {options.map((t) => <option key={t} value={t}>{label12(t)}</option>)}
    </select>
  )
}

export default function Availability({ session }) {
  const [doctors, setDoctors] = useState([])
  const [doctorId, setDoctorId] = useState(null)
  const [doctor, setDoctor] = useState(null)
  const [slots, setSlots] = useState([])
  const [state, setState] = useState({ loading: true })
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState(null)

  // Whose week: the signed-in doctor's own, else the first on the list.
  useEffect(() => {
    (async () => {
      try {
        let mine = null
        try { mine = (await api.doctorDashboard()).data.doctor } catch { /* not a doctor */ }
        const list = (await api.doctors()).data.doctors || []
        setDoctors(list)
        setDoctorId(mine?.id ?? list[0]?.id ?? null)
        if (!mine && list.length === 0) setState({ loading: false })
      } catch (error) {
        setState({ loading: false, error })
      }
    })()
  }, [])

  useEffect(() => {
    if (!doctorId) return
    let alive = true
    setState({ loading: true })
    Promise.all([api.doctor(doctorId), api.schedule(doctorId)])
      .then(([d, s]) => {
        if (!alive) return
        setDoctor(d.data.doctor)
        setSlots((s.data.schedule || []).map((row) => ({
          day_of_week: Number(row.day_of_week),
          start_time: String(row.start_time).slice(0, 5),
          end_time: String(row.end_time).slice(0, 5),
        })))
        setState({ loading: false })
      })
      .catch((error) => { if (alive) setState({ loading: false, error }) })
    return () => { alive = false }
  }, [doctorId])

  const canEdit = session.can('schedule.manage')

  function forDay(day) {
    return slots
      .map((s, i) => ({ ...s, i }))
      .filter((s) => s.day_of_week === day)
  }
  function addSlot(day) {
    setSlots([...slots, { day_of_week: day, start_time: '09:00', end_time: '13:00' }])
  }
  function setSlot(i, key, value) {
    setSlots(slots.map((s, j) => (j === i ? { ...s, [key]: value } : s)))
  }
  function removeSlot(i) {
    setSlots(slots.filter((_, j) => j !== i))
  }
  function copyToWeekdays(day) {
    const src = forDay(day).map(({ day_of_week, start_time, end_time }) => ({ day_of_week, start_time, end_time }))
    const kept = slots.filter((s) => s.day_of_week === day || s.day_of_week > 5)
    const copies = [1, 2, 3, 4, 5].filter((d) => d !== day)
      .flatMap((d) => src.map((s) => ({ ...s, day_of_week: d })))
    setSlots([...kept, ...copies])
  }

  async function save() {
    setBusy(true)
    setNotice(null)
    try {
      await api.saveSchedule(doctorId, slots)
      setNotice({ ok: true, message: 'Availability saved. Patients can book into these hours now.' })
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function toggleAccepting() {
    setBusy(true)
    setNotice(null)
    try {
      const next = Number(doctor.is_accepting) === 1 ? 0 : 1
      const res = await api.updateDoctor(doctorId, { is_accepting: Boolean(next) })
      setDoctor(res.data.doctor)
      setNotice({ ok: true, message: next ? 'Taking bookings again.' : 'Not taking new bookings. Existing ones stand.' })
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    } finally {
      setBusy(false)
    }
  }

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} />
  if (!doctorId) return <ErrorBox error={{ message: 'No doctors at this clinic yet.' }} />

  const accepting = Number(doctor?.is_accepting) === 1

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Availability</h1>
          <p>The hours patients can book. The app only offers slots inside these.</p>
        </div>
        {canEdit && (
          <button className="btn" disabled={busy} onClick={save}>
            {busy ? 'Saving…' : 'Save availability'}
          </button>
        )}
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      <div className="row" style={{ marginBottom: 16, alignItems: 'center', gap: 14, flexWrap: 'wrap' }}>
        {doctors.length > 1 && (
          <div className="field" style={{ margin: 0 }}>
            <label>Doctor</label>
            <select value={doctorId} onChange={(e) => setDoctorId(Number(e.target.value))}>
              {doctors.map((d) => <option key={d.id} value={d.id}>{d.name || d.doctor_name} · {d.specialty}</option>)}
            </select>
          </div>
        )}
        {doctor && (
          <div className="row" style={{ gap: 10, alignItems: 'center' }}>
            <Badge tone={accepting ? 'ok' : 'warn'}>{accepting ? 'taking bookings' : 'not taking bookings'}</Badge>
            {canEdit && (
              <button className="btn btn-sm btn-secondary" disabled={busy} onClick={toggleAccepting}>
                {accepting ? 'Pause bookings' : 'Resume bookings'}
              </button>
            )}
            <span className="hint">Slot length {doctor.slot_minutes} min</span>
          </div>
        )}
      </div>

      <Card title="Weekly hours" bodyless>
        {DAYS.map((label, idx) => {
          const day = idx + 1
          const rows = forDay(day)
          return (
            <div className="slot-row" key={day} style={{ alignItems: 'flex-start' }}>
              <div className="slot-time" style={{ minWidth: 110, paddingTop: 6 }}>{label}</div>
              <div className="slot-main">
                {rows.length === 0 ? (
                  <div className="hint" style={{ paddingTop: 6 }}>Off</div>
                ) : rows.map((s) => (
                  <div className="row" key={s.i} style={{ gap: 8, alignItems: 'center', marginBottom: 6 }}>
                    <TimeSelect value={s.start_time} disabled={!canEdit}
                                onChange={(v) => setSlot(s.i, 'start_time', v)} />
                    <span className="hint">to</span>
                    <TimeSelect value={s.end_time} disabled={!canEdit}
                                onChange={(v) => setSlot(s.i, 'end_time', v)} />
                    {canEdit && (
                      <button className="icon-btn" title="Remove" onClick={() => removeSlot(s.i)}>✕</button>
                    )}
                  </div>
                ))}
              </div>
              {canEdit && (
                <div className="slot-actions">
                  <button className="btn btn-sm btn-secondary" onClick={() => addSlot(day)}>Add hours</button>
                  {day <= 5 && rows.length > 0 && (
                    <button className="btn btn-sm btn-secondary" title="Copy these hours to Monday–Friday"
                            onClick={() => copyToWeekdays(day)}>Copy to weekdays</button>
                  )}
                </div>
              )}
            </div>
          )
        })}
      </Card>
    </>
  )
}
