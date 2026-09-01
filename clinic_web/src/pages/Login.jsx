import { useState } from 'react'
import { api, tokens } from '../api.js'

/**
 * The clinic's way in.
 *
 * Two halves: what this workspace is, and the form. The left half exists
 * because staff sign into three MediFlow apps and land in the wrong one
 * otherwise — it says, plainly, that this is the clinical workspace and what
 * lives here.
 *
 * The demo accounts are listed with their ROLES rather than their names,
 * because what someone evaluating this needs to see is that the same screen
 * shows a doctor and a receptionist different things.
 */
const DEMO = [
  { email: 'doctor@clinic.test', name: 'Dr. Bilal Ahmed', role: 'Doctor',
    sees: 'Consultations, prescriptions, charts' },
  { email: 'reception@clinic.test', name: 'Sana Malik', role: 'Receptionist',
    sees: 'Booking, registration, taking payment' },
  { email: 'billing@clinic.test', name: 'Imran Qureshi', role: 'Billing',
    sees: 'Invoices, claims, refunds' },
  { email: 'owner@clinic.test', name: 'Dr. Ayesha Khan', role: 'Owner',
    sees: 'Everything in this clinic' },
]

export default function Login({ onSignedIn, onForgot }) {
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
      const orgs = res.data.organizations || []
      tokens.setOrg(res.data.active_org_id ?? orgs[0]?.organization_id ?? null)
      onSignedIn()
    } catch (err) {
      setError(err)
      setBusy(false)
    }
  }

  function fill(account) {
    setEmail(account.email)
    setPassword('Password123')
    setError(null)
  }

  return (
    <div className="auth-shell">
      {/* ---- what this is ---- */}
      <aside className="auth-aside">
        <div className="brand">
          <div className="brand-mark">M</div>
          <div className="brand-name">MediFlow</div>
        </div>

        <h1 className="auth-headline">The clinical workspace</h1>
        <p className="auth-lede">
          Today's list, the consultation, the prescription, the invoice and the
          payment — the whole visit, in one place.
        </p>

        <ul className="auth-points">
          <li><b>Consult</b> — history, diagnosis, prescription, labs</li>
          <li><b>Bill</b> — services, invoices, payments, refunds</li>
          <li><b>Claim</b> — eligibility, submission, decisions</li>
        </ul>

        <p className="auth-foot">
          Patients use the MediFlow app. Platform administrators use the admin
          console. This is the clinic.
        </p>
      </aside>

      {/* ---- the form ---- */}
      <main className="auth-main">
        <form className="auth-card" onSubmit={submit}>
          <h2>Log in</h2>
          <p className="hint" style={{ marginBottom: 16 }}>
            With the account your clinic gave you.
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
              <button
                type="button"
                onClick={() => setShow(!show)}
                className="reveal-btn"
              >
                {show ? 'Hide' : 'Show'}
              </button>
            </div>
          </div>

          <button className="btn btn-block" disabled={busy || !email || !password}>
            {busy ? 'Logging in…' : 'Log in'}
          </button>

          <p className="hint" style={{ marginTop: 14, textAlign: 'center' }}>
            <button type="button" className="link-btn" onClick={onForgot}>
              Forgotten your password?
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
              onClick={() => fill(account)}
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
