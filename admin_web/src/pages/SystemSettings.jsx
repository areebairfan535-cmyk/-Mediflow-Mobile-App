import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, ErrorBox } from '../components.jsx'

/**
 * The deployment's own settings (§21).
 *
 * Not a clinic's settings — those live on the organization and each tenant
 * owns theirs. These are one value for the whole installation: what the
 * platform is called, who to write to, whether clinics may sign themselves up,
 * and which payment gateway is in use.
 *
 * The form is built from what the API returns rather than written out here, so
 * a setting added on the server shows up on this screen with no change to it.
 *
 * Nothing secret is on this page. Choosing PayPal is a setting; the key that
 * proves the PayPal account is yours stays in the environment, and the panel
 * only reports whether it is present.
 */
export default function SystemSettings() {
  const [state, setState] = useState({ loading: true })
  const [draft, setDraft] = useState({})
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState(null)

  async function load() {
    try {
      const res = await api.platformSettings()
      setState({ loading: false, ...res.data })
      setDraft({})
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [])

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  const settings = state.settings || []
  const dirty = Object.keys(draft).length > 0
  const valueOf = (s) => (s.key in draft ? draft[s.key] : s.value)
  const set = (key, value) => setDraft({ ...draft, [key]: value })

  async function save() {
    setBusy(true)
    setNotice(null)
    try {
      const res = await api.savePlatformSettings(draft)
      setState({ loading: false, ...res.data })
      setDraft({})
      setNotice({ ok: true, message: `Saved ${res.data.updated.length} setting(s).` })
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    } finally {
      setBusy(false)
    }
  }

  const pay = state.payment || {}
  const live = pay.mode === 'live'

  return (
    <>
      <h1>System settings</h1>
      <p className="hint">
        One value for this whole installation. A clinic&apos;s own currency, tax
        rate and timezone are its to set, not these.
      </p>

      {notice && (
        <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>
      )}

      <Card title="Settings">
        {settings.map((s) => (
          <div className="field" key={s.key}>
            <label>{s.label}</label>

            {s.type === 'bool' ? (
              <label className="row" style={{ gap: 8, alignItems: 'center' }}>
                <input
                  type="checkbox"
                  checked={['1', 'true', 'yes', 'on'].includes(String(valueOf(s)).toLowerCase())}
                  onChange={(e) => set(s.key, e.target.checked ? 'true' : 'false')}
                />
                <span className="hint">{s.help}</span>
              </label>
            ) : s.type === 'choice' ? (
              <>
                <select value={valueOf(s)} onChange={(e) => set(s.key, e.target.value)}
                        style={{ maxWidth: 280 }}>
                  {s.choices.map((c) => (
                    <option key={c || 'none'} value={c}>{c === '' ? '— none —' : c}</option>
                  ))}
                </select>
                <p className="hint">{s.help}</p>
              </>
            ) : (
              <>
                <input
                  type={s.type === 'email' ? 'email' : 'text'}
                  value={valueOf(s)}
                  onChange={(e) => set(s.key, e.target.value)}
                  placeholder={s.default}
                />
                <p className="hint">{s.help}</p>
              </>
            )}
          </div>
        ))}

        <div className="row" style={{ marginTop: 12 }}>
          <button className="btn" onClick={save} disabled={!dirty || busy}>
            {busy ? 'Saving…' : dirty ? 'Save changes' : 'Nothing to save'}
          </button>
          {dirty && (
            <button className="btn btn-secondary" onClick={() => setDraft({})} disabled={busy}>
              Discard
            </button>
          )}
        </div>
      </Card>

      {/* Read-only on purpose. Everything below is reported, not editable —
          the credentials are in the environment and this page never sees them. */}
      <Card title="Payment gateway">
        <div className="row" style={{ gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
          <Badge tone={pay.configured ? (live ? 'danger' : 'warn') : undefined}>
            {pay.configured ? `${pay.gateway} · ${pay.mode}` : 'not configured'}
          </Badge>
          {live && (
            <span style={{ fontWeight: 700, color: 'var(--danger, #b3261e)' }}>
              LIVE — real cards are charged
            </span>
          )}
        </div>

        {pay.reason && <p className="hint mt">{pay.reason}</p>}

        <p className="hint mt">
          Credentials are read from <code>{pay.credentials_location}</code> and are never
          stored here or shown on this screen. Change the gateway above; change the keys
          on the server.
        </p>
      </Card>
    </>
  )
}
