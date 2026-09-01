import { useEffect, useState } from 'react'
import { api, tokens } from '../api.js'
import { Card, Badge, Loading, Empty, ErrorBox, when } from '../components.jsx'

/**
 * The administrator's own account (§11).
 *
 * The session list used to be a page of its own. It belongs here: "who is
 * signed in as me, and on what" is the same question as "what is my password",
 * and both are about the account rather than about the clinic.
 *
 * Each row is a live refresh token. Revoking one kills it and every access
 * token minted from it — which is what makes this worth showing rather than
 * hiding behind a support request.
 */
export default function Account({ session, onSignedOut }) {
  const [state, setState] = useState({ loading: true })
  const [form, setForm] = useState({ current: '', next: '', confirm: '' })
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState(null)

  async function load() {
    setState({ loading: true })
    try {
      const res = await api.sessions()
      setState({ loading: false, sessions: res.data.sessions })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [])

  useEffect(() => {
    if (!notice) return undefined
    const t = setTimeout(() => setNotice(null), notice.ok ? 3500 : 7000)
    return () => clearTimeout(t)
  }, [notice])

  async function revoke(id) {
    setNotice(null)
    try {
      await api.revokeSession(id)
      setNotice({ ok: true, message: 'Session revoked.' })
      await load()
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    }
  }

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
      await load()
    } catch (error) {
      const detail = error.fieldMessages?.length ? error.fieldMessages.join(' · ') : error.message
      setNotice({ ok: false, message: detail })
    } finally {
      setBusy(false)
    }
  }

  const isPlatform = Boolean(session.user.is_platform_admin)

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Account &amp; security</h1>
          <p>Your sign-in and your devices — not the clinic's settings.</p>
        </div>
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      <div className="grid-2">
        <Card title="Signed in as">
          <table>
            <tbody>
              <tr>
                <td className="strong" style={{ width: 140 }}>Name</td>
                <td>{session.user.name}</td>
              </tr>
              <tr><td className="strong">Email</td><td className="mono">{session.user.email}</td></tr>
              <tr>
                <td className="strong">Access</td>
                <td>
                  {isPlatform
                    ? <Badge tone="accent">platform administrator</Badge>
                    : <Badge>{session.role || 'no role'}</Badge>}
                </td>
              </tr>
              <tr>
                <td className="strong">Organization</td>
                <td>{session.organization?.name || (isPlatform ? 'All tenants' : '—')}</td>
              </tr>
              <tr>
                <td className="strong">Memberships</td>
                <td className="mono">{(session.organizations || []).length}</td>
              </tr>
            </tbody>
          </table>

          {isPlatform && (
            <p className="hint" style={{ marginTop: 12 }}>
              This account can read every clinic on the deployment. Everything it
              does there is written to the platform audit trail.
            </p>
          )}
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
              Every other session is revoked, which is the point when a device
              has gone missing.
            </p>
          </form>
        </Card>
      </div>

      <div style={{ marginTop: 16 }}>
        <Card
          title={state.loading ? 'Devices' : `${state.sessions.length} signed-in device(s)`}
          action={<button className="btn btn-sm btn-secondary" onClick={load}>Refresh</button>}
          bodyless
        >
          {state.loading ? (
            <Loading />
          ) : state.error ? (
            <div style={{ padding: 18 }}><ErrorBox error={state.error} onRetry={load} /></div>
          ) : state.sessions.length === 0 ? (
            <Empty icon="🔐" title="No active sessions" />
          ) : (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Device</th><th>IP</th><th>Signed in</th>
                    <th>Last used</th><th>Expires</th><th />
                  </tr>
                </thead>
                <tbody>
                  {state.sessions.map((s) => (
                    <tr key={s.id}>
                      <td className="strong">
                        {s.device_name || 'Unknown device'}{' '}
                        {s.is_current && <Badge tone="ok">this device</Badge>}
                      </td>
                      <td className="mono">{s.ip_address || '—'}</td>
                      <td>{when(s.created_at)}</td>
                      <td>{when(s.last_used_at)}</td>
                      <td>{when(s.expires_at)}</td>
                      <td>
                        <button
                          className="btn btn-sm btn-secondary"
                          onClick={() => revoke(s.id)}
                          disabled={s.is_current}
                          title={s.is_current ? 'Use Sign out instead' : 'Revoke this session'}
                        >
                          Revoke
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
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
