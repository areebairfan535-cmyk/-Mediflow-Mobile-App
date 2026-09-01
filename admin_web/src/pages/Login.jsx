import { useState } from 'react'
import { api, tokens } from '../api.js'

/**
 * The admin console's way in.
 *
 * Two audiences share this door and they are not the same: a clinic owner
 * administering their own practice, and platform staff who can see every
 * tenant on the deployment. The left pane names both, because signing into the
 * wrong console is the commonest way to think a feature is missing.
 *
 * The demo accounts exist only because seed.php creates them; a production
 * build drops the block.
 */
const DEMO = [
  { email: 'admin@mediflow.test', name: 'Platform admin', role: 'Platform',
    sees: 'Every clinic, plans, markets' },
  { email: 'owner@clinic.test', name: 'Dr. Ayesha Khan', role: 'Owner',
    sees: 'This clinic: team, roles, plan, audit' },
]

export default function Login({ onSignedIn, onCreateAccount, onForgot }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [show, setShow] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function submit(e) {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    setError(null)
    try {
      const res = await api.login(email.trim(), password)
      tokens.set(res.data.auth)

      // Pick the tenant to work in. A platform admin may have none, which is
      // fine — the platform routes are not tenant-scoped.
      const orgs = res.data.organizations || []
      tokens.setOrg(res.data.active_org_id ?? orgs[0]?.organization_id ?? null)

      onSignedIn()
    } catch (err) {
      setError(err)
      setBusy(false)
    }
  }

  return (
    <div className="auth-shell">
      <aside className="auth-aside">
        <div className="brand">
          <div className="brand-mark">M</div>
          <div className="brand-name">MediFlow</div>
        </div>

        <h1 className="auth-headline">Admin console</h1>
        <p className="auth-lede">
          Two jobs behind one door: running a clinic, and running the platform
          the clinics sit on.
        </p>

        <ul className="auth-points">
          <li><b>Clinic owners</b> — team, roles, plan and usage, audit trail</li>
          <li><b>Platform staff</b> — every tenant, the price list, the markets</li>
        </ul>

        <p className="auth-foot">
          Clinical work happens in the clinic app; patients use the MediFlow
          app. Nothing here touches a medical record — only who may reach one.
        </p>
      </aside>

      <main className="auth-main">
        <form className="auth-card" onSubmit={submit}>
          <h2>Log in</h2>
          <p className="hint" style={{ marginBottom: 16 }}>
            An owner, an administrator, or platform staff.
          </p>

          {error && (
            <div className="alert">
              {error.message}
              {error.fieldMessages?.length > 0 && (
                <ul style={{ margin: '6px 0 0 16px', padding: 0 }}>
                  {error.fieldMessages.map((m, i) => <li key={i}>{m}</li>)}
                </ul>
              )}
            </div>
          )}

          <div className="field">
            <label>Email</label>
            <input
              type="email"
              autoFocus
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="you@clinic.com"
              required
            />
          </div>

          <div className="field">
            <label>Password</label>
            <div style={{ position: 'relative' }}>
              <input
                type={show ? 'text' : 'password'}
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                required
                style={{ paddingRight: 62 }}
              />
              <button type="button" className="reveal-btn" onClick={() => setShow(!show)}>
                {show ? 'Hide' : 'Show'}
              </button>
            </div>
          </div>

          <button className="btn btn-block" disabled={busy || !email || !password}>
            {busy ? 'Logging in…' : 'Log in'}
          </button>

          {/* §22 starts here: a clinic with no account yet creates one, picks a
              plan and a market, and lands inside as its owner. */}
          <p className="hint" style={{ marginTop: 14, textAlign: 'center' }}>
            <button type="button" className="link-btn" onClick={onForgot}>
              Forgotten your password?
            </button>
          </p>

          <p className="hint" style={{ marginTop: 16, textAlign: 'center' }}>
            New clinic?{' '}
            <button type="button" className="link-btn" onClick={onCreateAccount}>
              Create an account
            </button>
          </p>
        </form>

        <div className="demo-accounts">
          <p>Demo accounts — password <code>Password123</code></p>
          {DEMO.map((account) => (
            <button
              key={account.email}
              type="button"
              className="demo-btn"
              onClick={() => { setEmail(account.email); setPassword('Password123'); setError(null) }}
            >
              <strong>{account.name}</strong>
              <span>{account.role}</span>
              <em>{account.sees}</em>
            </button>
          ))}
        </div>
      </main>
    </div>
  )
}
