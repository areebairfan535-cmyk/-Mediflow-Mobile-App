import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, ErrorBox } from '../components.jsx'

/**
 * What the platform sells, and where it sells it (§21, §22, §23).
 *
 * Two tables that look alike and are not: a **plan** is a commercial offer,
 * and a **country** is the behaviour of a market — currency, timezone, tax
 * rate, invoice format. §23 forbids hard-coding the second, so the row here is
 * the configuration rather than a mirror of something in the code.
 */
export default function PlatformConfig() {
  const [plans, setPlans] = useState({ loading: true })
  const [countries, setCountries] = useState({ loading: true })
  const [notice, setNotice] = useState(null)
  const [editing, setEditing] = useState(null)      // { kind, row }

  async function loadPlans() {
    try {
      const res = await api.platformPlans()
      setPlans({ loading: false, rows: res.data.plans })
    } catch (error) {
      setPlans({ loading: false, error })
    }
  }

  async function loadCountries() {
    try {
      const res = await api.platformCountries()
      setCountries({ loading: false, rows: res.data.countries })
    } catch (error) {
      setCountries({ loading: false, error })
    }
  }

  useEffect(() => { loadPlans(); loadCountries() }, [])

  async function save(kind, row, body) {
    setNotice(null)
    try {
      if (kind === 'plan') {
        row ? await api.updatePlatformPlan(row.id, body) : await api.createPlatformPlan(body)
        await loadPlans()
      } else {
        row ? await api.updateCountry(row.id, body) : await api.createCountry(body)
        await loadCountries()
      }
      setNotice({ ok: true, message: `${kind === 'plan' ? 'Plan' : 'Country'} saved.` })
      setEditing(null)
    } catch (error) {
      const detail = error.fieldMessages?.length ? error.fieldMessages.join(' · ') : error.message
      setNotice({ ok: false, message: detail })
    }
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Plans &amp; markets</h1>
          <p>
            What the platform offers, and how each country behaves — currency, timezone
            and tax. Nothing about a market is hard-coded (§23).
          </p>
        </div>
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      <Card
        title="Plans"
        action={
          <button className="btn btn-sm" onClick={() => setEditing({ kind: 'plan', row: null })}>
            New plan
          </button>
        }
        bodyless
      >
        {plans.loading ? <Loading /> : plans.error ? (
          <div style={{ padding: 18 }}><ErrorBox error={plans.error} onRetry={loadPlans} /></div>
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Plan</th><th>Price / month</th><th>Doctors</th><th>Staff</th>
                  <th>Patients</th><th>Invoices / mo</th><th>AI / mo</th>
                  <th>Clinics</th><th /><th />
                </tr>
              </thead>
              <tbody>
                {plans.rows.map((p) => (
                  <tr key={p.id}>
                    <td className="strong">
                      {p.name} <span className="mono" style={{ color: 'var(--muted)' }}>{p.slug}</span>
                    </td>
                    <td className="mono">
                      {Number(p.price_monthly) === 0
                        ? 'Free'
                        : `${p.currency_code} ${Number(p.price_monthly).toLocaleString()}`}
                    </td>
                    <td className="mono"><Cap value={p.max_doctors} /></td>
                    <td className="mono"><Cap value={p.max_staff} /></td>
                    <td className="mono"><Cap value={p.max_patients} /></td>
                    <td className="mono"><Cap value={p.max_invoices_month} /></td>
                    <td className="mono"><Cap value={p.max_ai_calls_month} /></td>
                    <td className="mono">{p.organizations}</td>
                    <td><Badge tone={p.is_active ? 'ok' : 'neutral'}>
                      {p.is_active ? 'offered' : 'retired'}
                    </Badge></td>
                    <td>
                      <button className="btn btn-sm btn-secondary"
                              onClick={() => setEditing({ kind: 'plan', row: p })}>
                        Edit
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <div style={{ marginTop: 16 }}>
        <Card
          title="Countries"
          action={
            <button className="btn btn-sm" onClick={() => setEditing({ kind: 'country', row: null })}>
              Add country
            </button>
          }
          bodyless
        >
          {countries.loading ? <Loading /> : countries.error ? (
            <div style={{ padding: 18 }}><ErrorBox error={countries.error} onRetry={loadCountries} /></div>
          ) : (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Country</th><th>Currency</th><th>Timezone</th><th>Default tax</th>
                    <th>Invoice prefix</th><th>Clinics</th><th /><th />
                  </tr>
                </thead>
                <tbody>
                  {countries.rows.map((c) => (
                    <tr key={c.id}>
                      <td className="strong">{c.name} <span className="mono">{c.code}</span></td>
                      <td className="mono">{c.currency_code} {c.currency_symbol}</td>
                      <td>{c.timezone}</td>
                      <td className="mono">{(Number(c.default_tax_rate) * 100).toFixed(2)}%</td>
                      <td className="mono">{c.invoice_prefix}</td>
                      <td className="mono">{c.organizations}</td>
                      <td><Badge tone={c.is_active ? 'ok' : 'neutral'}>
                        {c.is_active ? 'open' : 'closed'}
                      </Badge></td>
                      <td>
                        <button className="btn btn-sm btn-secondary"
                                onClick={() => setEditing({ kind: 'country', row: c })}>
                          Edit
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <p className="hint" style={{ padding: '0 18px 16px' }}>
            Editing a country applies to clinics created from then on. Existing clinics
            keep the currency, timezone and tax they were set up with — changing a rate
            underneath issued invoices would silently re-price them.
          </p>
        </Card>
      </div>

      {editing?.kind === 'plan' && (
        <PlanForm
          plan={editing.row}
          onClose={() => setEditing(null)}
          onSave={(body) => save('plan', editing.row, body)}
        />
      )}

      {editing?.kind === 'country' && (
        <CountryForm
          country={editing.row}
          onClose={() => setEditing(null)}
          onSave={(body) => save('country', editing.row, body)}
        />
      )}
    </>
  )
}

/** A ceiling, or the fact that there isn't one. */
function Cap({ value }) {
  return value == null ? <span style={{ color: 'var(--muted)' }}>∞</span> : Number(value).toLocaleString()
}

/* ------------------------------------------------------------------ */

const LIMIT_FIELDS = [
  ['max_doctors', 'Doctors'],
  ['max_staff', 'Staff accounts'],
  ['max_patients', 'Patients'],
  ['max_storage_mb', 'Storage (MB)'],
  ['max_invoices_month', 'Invoices / month'],
  ['max_appointments_month', 'Appointments / month'],
  ['max_ai_calls_month', 'AI calls / month'],
]

function PlanForm({ plan, onClose, onSave }) {
  const [form, setForm] = useState(() => ({
    slug: plan?.slug ?? '',
    name: plan?.name ?? '',
    description: plan?.description ?? '',
    price_monthly: plan?.price_monthly ?? '0',
    price_yearly: plan?.price_yearly ?? '',
    currency_code: plan?.currency_code ?? 'USD',
    is_active: plan ? Boolean(plan.is_active) : true,
    sort_order: plan?.sort_order ?? 0,
    ...Object.fromEntries(LIMIT_FIELDS.map(([k]) => [k, plan?.[k] ?? ''])),
  }))
  const [busy, setBusy] = useState(false)
  const set = (k) => (ev) =>
    setForm({ ...form, [k]: ev.target.type === 'checkbox' ? ev.target.checked : ev.target.value })

  async function submit(ev) {
    ev.preventDefault()
    setBusy(true)

    // An empty limit box means unlimited, and must be sent as an explicit
    // null — leaving it out would mean "don't change it", which is a
    // different request.
    const body = {
      name: form.name,
      description: form.description || null,
      price_monthly: Number(form.price_monthly || 0),
      price_yearly: form.price_yearly === '' ? null : Number(form.price_yearly),
      currency_code: form.currency_code.toUpperCase(),
      is_active: form.is_active,
      sort_order: Number(form.sort_order || 0),
      ...Object.fromEntries(
        LIMIT_FIELDS.map(([k]) => [k, form[k] === '' || form[k] == null ? null : Number(form[k])]),
      ),
    }
    if (!plan) body.slug = form.slug

    try { await onSave(body) } finally { setBusy(false) }
  }

  return (
    <Modal title={plan ? `Edit ${plan.name}` : 'New plan'} onClose={onClose}>
      <form onSubmit={submit}>
        {!plan && (
          <div className="field">
            <label>Slug</label>
            <input value={form.slug} onChange={set('slug')} required placeholder="growth" />
            <p className="hint">Permanent. Clients key off it, so it cannot be renamed later.</p>
          </div>
        )}

        <div className="field">
          <label>Name</label>
          <input value={form.name} onChange={set('name')} required />
        </div>

        <div className="field">
          <label>Description</label>
          <input value={form.description} onChange={set('description')} />
        </div>

        <div className="row" style={{ gap: 12 }}>
          <div className="field" style={{ flex: 1 }}>
            <label>Price / month</label>
            <input type="number" min="0" step="0.01"
                   value={form.price_monthly} onChange={set('price_monthly')} />
          </div>
          <div className="field" style={{ flex: 1 }}>
            <label>Price / year</label>
            <input type="number" min="0" step="0.01"
                   value={form.price_yearly} onChange={set('price_yearly')} placeholder="—" />
          </div>
          <div className="field" style={{ width: 110 }}>
            <label>Currency</label>
            <input value={form.currency_code} onChange={set('currency_code')} maxLength={3} />
          </div>
        </div>

        <p className="hint" style={{ margin: '4px 0 10px' }}>
          Leave a limit empty for unlimited.
        </p>

        <div className="row" style={{ flexWrap: 'wrap', gap: 12 }}>
          {LIMIT_FIELDS.map(([key, label]) => (
            <div className="field" key={key} style={{ width: 150 }}>
              <label>{label}</label>
              <input type="number" min="0" value={form[key] ?? ''} onChange={set(key)} placeholder="∞" />
            </div>
          ))}
        </div>

        <div className="row" style={{ gap: 16, alignItems: 'center', marginTop: 6 }}>
          <label className="row" style={{ gap: 8, alignItems: 'center' }}>
            <input type="checkbox" checked={form.is_active} onChange={set('is_active')}
                   style={{ width: 'auto' }} />
            <span>Offered to new clinics</span>
          </label>
          <div className="field" style={{ width: 120, margin: 0 }}>
            <label>Sort order</label>
            <input type="number" min="0" value={form.sort_order} onChange={set('sort_order')} />
          </div>
        </div>

        <div className="row" style={{ marginTop: 16, gap: 8 }}>
          <button className="btn" disabled={busy}>{busy ? 'Saving…' : 'Save plan'}</button>
          <button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
        </div>
      </form>
    </Modal>
  )
}

function CountryForm({ country, onClose, onSave }) {
  const [form, setForm] = useState(() => ({
    code: country?.code ?? '',
    name: country?.name ?? '',
    currency_code: country?.currency_code ?? '',
    currency_symbol: country?.currency_symbol ?? '',
    timezone: country?.timezone ?? '',
    date_format: country?.date_format ?? 'd/m/Y',
    // Stored as a fraction, entered as a percentage — nobody types 0.17.
    tax_percent: country ? String(Number(country.default_tax_rate) * 100) : '0',
    invoice_prefix: country?.invoice_prefix ?? 'INV',
    is_active: country ? Boolean(country.is_active) : true,
  }))
  const [busy, setBusy] = useState(false)
  const set = (k) => (ev) =>
    setForm({ ...form, [k]: ev.target.type === 'checkbox' ? ev.target.checked : ev.target.value })

  async function submit(ev) {
    ev.preventDefault()
    setBusy(true)
    try {
      await onSave({
        code: form.code.toUpperCase(),
        name: form.name,
        currency_code: form.currency_code.toUpperCase(),
        currency_symbol: form.currency_symbol || null,
        timezone: form.timezone,
        date_format: form.date_format,
        default_tax_rate: Number(form.tax_percent || 0) / 100,
        invoice_prefix: form.invoice_prefix,
        is_active: form.is_active,
      })
    } finally { setBusy(false) }
  }

  return (
    <Modal title={country ? `Edit ${country.name}` : 'Add a country'} onClose={onClose}>
      <form onSubmit={submit}>
        <div className="row" style={{ gap: 12 }}>
          <div className="field" style={{ width: 110 }}>
            <label>Code</label>
            <input value={form.code} onChange={set('code')} maxLength={2} required
                   disabled={Boolean(country)} placeholder="GB" />
          </div>
          <div className="field" style={{ flex: 1 }}>
            <label>Name</label>
            <input value={form.name} onChange={set('name')} required />
          </div>
        </div>

        <div className="row" style={{ gap: 12 }}>
          <div className="field" style={{ width: 130 }}>
            <label>Currency</label>
            <input value={form.currency_code} onChange={set('currency_code')} maxLength={3} required />
          </div>
          <div className="field" style={{ width: 110 }}>
            <label>Symbol</label>
            <input value={form.currency_symbol} onChange={set('currency_symbol')} placeholder="£" />
          </div>
          <div className="field" style={{ flex: 1 }}>
            <label>Timezone</label>
            <input value={form.timezone} onChange={set('timezone')} required
                   placeholder="Europe/London" />
          </div>
        </div>

        <div className="row" style={{ gap: 12 }}>
          <div className="field" style={{ width: 150 }}>
            <label>Default tax %</label>
            <input type="number" min="0" max="100" step="0.01"
                   value={form.tax_percent} onChange={set('tax_percent')} />
          </div>
          <div className="field" style={{ width: 150 }}>
            <label>Date format</label>
            <input value={form.date_format} onChange={set('date_format')} />
          </div>
          <div className="field" style={{ width: 150 }}>
            <label>Invoice prefix</label>
            <input value={form.invoice_prefix} onChange={set('invoice_prefix')} />
          </div>
        </div>

        <label className="row" style={{ gap: 8, alignItems: 'center', marginTop: 4 }}>
          <input type="checkbox" checked={form.is_active} onChange={set('is_active')}
                 style={{ width: 'auto' }} />
          <span>Open for new clinics</span>
        </label>

        <div className="row" style={{ marginTop: 16, gap: 8 }}>
          <button className="btn" disabled={busy}>{busy ? 'Saving…' : 'Save country'}</button>
          <button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
        </div>
      </form>
    </Modal>
  )
}

/** Local modal shell — the admin console's components.jsx has none. */
function Modal({ title, onClose, children }) {
  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" style={{ maxWidth: 720 }} onClick={(e) => e.stopPropagation()}>
        <div className="modal-head">
          <h2>{title}</h2>
          <button className="icon-btn" onClick={onClose} aria-label="Close">✕</button>
        </div>
        <div className="modal-body">{children}</div>
      </div>
    </div>
  )
}
