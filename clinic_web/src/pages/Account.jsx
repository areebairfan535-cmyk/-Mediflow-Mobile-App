import { useEffect, useState } from 'react'
import { api, tokens } from '../api.js'
import { Card, Badge, Loading, ErrorBox, when } from '../components.jsx'

/**
 * The staff account (§11): who you are signed in as, what you may do here, the
 * password, and the devices holding a live session.
 *
 * The permission list is shown in full rather than summarised as a role name.
 * "Receptionist" tells you nothing about why a button is missing; a list you
 * can search does — and when somebody says "I cannot issue invoices", this is
 * the screen that answers it in one look.
 */
export default function Account({ session, onSignedOut }) {
  const [sessions, setSessions] = useState({ loading: true, rows: [] })
  const [form, setForm] = useState({ current: '', next: '', confirm: '' })
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState(null)
  const [filter, setFilter] = useState('')

  async function loadSessions() {
    try {
      const res = await api.sessions()
      setSessions({ loading: false, rows: res.data.sessions ?? [] })
    } catch (error) {
      setSessions({ loading: false, rows: [], error })
    }
  }

  useEffect(() => { loadSessions() }, [])

  useEffect(() => {
    if (!notice) return undefined
    const t = setTimeout(() => setNotice(null), notice.ok ? 3500 : 7000)
    return () => clearTimeout(t)
  }, [notice])

  async function changePassword(e) {
    e.preventDefault()
    if (form.next !== form.confirm) {
      setNotice({ ok: false, message: 'The two new passwords do not match.' })
      return
    }
    setBusy(true)
    try {
      await api.changePassword(form.current, form.next)
      setForm({ current: '', next: '', confirm: '' })
      setNotice({ ok: true, message: 'Password changed. Every other session was signed out.' })
      await loadSessions()
    } catch (error) {
      const detail = error.fieldMessages?.length ? error.fieldMessages.join(' · ') : error.message
      setNotice({ ok: false, message: detail })
    } finally {
      setBusy(false)
    }
  }

  const permissions = (session.permissions || [])
    .filter((p) => p.toLowerCase().includes(filter.toLowerCase()))
    .sort()

  // Grouped by module — the slugs are module.action, and 51 of them in one
  // list is a wall rather than an answer.
  const grouped = permissions.reduce((acc, slug) => {
    const [module] = slug.split('.')
    ;(acc[module] ??= []).push(slug)
    return acc
  }, {})

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Account &amp; security</h1>
          <p>Your sign-in, not your clinic's settings — those live under the organization.</p>
        </div>
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      <div className="grid-2">
        <Card title="Signed in as">
          <table>
            <tbody>
              <tr>
                <td className="strong" style={{ width: 130 }}>Name</td>
                <td>{session.user.name}</td>
              </tr>
              <tr><td className="strong">Email</td><td className="mono">{session.user.email}</td></tr>
              <tr>
                <td className="strong">Role</td>
                <td><Badge tone="accent">{session.role || '—'}</Badge></td>
              </tr>
              <tr>
                <td className="strong">Clinic</td>
                <td>{session.organization?.name || '—'}</td>
              </tr>
              <tr>
                <td className="strong">Permissions</td>
                <td className="mono">{(session.permissions || []).length}</td>
              </tr>
            </tbody>
          </table>
        </Card>

        <Card title="Change password">
          <form onSubmit={changePassword}>
            <div className="field">
              <label>Current password</label>
              <input type="password" autoComplete="current-password" required
                     value={form.current}
                     onChange={(e) => setForm({ ...form, current: e.target.value })} />
            </div>
            <div className="field">
              <label>New password</label>
              <input type="password" autoComplete="new-password" required minLength={8}
                     value={form.next}
                     onChange={(e) => setForm({ ...form, next: e.target.value })}
                     placeholder="At least 8 characters" />
            </div>
            <div className="field">
              <label>Repeat the new password</label>
              <input type="password" autoComplete="new-password" required
                     value={form.confirm}
                     onChange={(e) => setForm({ ...form, confirm: e.target.value })} />
            </div>

            <button className="btn btn-block" disabled={busy}>
              {busy ? 'Saving…' : 'Change password'}
            </button>

            <p className="hint" style={{ marginTop: 10 }}>
              Every other session is revoked — which is the point if you are
              changing it because a device went missing.
            </p>
          </form>
        </Card>
      </div>

      <div style={{ marginTop: 16 }}>
        <Card
          title="Devices signed in"
          action={
            <button className="btn btn-sm btn-secondary" onClick={loadSessions}>Refresh</button>
          }
          bodyless
        >
          {sessions.loading ? (
            <Loading />
          ) : sessions.error ? (
            <div style={{ padding: 18 }}><ErrorBox error={sessions.error} onRetry={loadSessions} /></div>
          ) : (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr><th>Device</th><th>Address</th><th>Last used</th><th /><th /></tr>
                </thead>
                <tbody>
                  {sessions.rows.map((row) => (
                    <tr key={row.id}>
                      <td className="strong">{row.device_name || 'Unknown device'}</td>
                      <td className="mono">{row.ip_address || '—'}</td>
                      <td>{when(row.last_used_at || row.created_at)}</td>
                      <td>{row.current && <Badge tone="ok">this browser</Badge>}</td>
                      <td>
                        {!row.current && (
                          <button className="btn btn-sm btn-secondary"
                                  onClick={() => api.revokeSession(row.id).then(loadSessions).catch(() => {})}>
                            Sign out
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </div>

      <div style={{ marginTop: 16 }}>
        <Card
          title={`What you may do here (${(session.permissions || []).length})`}
          action={
            <input
              placeholder="Filter…"
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
              style={{ width: 180, padding: '6px 10px', fontSize: 13 }}
            />
          }
        >
          {Object.keys(grouped).length === 0 ? (
            <p className="hint">Nothing matches “{filter}”.</p>
          ) : (
            Object.entries(grouped).map(([module, slugs]) => (
              <div key={module} style={{ marginBottom: 12 }}>
                <div className="hint" style={{ marginBottom: 6, textTransform: 'uppercase' }}>
                  {module}
                </div>
                <div className="row" style={{ flexWrap: 'wrap', gap: 6 }}>
                  {slugs.map((slug) => (
                    <span className="perm-chip" key={slug}>{slug.split('.')[1]}</span>
                  ))}
                </div>
              </div>
            ))
          )}

          <p className="hint" style={{ marginTop: 10 }}>
            Set by your role, not by you. An organization owner changes it.
          </p>
        </Card>
      </div>

      <button
        className="btn btn-secondary"
        style={{ marginTop: 22 }}
        onClick={async () => {
          try { await api.logout() } catch { /* leaving either way */ }
          tokens.clear()
          onSignedOut()
        }}
      >
        Sign out
      </button>
    </>
  )
}
