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
