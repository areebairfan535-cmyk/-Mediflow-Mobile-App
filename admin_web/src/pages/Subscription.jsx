import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, ErrorBox } from '../components.jsx'

/**
 * The clinic's plan, what it has used, and how to change it (§22).
 *
 * Usage is shown before the price list on purpose: the question an owner
 * arrives with is "why was my receptionist refused", and the answer is a bar
 * that is full — not a comparison table.
 */
export default function Subscription({ session }) {
  const [state, setState] = useState({ loading: true })
  const [notice, setNotice] = useState(null)
  const [busy, setBusy] = useState(null)

  async function load() {
    try {
      const [sub, plans] = await Promise.all([api.subscription(), api.plans()])
      setState({ loading: false, ...sub.data, plans: plans.data.plans })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [])

  if (state.loading) return <Loading label="Loading plan…" />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  const { subscription, plan, usage, plans } = state
  const canManage = session.can('subscription.manage')

  async function choose(target) {
    setNotice(null)
    setBusy(target.id)
    try {
      await api.changePlan(target.id)
      setNotice({ ok: true, message: `Now on ${target.name}.` })
      await load()
    } catch (error) {
      // A downgrade that does not fit comes back as a field error listing
      // exactly what is in the way. Showing only "validation failed" would
      // throw away the useful half of the answer.
      const detail = error.fieldMessages?.length
        ? error.fieldMessages.join(' · ')
        : error.message
      setNotice({ ok: false, message: detail })
    } finally {
      setBusy(null)
    }
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h1>{plan.name}</h1>
          <p>
            {Number(plan.price_monthly) === 0
              ? 'No charge'
              : `${plan.currency_code} ${Number(plan.price_monthly).toLocaleString()} / month`}
            {' · '}
            billed {subscription.billing_cycle}
            {subscription.current_period_end && ` · period ends ${subscription.current_period_end}`}
          </p>
        </div>
        <Badge tone={subscription.status === 'active' ? 'ok' : 'warn'}>
          {subscription.status}
        </Badge>
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      <Card title="Usage this period">
        <table>
          <thead>
            <tr>
              <th style={{ width: '32%' }}>Allowance</th>
              <th style={{ width: '44%' }} />
              <th style={{ textAlign: 'right' }}>Used</th>
            </tr>
          </thead>
          <tbody>
            {usage.map((u) => (
              <tr key={u.metric}>
                <td className="strong">{u.label}</td>
                <td><Meter used={u.used} limit={u.limit} /></td>
                <td style={{ textAlign: 'right' }} className="mono">
                  {u.unlimited ? `${u.used} / ∞` : `${u.used} / ${u.limit}`}
                  {u.exhausted && ' ⚠'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>

      <div style={{ marginTop: 16 }}>
        <Card
          title="Plans"
          action={!canManage && <span className="hint">Only the owner can change this</span>}
        >
          <div className="stat-grid">
            {plans.map((p) => {
              const isCurrent = Number(p.id) === Number(plan.id)
              return (
                <div
                  key={p.id}
                  className="stat"
                  style={isCurrent ? { borderColor: 'var(--accent)' } : undefined}
                >
                  <div className="label">{p.name}</div>
                  <div className="value">
                    {Number(p.price_monthly) === 0
                      ? 'Free'
                      : `${p.currency_code} ${Number(p.price_monthly).toLocaleString()}`}
                  </div>
                  <div className="hint" style={{ marginTop: 8, lineHeight: 1.7 }}>
                    <Limit label="doctors" value={p.max_doctors} />
                    <Limit label="staff" value={p.max_staff} />
                    <Limit label="patients" value={p.max_patients} />
                    <Limit label="invoices / month" value={p.max_invoices_month} />
                    <Limit label="AI calls / month" value={p.max_ai_calls_month} />
                  </div>
                  <div style={{ marginTop: 10 }}>
                    {isCurrent ? (
                      <Badge tone="ok">current plan</Badge>
                    ) : canManage ? (
                      <button
                        className="btn btn-sm btn-secondary"
                        disabled={busy != null}
                        onClick={() => choose(p)}
                      >
                        {busy === p.id ? 'Switching…' : 'Switch to this'}
                      </button>
                    ) : null}
                  </div>
                </div>
              )
            })}
          </div>

          <p className="hint" style={{ marginTop: 14 }}>
            A smaller plan is refused while the clinic is over what it allows —
            nothing is deleted to make room. Reduce first, then switch.
          </p>
        </Card>
      </div>
    </>
  )
}

/** A bar, or a dash where there is no ceiling to draw one against. */
function Meter({ used, limit }) {
  if (limit == null) return <span className="hint">unlimited</span>

  const pct = limit === 0 ? 100 : Math.min(100, Math.round((used / limit) * 100))
  const tone = pct >= 100 ? 'var(--danger)' : pct >= 80 ? 'var(--warn)' : 'var(--ok)'

  return (
    <div style={{ background: 'var(--border)', borderRadius: 999, height: 8, width: '100%' }}>
      <div style={{ background: tone, borderRadius: 999, height: 8, width: `${pct}%` }} />
    </div>
  )
}

function Limit({ label, value }) {
  return (
    <div>
      {value == null ? 'Unlimited' : Number(value).toLocaleString()} {label}
    </div>
  )
}
