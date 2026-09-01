/** Small shared presentational pieces. */

export function Card({ title, action, children, bodyless }) {
  return (
    <div className="card">
      {(title || action) && (
        <div className="card-head">
          <h2>{title}</h2>
          {action}
        </div>
      )}
      {bodyless ? children : <div className="card-body">{children}</div>}
    </div>
  )
}

export function Stat({ label, value, hint, money }) {
  return (
    <div className="stat">
      <div className="label">{label}</div>
      <div className={money ? 'value money' : 'value'}>{value}</div>
      {hint && <div className="hint">{hint}</div>}
    </div>
  )
}

/** Maps a status string to a colour without each page repeating the mapping. */
const TONE = {
  active: 'ok',
  succeeded: 'ok',
  paid: 'ok',
  approved: 'ok',
  ok: 'ok',
  invited: 'warn',
  pending: 'warn',
  trialing: 'warn',
  suspended: 'warn',
  overdue: 'warn',
  disabled: 'danger',
  cancelled: 'danger',
  rejected: 'danger',
  failed: 'danger',
}

export function Badge({ children, tone }) {
  const key = String(children ?? '').toLowerCase()
  const resolved = tone || TONE[key] || 'neutral'
  return <span className={`badge badge-${resolved}`}>{children}</span>
}

export function Loading({ label = 'Loading…' }) {
  return (
    <div className="loading">
      <span className="spinner" />
      <div style={{ marginTop: 10 }}>{label}</div>
    </div>
  )
}

export function Empty({ icon = '∅', title, hint }) {
  return (
    <div className="empty">
      <div className="big">{icon}</div>
      <div style={{ fontWeight: 600, color: 'var(--body)' }}>{title}</div>
      {hint && <div style={{ marginTop: 5, fontSize: 13 }}>{hint}</div>}
    </div>
  )
}

export function ErrorBox({ error, onRetry }) {
  if (!error) return null
  return (
    <div className="alert">
      {error.message}
      {onRetry && (
        <button className="btn btn-sm btn-secondary" style={{ marginLeft: 12 }} onClick={onRetry}>
          Retry
        </button>
      )}
    </div>
  )
}

/** UTC from the API -> the viewer's local time, in a compact form. */
export function when(value) {
  if (!value) return '—'
  const iso = String(value).replace(' ', 'T') + (String(value).endsWith('Z') ? '' : 'Z')
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleString(undefined, {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/**
 * Times are rendered in the ORGANIZATION's timezone, never the viewer's.
 *
 * A 10:00 appointment is a time at that clinic. A receptionist in Karachi and
 * a regional manager in Dubai must both see 10:00, or they are looking at two
 * different appointments. §23 makes the timezone a per-organization setting,
 * so it is registered once at sign-in and used by every formatter here.
 *
 * Left undefined (before sign-in) these fall back to the browser default,
 * which is the best guess available at that point.
 */
let clinicTimeZone

export function setClinicTimeZone(tz) {
  clinicTimeZone = tz || undefined
}

/** "14:30", in the clinic's timezone. */
export function timeOf(value) {
  if (!value) return '—'
  const d = toDate(value)
  if (!d) return '—'
  return d.toLocaleTimeString(undefined, {
    hour: '2-digit', minute: '2-digit', hour12: false, timeZone: clinicTimeZone,
  })
}

/** "24 Aug 2026", in the clinic's timezone. */
export function dateOf(value) {
  if (!value) return '—'
  const d = toDate(value)
  if (!d) return '—'
  return d.toLocaleDateString(undefined, {
    day: '2-digit', month: 'short', year: 'numeric', timeZone: clinicTimeZone,
  })
}

/**
 * Today as YYYY-MM-DD in the CLINIC's timezone, for <input type="date">.
 *
 * en-CA formats as YYYY-MM-DD, which is exactly what a date input expects.
 */
export function todayISO() {
  return new Intl.DateTimeFormat('en-CA', {
    year: 'numeric', month: '2-digit', day: '2-digit', timeZone: clinicTimeZone,
  }).format(new Date())
}

/** Initials for the avatar circle. */
export function initials(name) {
  return String(name || '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0].toUpperCase())
    .join('')
}

/**
 * The API sends UTC as "YYYY-MM-DD HH:MM:SS" with no zone marker, which
 * JS would otherwise read as local time. Normalise before parsing.
 */
function toDate(value) {
  const s = String(value)
  const iso = s.includes('T') ? s : s.replace(' ', 'T')
  const d = new Date(iso.endsWith('Z') ? iso : `${iso}Z`)
  return Number.isNaN(d.getTime()) ? null : d
}

/** Simple modal shell. */
export function Modal({ title, onClose, children, footer, wide }) {
  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" style={wide ? { maxWidth: 860 } : undefined}
           onClick={(e) => e.stopPropagation()}>
        <div className="modal-head">
          <h2>{title}</h2>
          <button className="icon-btn" onClick={onClose} aria-label="Close">✕</button>
        </div>
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-foot">{footer}</div>}
      </div>
    </div>
  )
}

/** Red banner listing a patient's active allergies. Renders nothing if none. */
export function AllergyBanner({ allergies }) {
  const active = (allergies || []).filter((a) => Number(a.is_active) === 1)
  if (active.length === 0) return null

  return (
    <div className="allergy-banner">
      <div className="title">⚠ Allergies</div>
      {active.map((a) => (
        <div className="item" key={a.id}>
          <strong>{a.substance}</strong> — {a.severity.replace(/_/g, ' ')}
          {a.reaction ? ` · ${a.reaction}` : ''}
        </div>
      ))}
    </div>
  )
}
