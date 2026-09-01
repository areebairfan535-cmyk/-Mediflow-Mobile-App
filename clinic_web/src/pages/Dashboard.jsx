import { useEffect, useState } from 'react'
import { api, ApiError } from '../api.js'
import { Card, Stat, Badge, Loading, Empty, ErrorBox, timeOf, todayISO } from '../components.jsx'
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

  async function setStatus(id, status) {
    setBusy(id)
    setNotice(null)
    try {
      await api.setAppointmentStatus(id, status)
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
          <Stat label="Today" value={state.counts.today_total} hint="appointments" />
          <Stat label="Waiting" value={state.counts.waiting}
                hint={state.counts.waiting > 0 ? 'patients arrived' : 'nobody waiting'} />
          <Stat label="Completed" value={state.counts.completed} hint="visits done" />
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

      <Card title={`${list.length} appointment${list.length === 1 ? "" : "s"}`} bodyless>
        {list.length === 0 ? (
          <Empty icon="📅" title="Nothing booked for today"
                 hint="Use the Appointments tab to book one." />
        ) : (
          list.map((a) => (
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
              </div>

              <Badge>{a.status.replace(/_/g, ' ')}</Badge>

              <div className="slot-actions">
                {a.status === 'booked' && (
                  <button className="btn btn-sm btn-secondary" disabled={busy === a.id}
                          onClick={() => setStatus(a.id, 'confirmed')}>Confirm</button>
                )}
                {(a.status === 'booked' || a.status === 'confirmed') && (
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

      {/* §9: only renders when a provider is actually configured. */}
      <div style={{ marginTop: 14 }}>
        <AiStatusLine />
      </div>
    </>
  )
}
