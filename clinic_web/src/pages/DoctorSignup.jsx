import { useState } from 'react'
import { api } from '../api.js'

/**
 * A doctor applies to join the clinic (§2).
 *
 * Name, phone, email, education, experience — the facts an owner reads
 * before saying yes. The account is made at once; the membership waits as
 * pending, and the owner approves it from the Team page. Until then the
 * login works and shows "waiting", which is more honest than "no access".
 *
 * The clinic is fixed by this build (CLINIC_SLUG), the same way the patient
 * app is built for one clinic: a doctor arriving at this address is applying
 * to this clinic.
 */
const CLINIC_SLUG = (import.meta.env.VITE_CLINIC || 'demo-clinic').trim()

const SPECIALTIES = [
  'General Dentist', 'Orthodontist', 'Endodontist', 'Periodontist',
  'Oral Surgeon', 'Paediatric Dentist', 'General Physician', 'Other',
]

export default function DoctorSignup({ onDone, onBack }) {
  const [form, setForm] = useState({
    name: '', email: '', phone: '', password: '', confirm: '',
    specialty: 'General Dentist', qualification: '', experience_years: '', license_no: '',
  })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [sent, setSent] = useState(false)

  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })
  const mismatch = form.confirm !== '' && form.confirm !== form.password
  const ready =
    form.name.trim().length >= 2 && form.email.trim() && form.phone.trim() &&
    form.password.length >= 8 && form.confirm === form.password &&
    form.qualification.trim() && form.experience_years !== ''

  async function submit(e) {
    e.preventDefault()
    if (!ready || busy) return
    setBusy(true)
    setError(null)
    try {
      await api.registerDoctor({
        name: form.name.trim(),
        email: form.email.trim(),
        phone: form.phone.trim(),
        password: form.password,
        clinic: CLINIC_SLUG,
        specialty: form.specialty,
        qualification: form.qualification.trim(),
        experience_years: Number(form.experience_years),
        license_no: form.license_no.trim() || undefined,
      })
      setSent(true)
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
        <h1 className="auth-headline">Join the clinic</h1>
        <p className="auth-lede">
          Tell the clinic who you are and what you practise. The owner reviews
          it and lets you in — usually the same day.
        </p>
        <ul className="auth-points">
          <li><b>Apply</b> — your details, education and experience</li>
          <li><b>Reviewed</b> — the clinic owner sees it at once</li>
          <li><b>Approved</b> — you sign in and your day is waiting</li>
        </ul>
        <p className="auth-foot">
          Already on the team? <button type="button" className="link-btn" onClick={onBack}>Log in</button>
        </p>
      </aside>

      <main className="auth-main">
        {sent ? (
          <div className="auth-card">
            <h2>Application sent</h2>
            <p className="hint" style={{ marginBottom: 16 }}>
              The clinic owner has been told. You will get a notification when
              they approve it, and you can log in now to check where it stands.
            </p>
            <button className="btn btn-block" onClick={onDone}>Go to log in</button>
          </div>
        ) : (
          <form className="auth-card" onSubmit={submit}>
            <h2>Register as a doctor</h2>
            <p className="hint" style={{ marginBottom: 16 }}>
              Your application goes to the clinic owner for approval.
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
              <label>Full name</label>
              <input autoFocus value={form.name} onChange={set('name')} placeholder="Dr. Sana Ahmed" required />
            </div>

            <div className="grid-2" style={{ gap: 12 }}>
              <div className="field">
                <label>Email</label>
                <input type="email" autoComplete="username" value={form.email} onChange={set('email')}
                       placeholder="you@clinic.com" required />
              </div>
              <div className="field">
                <label>Phone</label>
                <input type="tel" value={form.phone} onChange={set('phone')} placeholder="03xx xxxxxxx" required />
              </div>
            </div>

            <div className="grid-2" style={{ gap: 12 }}>
              <div className="field">
                <label>Specialty</label>
                <select value={form.specialty} onChange={set('specialty')}>
                  {SPECIALTIES.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
              </div>
              <div className="field">
                <label>Years of experience</label>
                <input type="number" min="0" max="70" value={form.experience_years}
                       onChange={set('experience_years')} placeholder="4" required />
              </div>
            </div>

            <div className="field">
              <label>Education</label>
              <input value={form.qualification} onChange={set('qualification')}
                     placeholder="BDS, MDS Orthodontics" required />
            </div>

            <div className="field">
              <label>License number (optional)</label>
              <input value={form.license_no} onChange={set('license_no')} placeholder="PMDC-12345" />
            </div>

            <div className="grid-2" style={{ gap: 12 }}>
              <div className="field">
                <label>Password</label>
                <input type="password" autoComplete="new-password" value={form.password}
                       onChange={set('password')} placeholder="At least 8 characters" required />
              </div>
              <div className="field">
                <label>Confirm password</label>
                <input type="password" autoComplete="new-password" value={form.confirm}
                       onChange={set('confirm')} placeholder="Type it again" required
                       style={mismatch ? { borderColor: 'var(--danger)' } : undefined} />
              </div>
            </div>

            <button className="btn btn-block" disabled={!ready || busy}>
              {busy ? 'Sending…' : 'Send application'}
            </button>

            <p className="hint" style={{ marginTop: 14, textAlign: 'center' }}>
              <button type="button" className="link-btn" onClick={onBack}>I already have an account</button>
            </p>
          </form>
        )}
      </main>
    </div>
  )
}
