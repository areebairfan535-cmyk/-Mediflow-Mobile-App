import { useState } from 'react'
import { api } from '../api.js'

/**
 * Forgotten password (§11), admin side.
 *
 * The same two steps as everywhere else — email, then code and new password.
 *
 * The warning on the left is the one that matters for this console: an owner
 * who loses their password loses the only account that can administer the
 * clinic, so the code arriving at a mailbox they still control is the whole
 * safety net.
 */
export default function ForgotPassword({ onDone }) {
  const [step, setStep] = useState(1)
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [show, setShow] = useState(false)

  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [hint, setHint] = useState(null)
  const [done, setDone] = useState(false)

  const mismatch = confirm !== '' && confirm !== password

  async function send(e) {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    setError(null)
    try {
      const res = await api.forgotPassword(email.trim())
      // The server hands the code back outside production, where there is
      // usually no mail server to carry it.
      if (res.data.code) {
        setCode(String(res.data.code))
        setHint(`Development build — the code is ${res.data.code}.`)
      } else {
        setHint(`Check ${email.trim()} for a 6-digit code. It expires in ${res.data.expires_in_minutes} minutes.`)
      }
      setStep(2)
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }

  async function reset(e) {
    e.preventDefault()
    if (busy || mismatch) return
    setBusy(true)
    setError(null)
    try {
      await api.resetPassword(email.trim(), code.trim(), password)
      setDone(true)
    } catch (err) {
      setError(err)
    } finally {
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

        <h1 className="auth-headline">Locked out</h1>
        <p className="auth-lede">
          A six-digit code goes to your email. It works once, expires in half an
          hour, and signs every device out when you use it.
        </p>

        <ul className="auth-points">
          <li><b>Owners</b> — this is the way back in; nobody else can reset it for you</li>
          <li><b>Staff</b> — your owner can reset yours from the team screen, which is quicker</li>
          <li><b>Nothing is revealed</b> — the reply is the same for an address we do not know</li>
        </ul>

        <p className="auth-foot">
          Nothing here touches a medical record — only who may reach one.
        </p>
      </aside>

      <main className="auth-main">
        {done ? (
          <div className="auth-card">
            <h2>Password changed</h2>
            <p className="hint" style={{ marginBottom: 16 }}>
              Every session has been signed out. Log in with the new password.
            </p>
            <button className="btn btn-block" onClick={onDone}>Log in</button>
          </div>
        ) : (
          <form className="auth-card" onSubmit={step === 1 ? send : reset}>
            <h2>Forgotten password</h2>
            <p className="hint" style={{ marginBottom: 16 }}>
              {step === 1
                ? 'We will email you a code.'
                : 'Type the code, then choose a new password.'}
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

            {hint && step === 2 && <div className="notice">{hint}</div>}

            <div className="field">
              <label>Email</label>
              <input
                type="email"
                autoFocus
                required
                readOnly={step === 2}
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="you@clinic.com"
              />
            </div>

            {step === 1 ? (
              <button className="btn btn-block" disabled={busy || !email}>
                {busy ? 'Sending…' : 'Send code'}
              </button>
            ) : (
              <>
                <div className="field">
                  <label>Code</label>
                  <input
                    required
                    inputMode="numeric"
                    maxLength={6}
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    placeholder="000000"
                    style={{ letterSpacing: '0.4em', fontWeight: 700 }}
                  />
                </div>

                <div className="field">
                  <label>New password</label>
                  <div style={{ position: 'relative' }}>
                    <input
                      type={show ? 'text' : 'password'}
                      required
                      minLength={8}
                      autoComplete="new-password"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      placeholder="At least 8 characters"
                      style={{ paddingRight: 62 }}
                    />
                    <button type="button" className="reveal-btn" onClick={() => setShow(!show)}>
                      {show ? 'Hide' : 'Show'}
                    </button>
                  </div>
                </div>

                <div className="field">
                  <label>Confirm password</label>
                  <input
                    type={show ? 'text' : 'password'}
                    required
                    autoComplete="new-password"
                    value={confirm}
                    onChange={(e) => setConfirm(e.target.value)}
                    placeholder="Type it again"
                  />
                  {mismatch && (
                    <p className="hint" style={{ color: 'var(--danger, #c1362f)' }}>
                      The two passwords are not the same.
                    </p>
                  )}
                </div>

                <button className="btn btn-block" disabled={busy || mismatch || !code}>
                  {busy ? 'Changing…' : 'Change password'}
                </button>

                {/* The commonest reason to be stuck on this step is a code that
                    never arrived. */}
                <button
                  type="button"
                  className="btn btn-secondary btn-block"
                  style={{ marginTop: 8 }}
                  onClick={() => { setStep(1); setCode(''); setHint(null); setError(null) }}
                >
                  Send a new code
                </button>
              </>
            )}

            <p className="hint" style={{ marginTop: 16, textAlign: 'center' }}>
              <button type="button" className="link-btn" onClick={onDone}>Back to log in</button>
            </p>
          </form>
        )}
      </main>
    </div>
  )
}
