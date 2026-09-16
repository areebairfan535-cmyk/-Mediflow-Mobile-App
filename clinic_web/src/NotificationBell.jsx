import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from './api.js'
import { when } from './components.jsx'

/**
 * The bell in the topbar: what happened to this person's appointments while
 * they were looking somewhere else.
 *
 * A patient booking from their phone landed on the doctor's day with no
 * announcement — the doctor found it only by opening the right date. The
 * inbox already existed on the server; this is the door to it. It polls
 * rather than pushes: once a minute is plenty for "someone booked Thursday",
 * and it needs no socket to keep alive.
 *
 * Opening the panel does not mark anything read — a glance is not the same
 * as dealing with it. A row is read when it is clicked, and clicking an
 * appointment row also goes to that day.
 */
const POLL_MS = 60_000

const ICONS = {
  'appointment.booked.doctor':      '📅',
  'appointment.rescheduled.doctor': '🔁',
  'appointment.cancelled.doctor':   '✖',
  'insurance.submitted':            '🛡',
}

export function NotificationBell({ go }) {
  const [open, setOpen] = useState(false)
  const [rows, setRows] = useState([])
  const [unread, setUnread] = useState(0)
  const panel = useRef(null)

  const load = useCallback(async () => {
    try {
      const res = await api.notifications()
      setRows(res.data.notifications || [])
      setUnread(res.data.unread || 0)
    } catch {
      // A missed poll is nothing; the next one is a minute away.
    }
  }, [])

  useEffect(() => {
    load()
    const timer = setInterval(load, POLL_MS)
    return () => clearInterval(timer)
  }, [load])

  // Click anywhere else closes it — the panel is a glance, not a page.
  useEffect(() => {
    if (!open) return undefined
    function away(e) { if (panel.current && !panel.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', away)
    return () => document.removeEventListener('mousedown', away)
  }, [open])

  async function openRow(row) {
    if (!row.read_at) {
      setRows((rs) => rs.map((r) => (r.id === row.id ? { ...r, read_at: new Date().toISOString() } : r)))
      setUnread((n) => Math.max(0, n - 1))
      try { await api.markNotificationRead(row.id) } catch { /* the next poll corrects it */ }
    }
    if (go && row.subject_type === 'appointment') {
      setOpen(false)
      go('appointments')
    } else if (go && row.subject_type === 'insurance_policy') {
      setOpen(false)
      go('patients')
    }
  }

  async function readAll() {
    setRows((rs) => rs.map((r) => (r.read_at ? r : { ...r, read_at: new Date().toISOString() })))
    setUnread(0)
    try { await api.markAllNotificationsRead() } catch { /* the next poll corrects it */ }
  }

  return (
    <div className="bell-wrap" ref={panel}>
      <button
        className={`bell${unread > 0 ? ' has-unread' : ''}`}
        onClick={() => { setOpen((o) => !o); if (!open) load() }}
        title="Notifications"
        aria-label={unread > 0 ? `${unread} unread notifications` : 'Notifications'}
      >
        <span aria-hidden="true">🔔</span>
        {unread > 0 && <span className="bell-count">{unread > 99 ? '99+' : unread}</span>}
      </button>

      {open && (
        <div className="bell-panel">
          <div className="bell-head">
            <strong>Notifications</strong>
            {unread > 0 && (
              <button className="btn btn-sm btn-secondary" onClick={readAll}>Mark all read</button>
            )}
          </div>

          {rows.length === 0 ? (
            <div className="bell-empty">Nothing yet. Bookings, moves and cancellations show here.</div>
          ) : (
            <ul className="bell-list">
              {rows.map((row) => (
                <li key={row.id}>
                  <button
                    className={`bell-row${row.read_at ? '' : ' unread'}`}
                    onClick={() => openRow(row)}
                  >
                    <span className="bell-icon" aria-hidden="true">{ICONS[row.event] || '•'}</span>
                    <span className="bell-text">
                      <span className="bell-title">{row.title}</span>
                      <span className="bell-body">{row.body}</span>
                      <span className="bell-when">{when(row.created_at)}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}
