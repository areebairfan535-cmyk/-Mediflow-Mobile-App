import { useEffect, useState } from 'react'
import { api, ApiError } from '../api.js'
import {
  AppointmentType, Card, Stat, Badge, Loading, Empty, ErrorBox,
  timeOf, dateOf, todayISO, minutesSince, lateness,
} from '../components.jsx'
import { AiStatusLine } from '../ai.jsx'
import { money } from './Billing.jsx'

/**
 * Doctor dashboard (§4): today's appointments, who is waiting, what is done,
 * and one click into the consultation.
 *
 * A user without a doctor profile (receptionist, accountant) gets the clinic's
 * whole day instead of a personal list — the API tells us which case we are in
 * by returning 404 from /doctors/dashboard.
 */
export default function Dashboard({ session, go }) {
  const [state, setState] = useState({ loading: true })
  const [busy, setBusy] = useState(null)
  const [notice, setNotice] = useState(null)
  // Which slice of today the list below is showing: all, completed, cancelled.
  const [filter, setFilter] = useState('all')

  async function load() {
    setState({ loading: true })
    try {
      const res = await api.doctorDashboard()
      setState({ loading: false, mode: 'doctor', ...res.data })
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) {
        try {
          const day = await api.appointments({ date: todayISO() })
          setState({ loading: false, mode: 'front_desk', today: day.data.appointments })
        } catch (e) {
          setState({ loading: false, error: e })
        }
        return
      }
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [])

  // A hold keeps the slot and says why; the patient reads the reason
  // before setting out.
  async function hold(a) {
    const reason = window.prompt(`Put ${a.patient_name}'s appointment on hold? Tell them why:`)
    if (reason === null || reason.trim() === '') return
    await setStatus(a.id, 'on_hold', reason.trim())
  }

  // Turning a request down needs a reason: it goes to the patient's phone.
  async function decline(a) {
    const reason = window.prompt(`Decline ${a.patient_name}'s request? Tell them why:`)
    if (reason === null || reason.trim() === '') return
    await setStatus(a.id, 'cancelled', reason.trim())
  }

  async function setStatus(id, status, reason) {
    setBusy(id)
    setNotice(null)
    try {
      await api.setAppointmentStatus(id, status, reason)
      await load()
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    } finally {
      setBusy(null)
    }
  }

  async function startConsultation(appointment) {
    setBusy(appointment.id)
    setNotice(null)
    try {
      // The patient must be marked arrived before the consultation begins;
      // do it here so the doctor does not have to make two clicks.
      if (appointment.status === 'booked' || appointment.status === 'confirmed') {
        await api.setAppointmentStatus(appointment.id, 'arrived')
      }
      const res = await api.startEncounter({ appointment_id: appointment.id })
      go('consultation', { encounterId: res.data.encounter.id })
    } catch (error) {
      setNotice({ ok: false, message: error.message })
      setBusy(null)
    }
  }

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  const isDoctor = state.mode === 'doctor'
  const list = state.today || []

  // Arrived and not yet called through. `in_consultation` is deliberately out:
  // that patient is being seen, not waiting.
  const waiting = list
    .filter((a) => a.status === 'arrived')
    .sort((a, b) => String(a.scheduled_at).localeCompare(String(b.scheduled_at)))

  // §4 asks for completed visits and cancellations as their own things, and a
  // tile counting them is not one. Rather than three near-identical cards, the
  // day list narrows — and the tile that shows the count is what narrows it,
  // because that is where the reader already is when they want the detail.
  const FILTERS = {
    all: () => true,
    completed: (a) => a.status === 'completed',
    cancelled: (a) => a.status === 'cancelled' || a.status === 'no_show',
  }
  const shown = list.filter(FILTERS[filter] ?? FILTERS.all)

  const listTitle = filter === 'completed'
    ? `${shown.length} completed visit${shown.length === 1 ? '' : 's'}`
    : filter === 'cancelled'
      ? `${shown.length} cancelled or missed`
      : `${shown.length} appointment${shown.length === 1 ? '' : 's'}`

  return (
    <>
      <div className="page-head">
        <div>
          <h1>{isDoctor ? 'My day' : "Today's schedule"}</h1>
          <p>{new Date().toLocaleDateString(undefined,
            { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</p>
        </div>
        <button className="btn btn-secondary btn-sm" onClick={load}>Refresh</button>
      </div>

      {notice && <div className="alert">{notice.message}</div>}

      {state.open_encounter && (
        <div className="alert alert-ok" style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <span>
            Consultation <strong>{state.open_encounter.encounter_no}</strong> is still open.
          </span>
          <button className="btn btn-sm"
                  onClick={() => go('consultation', { encounterId: state.open_encounter.id })}>
            Resume
          </button>
        </div>
      )}

      {isDoctor && state.counts && (
        <div className="stat-grid">
          {/* Pressing a tile a second time clears the filter, so the way back
              to the whole day is the way you got out of it. */}
          <Stat label="Today" value={state.counts.today_total} hint="appointments"
                active={filter === 'all'} onClick={() => setFilter('all')} />
          <Stat label="Waiting" value={state.counts.waiting}
                hint={state.counts.waiting > 0 ? 'patients arrived' : 'nobody waiting'} />
          {/* Only while somebody is actually in the room. A permanent "0 in
              progress" tile is a fact nobody needs, and it crowds out the
              counts that change through the morning. */}
          {state.counts.in_progress > 0 && (
            <Stat label="In progress" value={state.counts.in_progress} hint="in the room" />
          )}
          <Stat label="Completed" value={state.counts.completed} hint="visits done"
                active={filter === 'completed'}
                onClick={() => setFilter(filter === 'completed' ? 'all' : 'completed')} />
          {/* §4 asks for cancellations too. Counted together with no-shows,
              because an hour nobody turned up for cost the doctor the same as
              one that was cancelled — the hint says which is which. */}
          <Stat label="Cancelled" value={state.counts.cancelled}
                hint={state.counts.cancelled > 0 ? 'incl. no-shows' : 'none today'}
                active={filter === 'cancelled'}
                onClick={() => setFilter(filter === 'cancelled' ? 'all' : 'cancelled')} />
          <Stat label="This week" value={state.counts.week_total} hint="appointments" />
        </div>
      )}

      {/* §4 asks for revenue and outstanding on the doctor's dashboard. These
          are this doctor's own visits, not the practice ledger — a clinician
          should not have to read the clinic's accounts to see their morning. */}
      {state.money && (
        <div className="stat-grid">
          <Stat label="Billed today" value={money(state.money.billed_today)} money
                hint="from your visits" />
          <Stat label="Collected today" value={money(state.money.collected_today)} money
                hint="paid against them" />
          <Stat label="Outstanding" value={money(state.money.outstanding)} money
                hint={Number(state.money.outstanding) > 0 ? 'still owed' : 'nothing owed'} />
        </div>
      )}

      {/* §4 asks for the waiting patients as a list, and a count is not one.
          "Who is waiting, and how long have they been?" is the question a
          doctor asks between visits; answering it by scanning a full day for
          `arrived` badges is the thing this card exists to stop.

          Ordered by their slot, not by arrival: the person the clinic is
          latest for should be seen first, and an early arrival has not
          overtaken anybody. */}
      {waiting.length > 0 && (
        <Card title={`Waiting now (${waiting.length})`} bodyless>
          {waiting.map((a) => (
            <div className="slot-row" key={`w-${a.id}`}>
              <div className="slot-time">{timeOf(a.scheduled_at)}</div>
              <div className="slot-main">
                <div className="who">
                  {a.patient_name} <span className="hint mono">{a.mrn}</span>
                </div>
                <div className="why">
                  {a.reason || 'No reason given'}
                  {!isDoctor && a.doctor_name ? ` · ${a.doctor_name}` : ''}
                </div>
              </div>

              {/* Late enough to matter gets said in red; the rest is a hint. */}
              <span className={minutesSince(a.scheduled_at) >= 15 ? 'strong' : 'hint'}
                    style={minutesSince(a.scheduled_at) >= 15
                      ? { color: 'var(--danger, #b3261e)' } : undefined}>
                {lateness(a.scheduled_at)}
              </span>

              <AppointmentType type={a.type} />

              <div className="slot-actions">
                {session.can('encounter.create') && (
                  <button className="btn btn-sm" disabled={busy === a.id}
                          onClick={() => startConsultation(a)}>
                    Start consultation
                  </button>
                )}
                <button className="btn btn-sm btn-secondary"
                        onClick={() => go('chart', { patientId: a.patient_id })}>
                  Chart
                </button>
              </div>
            </div>
          ))}
        </Card>
      )}

      <Card
        title={listTitle}
        bodyless
        action={filter !== 'all' && (
          <button className="btn btn-sm btn-secondary" onClick={() => setFilter('all')}>
            Show the whole day
          </button>
        )}
      >
        {shown.length === 0 ? (
          <Empty
            icon="📅"
            title={
              filter === 'completed' ? 'No visits finished yet'
                : filter === 'cancelled' ? 'Nothing cancelled today'
                  : 'Nothing booked for today'
            }
            hint={
              filter !== 'all' ? undefined
                : (state.upcoming || []).length > 0 ? 'Your next bookings are listed below.'
                  : 'Use the Appointments tab to book one.'
            }
          />
        ) : (
          shown.map((a) => (
            <div className="slot-row" key={a.id}>
              <div className="slot-time">{timeOf(a.scheduled_at)}</div>

              <div className="slot-main">
                <div className="who">
                  {a.patient_name}{' '}
                  <span className="hint mono">{a.mrn}</span>
                </div>
                <div className="why">
                  {a.reason || 'No reason given'}
                  {!isDoctor && a.doctor_name ? ` · ${a.doctor_name}` : ''}
                </div>
                {/* Why it was cancelled, on the row it belongs to. The count
                    above says how many; a doctor looking at a gap in their
                    morning wants to know which one and what happened. */}
                {a.status === 'on_hold' && a.hold_reason && (
                  <div className="why" style={{ color: 'var(--danger, #b3261e)' }}>
                    On hold: {a.hold_reason}
                  </div>
                )}
                {['cancelled', 'no_show'].includes(a.status) && (
                  <div className="why" style={{ color: 'var(--danger, #b3261e)' }}>
                    {a.status === 'no_show'
                      ? 'Patient did not arrive'
                      : a.cancelled_reason || 'Cancelled — no reason recorded'}
                  </div>
                )}
              </div>

              <AppointmentType type={a.type} />
              <Badge tone={a.status === 'booked' ? 'warn' : a.status === 'on_hold' ? 'danger' : undefined}>{a.status === 'booked' ? 'awaiting approval' : a.status.replace(/_/g, ' ')}</Badge>

              <div className="slot-actions">
                {/* A booking from the app is a request until the doctor says
                    yes; Approve is that yes, Decline the polite no. */}
                {a.status === 'booked' && (
                  <>
                    <button className="btn btn-sm" disabled={busy === a.id}
                            onClick={() => setStatus(a.id, 'confirmed')}>Approve</button>
                    <button className="btn btn-sm btn-secondary" disabled={busy === a.id}
                            onClick={() => decline(a)}>Decline</button>
                  </>
                )}
                {a.status === 'confirmed' && (
                  <button className="btn btn-sm btn-secondary" disabled={busy === a.id}
                          onClick={() => hold(a)}>Hold</button>
                )}
                {a.status === 'on_hold' && (
                  <button className="btn btn-sm" disabled={busy === a.id}
                          onClick={() => setStatus(a.id, 'confirmed')}>Lift hold</button>
                )}
                {/* Arrived is the front desk's word — the patient gave their name
                    at the counter. A doctor's day skips it: Start consultation
                    marks the patient arrived on its way in. */}
                {!isDoctor && (a.status === 'booked' || a.status === 'confirmed' || a.status === 'on_hold') && (
                  <button className="btn btn-sm btn-secondary" disabled={busy === a.id}
                          onClick={() => setStatus(a.id, 'arrived')}>Arrived</button>
                )}

                {a.encounter_id ? (
                  <button className="btn btn-sm"
                          onClick={() => go('consultation', { encounterId: a.encounter_id })}>
                    {a.encounter_status === 'open' ? 'Resume' : 'View'}
                  </button>
                ) : (
                  session.can('encounter.create')
                  && !['cancelled', 'no_show', 'completed'].includes(a.status) && (
                    <button className="btn btn-sm" disabled={busy === a.id}
                            onClick={() => startConsultation(a)}>
                      Start consultation
                    </button>
                  )
                )}

                <button className="btn btn-sm btn-secondary"
                        onClick={() => go('chart', { patientId: a.patient_id })}>
                  Chart
                </button>
              </div>
            </div>
          ))
        )}
      </Card>

      {/* What is booked after today. A doctor with nothing until Thursday
          should see Thursday here, not an empty day and a hint to go looking.
          Only live bookings — the finished and cancelled ones are history. */}
      {isDoctor && (state.upcoming || []).length > 0 && (
        <div style={{ marginTop: 14 }}>
          <Card title={`Coming up · ${state.upcoming.length} in the next two weeks`} bodyless>
            {state.upcoming.map((a) => (
              <div className="slot-row" key={a.id}>
                <div className="slot-time" style={{ minWidth: 118 }}>
                  {dateOf(a.scheduled_at)}<br />
                  <span className="hint">{timeOf(a.scheduled_at)}</span>
                </div>
                <div className="slot-main">
                  <div className="who">
                    {a.patient_name}{' '}
                    <span className="hint mono">{a.mrn}</span>
                  </div>
                  <div className="why">{a.reason || 'No reason given'}</div>
                </div>
                <AppointmentType type={a.type} />
                <Badge tone={a.status === 'booked' ? 'warn' : a.status === 'on_hold' ? 'danger' : undefined}>{a.status === 'booked' ? 'awaiting approval' : a.status.replace(/_/g, ' ')}</Badge>
                <div className="slot-actions">
                  {a.status === 'booked' && (
                    <>
                      <button className="btn btn-sm" disabled={busy === a.id}
                              onClick={() => setStatus(a.id, 'confirmed')}>Approve</button>
                      <button className="btn btn-sm btn-secondary" disabled={busy === a.id}
                              onClick={() => decline(a)}>Decline</button>
                    </>
                  )}
                  {a.status === 'confirmed' && (
                    <button className="btn btn-sm btn-secondary" disabled={busy === a.id}
                            onClick={() => hold(a)}>Hold</button>
                  )}
                  {a.status === 'on_hold' && (
                    <button className="btn btn-sm" disabled={busy === a.id}
                            onClick={() => setStatus(a.id, 'confirmed')}>Lift hold</button>
                  )}
                  <button className="btn btn-sm btn-secondary"
                          onClick={() => go('chart', { patientId: a.patient_id })}>
                    Chart
                  </button>
                </div>
              </div>
            ))}
          </Card>
        </div>
      )}

      {/* §9: only renders when a provider is actually configured. */}
      <div style={{ marginTop: 14 }}>
        <AiStatusLine />
      </div>
    </>
  )
}
