import { useEffect, useState } from 'react'
import { api, tokens } from '../api.js'

/**
 * Starting a clinic (§22's onboarding).
 *
 * The plan calls for: choose plan → organization created → admin account →
 * add doctors/staff → configure services. The first three happen here; the
 * last two are screens the new owner lands on.
 *
 * Two steps, not one long form. Everything on step one is about the person,
 * everything on step two is about the clinic, and a form that mixes them makes
 * people stop and work out which "name" is being asked for.
 *
 * Two calls back the flow: POST /auth/register, then POST /organizations with
 * the chosen plan. The second is what makes the account an owner — a user with
 * no organization can sign in and see nothing, which is a state worth passing
 * through quickly rather than leaving people in.
 */
export default function SignUp({ onSignedIn, onCancel }) {
  const [step, setStep] = useState(1)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [show, setShow] = useState(false)

  const [account, setAccount] = useState({ name: '', email: '', password: '' })
  const [clinic, setClinic] = useState({ name: '', country_code: '', plan: 'free' })

  const [plans, setPlans] = useState([])
  const [countries, setCountries] = useState([])

  useEffect(() => {
    // Both are public — the plan is chosen before the account exists.
    api.publicPlans().then((r) => setPlans(r.data.plans)).catch(() => setPlans([]))
    api.publicCountries()
      .then((r) => {
        setCountries(r.data.countries)
        setClinic((c) => ({ ...c, country_code: c.country_code || r.data.countries[0]?.code || '' }))
      })
      .catch(() => setCountries([]))
  }, [])

  const chosen = plans.find((p) => p.slug === clinic.plan)
  const market = countries.find((c) => c.code === clinic.country_code)

  function nextStep(e) {
    e.preventDefault()
    if (account.password.length < 8) {
      setError({ message: 'Choose a password of at least 8 characters.' })
      return
    }
    setError(null)
    setStep(2)
  }

  async function create(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      // 1. the person
      const registered = await api.register(account)
      tokens.set(registered.data.auth)

      // 2. the clinic, on the plan they picked
      const created = await api.createOrganization({
        name: clinic.name.trim(),
        country_code: clinic.country_code,
        plan: clinic.plan,
      })
      tokens.setOrg(created.data.organization.id)

      onSignedIn()
    } catch (err) {
      setError(err)
      setBusy(false)
      // A duplicate email fails at step one; send them back to fix it rather
      // than leaving the message stranded under a form about the clinic.
      if (err.status === 409 || err.fields?.email) setStep(1)
    }
  }

  return (
    <div className="auth-shell">
      <aside className="auth-aside">
        <div className="brand">
          <div className="brand-mark">M</div>
          <div className="brand-name">MediFlow</div>
        </div>

        <h1 className="auth-headline">Start a clinic</h1>
        <p className="auth-lede">
          Two minutes to an account, a clinic and a plan. Add your doctors, set
          your prices, and you are seeing patients.
        </p>

        <ul className="auth-points">
          <li><b>Your own tenant</b> — nobody else can reach your records</li>
          <li><b>Any market</b> — currency, timezone and tax follow the country</li>
          <li><b>Free to start</b> — change plan whenever the clinic outgrows it</li>
        </ul>

        <p className="auth-foot">
          Already have an account? Sign in instead — your clinic's owner adds
          staff from inside.
        </p>
      </aside>

      <main className="auth-main">
        <form className="auth-card" onSubmit={step === 1 ? nextStep : create}>
          <div className="signup-steps">
            <span className={step === 1 ? 'on' : 'done'}>1 · You</span>
            <span className={step === 2 ? 'on' : ''}>2 · Your clinic</span>
          </div>

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

          {step === 1 ? (
            <>
              <div className="field">
                <label>Your name</label>
                <input autoFocus required value={account.name}
                       onChange={(e) => setAccount({ ...account, name: e.target.value })}
                       placeholder="Dr. Ayesha Khan" />
              </div>

              <div className="field">
                <label>Email</label>
                <input type="email" required autoComplete="username" value={account.email}
                       onChange={(e) => setAccount({ ...account, email: e.target.value })}
                       placeholder="you@clinic.com" />
                <p className="hint">You will sign in with this.</p>
              </div>

              <div className="field">
                <label>Password</label>
                <div style={{ position: 'relative' }}>
                  <input type={show ? 'text' : 'password'} required minLength={8}
                         autoComplete="new-password" value={account.password}
                         onChange={(e) => setAccount({ ...account, password: e.target.value })}
                         placeholder="At least 8 characters"
                         style={{ paddingRight: 62 }} />
                  <button type="button" className="reveal-btn" onClick={() => setShow(!show)}>
                    {show ? 'Hide' : 'Show'}
                  </button>
                </div>
              </div>

              <button className="btn btn-block">Continue</button>
            </>
          ) : (
            <>
              <div className="field">
                <label>Clinic name</label>
                <input autoFocus required value={clinic.name}
                       onChange={(e) => setClinic({ ...clinic, name: e.target.value })}
                       placeholder="Sunrise Dental" />
              </div>

              <div className="field">
                <label>Country</label>
                <select value={clinic.country_code}
                        onChange={(e) => setClinic({ ...clinic, country_code: e.target.value })}>
                  {countries.map((c) => (
                    <option key={c.code} value={c.code}>{c.name}</option>
                  ))}
                </select>
                {market && (
                  <p className="hint">
                    Bills in {market.currency_code}, on {market.timezone} time. Tax and
                    invoice format follow the country — you can change them later.
                  </p>
                )}
              </div>

              <div className="field">
                <label>Plan</label>
                <div className="plan-picker">
                  {plans.map((p) => (
                    <button
                      type="button"
                      key={p.slug}
                      className={`plan-option${clinic.plan === p.slug ? ' on' : ''}`}
                      onClick={() => setClinic({ ...clinic, plan: p.slug })}
                    >
                      <strong>{p.name}</strong>
                      <span>
                        {Number(p.price_monthly) === 0
                          ? 'Free'
                          : `${p.currency_code} ${Number(p.price_monthly).toLocaleString()}/mo`}
                      </span>
                    </button>
                  ))}
                </div>

                {chosen && (
                  <p className="hint" style={{ marginTop: 8 }}>
                    {cap(chosen.max_doctors, 'doctor')} · {cap(chosen.max_patients, 'patient')} ·
                    {' '}{cap(chosen.max_invoices_month, 'invoice')}/month
                    {Number(chosen.max_ai_calls_month) > 0
                      ? ` · ${Number(chosen.max_ai_calls_month).toLocaleString()} AI calls/month`
                      : ''}
                  </p>
                )}
              </div>

              <button className="btn btn-block" disabled={busy}>
                {busy ? 'Creating…' : 'Create clinic'}
              </button>

              <button type="button" className="btn btn-secondary btn-block"
                      style={{ marginTop: 8 }} onClick={() => setStep(1)}>
                Back
              </button>
            </>
          )}

          <p className="hint" style={{ marginTop: 16, textAlign: 'center' }}>
            Already have an account?{' '}
            <button type="button" className="link-btn" onClick={onCancel}>Log in</button>
          </p>
        </form>
      </main>
    </div>
  )
}

/** "Unlimited doctors" reads better than "null doctors". */
function cap(value, noun) {
  if (value == null) return `Unlimited ${noun}s`
  const n = Number(value)
  return `${n.toLocaleString()} ${noun}${n === 1 ? '' : 's'}`
}
