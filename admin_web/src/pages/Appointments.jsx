import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, Empty, ErrorBox, when } from '../components.jsx'

/**
 * Bookings waiting on a yes, and the buttons that answer them.
 *
 * Two people use this page. A clinic owner sees their own clinic through the
 * tenant-scoped /appointments — the same rows the front desk works from. A
 * platform admin (§21) sees every clinic through /platform/appointments and
 * answers on a clinic's behalf; the API routes that write through the
 * clinic's own service, so the patient, the doctor and the owners are told
 * exactly as if the front desk had clicked.
 */
const STATUSES = ['booked', 'confirmed', 'on_hold', 'arrived', 'in_consultation', 'completed', 'cancelled', 'no_show']

export default function Appointments({ session }) {
  // A platform admin is nobody's member: cross-tenant, with a Clinic column.
  const platform = Boolean(session?.user?.is_platform_admin)
  const list_ = platform ? api.platformAppointments : api.appointments
  const answer = platform ? api.setPlatformAppointmentStatus : api.setAppointmentStatus

  const [filters, setFilters] = useState({ status: 'booked', search: '' })
  const [list, setList] = useState({ loading: true })
  const [notice, setNotice] = useState(null)
  const [busy, setBusy] = useState(null)

  async function load(f = filters) {
    setList({ loading: true })
    try {
      const res = await list_({ ...f, per_page: 50 })
      // The tenant list answers { appointments: [...] } and does not search;
      // the platform one pages and does. Filter here so the box works for both.
      let rows = res.data.appointments ?? res.data
      const q = (f.search || '').trim().toLowerCase()
      if (!platform && q) {
        rows = rows.filter((a) =>
          [a.patient_name, a.mrn, a.doctor_name].some((v) => String(v || '').toLowerCase().includes(q)))
      }
      setList({ loading: false, rows, meta: res.meta })
    } catch (error) {
      setList({ loading: false, error })
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function act(row, status) {
    let reason = null
    if (status === 'on_hold') {
      reason = window.prompt('Why is it on hold? The patient will read this.')
      if (!reason || !reason.trim()) return
    }
    if (status === 'cancelled') {
      reason = window.prompt('Reason for cancelling (optional):') ?? ''
    }

    setNotice(null)
    setBusy(row.id)
    try {
      await answer(row.id, status, reason || undefined)
      const said = { confirmed: 'confirmed', on_hold: 'put on hold', cancelled: 'cancelled' }[status]
      setNotice({ ok: true, message: `Appointment #${row.id} for ${row.patient_name} ${said}. The patient has been told.` })
      await load()
    } catch (error) {
      const detail = error.fieldMessages?.length ? error.fieldMessages.join(' · ') : error.message
      setNotice({ ok: false, message: detail })
    } finally {
      setBusy(null)
    }
  }

  const pending = list.rows?.length ?? 0

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Appointments</h1>
          <p>
            {platform
              ? "Every clinic's bookings. Confirm on a clinic's behalf when nobody there has answered."
              : 'Bookings from the patient app are requests until someone here says yes.'}
          </p>
        </div>
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      <Card
        title={
          filters.status === 'booked'
            ? `${pending} waiting for confirmation`
            : `${pending} ${filters.status.replace('_', ' ')}`
        }
        action={
          <form
            className="row"
            onSubmit={(e) => {
              e.preventDefault()
              load(filters)
            }}
          >
            <select
              value={filters.status}
              onChange={(e) => {
                const next = { ...filters, status: e.target.value }
                setFilters(next)
                load(next)
              }}
              style={{ width: 'auto', padding: '6px 10px', fontSize: 13 }}
            >
              {STATUSES.map((s) => (
                <option key={s} value={s}>{s.replace('_', ' ')}</option>
              ))}
            </select>
            <input
              placeholder={platform ? 'Patient, doctor or clinic…' : 'Patient or doctor…'}
              value={filters.search}
              onChange={(e) => setFilters({ ...filters, search: e.target.value })}
              style={{ width: 220, padding: '6px 10px', fontSize: 13 }}
            />
            <button className="btn btn-sm">Search</button>
          </form>
        }
        bodyless
      >
        {list.loading ? (
          <Loading />
        ) : list.error ? (
          <div style={{ padding: 18 }}><ErrorBox error={list.error} onRetry={() => load()} /></div>
        ) : list.rows.length === 0 ? (
          <Empty
            icon="📅"
            title={filters.status === 'booked' ? 'Nothing waiting' : 'No appointments'}
            hint={filters.status === 'booked' ? 'Every booking has been answered.' : undefined}
          />
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  {platform && <th>Clinic</th>}
                  <th>Patient</th>
                  <th>Doctor</th>
                  <th>When</th>
                  <th>Reason</th>
                  <th>Requested</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {list.rows.map((a) => {
                  const canAnswer = a.status === 'booked' || a.status === 'on_hold'
                  const canCancel = !['completed', 'cancelled', 'no_show'].includes(a.status)
                  return (
                    <tr key={a.id}>
                      <td style={{ color: 'var(--muted)' }}>{a.id}</td>
                      {platform && <td>{a.organization_name}</td>}
                      <td>
                        <div>{a.patient_name}</div>
                        <div style={{ fontSize: 12, color: 'var(--muted)' }}>{a.mrn}</div>
                      </td>
                      <td>
                        <div>{a.doctor_name}</div>
                        <div style={{ fontSize: 12, color: 'var(--muted)' }}>{a.specialty}</div>
                      </td>
                      <td>{when(a.scheduled_at)}</td>
                      <td style={{ maxWidth: 220 }}>
                        {a.reason || '—'}
                        {a.hold_reason && (
                          <div style={{ fontSize: 12, color: 'var(--muted)' }}>On hold: {a.hold_reason}</div>
                        )}
                      </td>
                      <td>{when(a.created_at)}</td>
                      <td><Badge>{a.status}</Badge></td>
                      <td style={{ whiteSpace: 'nowrap' }}>
                        {canAnswer && (
                          <button
                            className="btn btn-sm"
                            style={{ marginRight: 6 }}
                            disabled={busy === a.id}
                            onClick={() => act(a, 'confirmed')}
                          >
                            Confirm
                          </button>
                        )}
                        {a.status === 'booked' && (
                          <button
                            className="btn btn-sm btn-secondary"
                            style={{ marginRight: 6 }}
                            disabled={busy === a.id}
                            onClick={() => act(a, 'on_hold')}
                          >
                            Hold
                          </button>
                        )}
                        {canCancel && (
                          <button
                            className="btn btn-sm btn-secondary"
                            disabled={busy === a.id}
                            onClick={() => act(a, 'cancelled')}
                          >
                            Cancel
                          </button>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  )
}
