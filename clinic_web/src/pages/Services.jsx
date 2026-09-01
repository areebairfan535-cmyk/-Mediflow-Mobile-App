import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, Empty, ErrorBox, Modal } from '../components.jsx'

/**
 * The service catalogue and its prices (§6, §22).
 *
 * This is the screen §22's onboarding needs — "configure services" — and
 * without it a new clinic can register patients and see them, then find it
 * cannot bill for anything.
 *
 * Two things here are deliberately separate, because they behave differently:
 *
 *  - **The service** is what the clinic does. Its code never changes: invoice
 *    lines snapshot it, and renaming a code would rewrite what past invoices
 *    appear to say.
 *  - **The price** is what it costs *today*. Adding a new one closes the old
 *    one rather than overwriting it, so an invoice raised last month keeps the
 *    price it was raised at.
 */
const CATEGORIES = [
  'consultation', 'followup', 'procedure', 'lab', 'imaging', 'injection', 'room', 'other',
]

export default function Services({ session }) {
  const [state, setState] = useState({ loading: true })
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('')
  const [notice, setNotice] = useState(null)
  const [modal, setModal] = useState(null)      // { kind: 'service'|'price', service }

  const canManage = session.can('service.manage')

  async function load(params = {}) {
    setState((s) => ({ ...s, loading: true }))
    try {
      const res = await api.services({
        search: params.search ?? search,
        category: params.category ?? category,
      })
      setState({ loading: false, rows: res.data.services, departments: res.data.departments })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [])

  const rows = state.rows || []
  const priced = rows.filter((r) => r.price != null).length

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Services</h1>
          <p>
            What the clinic charges for. A service with no price cannot be put on an
            invoice — the factory refuses it rather than guessing a figure.
          </p>
        </div>
        {canManage && (
          <button className="btn" onClick={() => setModal({ kind: 'service' })}>
            New service
          </button>
        )}
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      {rows.length > 0 && priced < rows.length && (
        <div className="alert" style={{ background: 'var(--warn-soft)', color: 'var(--warn)',
                                        borderColor: 'rgba(180,106,0,0.2)' }}>
          {rows.length - priced} service(s) have no price yet and cannot be invoiced.
        </div>
      )}

      <Card
        title={`${rows.length} service(s)`}
        bodyless
        action={
          <form
            className="row"
            onSubmit={(e) => { e.preventDefault(); load() }}
          >
            <input
              placeholder="Search name or code…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              style={{ width: 200, padding: '6px 10px', fontSize: 13 }}
            />
            <select
              value={category}
              onChange={(e) => { setCategory(e.target.value); load({ category: e.target.value }) }}
              style={{ width: 'auto', padding: '6px 10px', fontSize: 13 }}
            >
              <option value="">All categories</option>
              {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
            <button className="btn btn-sm">Search</button>
          </form>
        }
      >
        {state.loading ? (
          <Loading />
        ) : state.error ? (
          <div style={{ padding: 18 }}><ErrorBox error={state.error} onRetry={() => load()} /></div>
        ) : rows.length === 0 ? (
          <Empty
            icon="🧾"
            title="No services yet"
            hint={canManage
              ? 'Add the ones this clinic charges for — consultation first.'
              : 'Ask an administrator to set up the catalogue.'}
          />
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Code</th><th>Service</th><th>Category</th><th>Department</th>
                  <th style={{ textAlign: 'right' }}>Price</th><th>Tax</th><th>Max disc.</th>
                  <th /><th />
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id} style={r.is_active ? undefined : { opacity: 0.55 }}>
                    <td className="mono strong">{r.code}</td>
                    <td>
                      {r.name}
                      {r.description && (
                        <div className="hint">{r.description}</div>
                      )}
                    </td>
                    <td>{r.category}</td>
                    <td>{r.department || '—'}</td>
                    <td style={{ textAlign: 'right' }} className="mono">
                      {r.price == null ? (
                        <Badge tone="warn">no price</Badge>
                      ) : (
                        `${r.currency_code} ${Number(r.price).toLocaleString()}`
                      )}
                    </td>
                    <td>{Number(r.is_taxable) === 1 ? 'taxable' : 'exempt'}</td>
                    <td className="mono">
                      {r.max_discount_pct ? `${Number(r.max_discount_pct)}%` : '—'}
                    </td>
                    <td>
                      <Badge tone={Number(r.is_active) === 1 ? 'ok' : 'neutral'}>
                        {Number(r.is_active) === 1 ? 'active' : 'retired'}
                      </Badge>
                    </td>
                    <td>
                      {canManage && (
                        <div className="row">
                          <button className="btn btn-sm btn-secondary"
                                  onClick={() => setModal({ kind: 'service', service: r })}>
                            Edit
                          </button>
                          <button className="btn btn-sm btn-secondary"
                                  onClick={() => setModal({ kind: 'price', service: r })}>
                            {r.price == null ? 'Set price' : 'New price'}
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {modal?.kind === 'service' && (
        <ServiceForm
          service={modal.service}
          departments={state.departments || []}
          onClose={() => setModal(null)}
          onSaved={(message) => { setModal(null); setNotice({ ok: true, message }); load() }}
          onError={(message) => setNotice({ ok: false, message })}
        />
      )}

      {modal?.kind === 'price' && (
        <PriceForm
          service={modal.service}
          onClose={() => setModal(null)}
          onSaved={(message) => { setModal(null); setNotice({ ok: true, message }); load() }}
        />
      )}
    </>
  )
}

/* ------------------------------------------------------------------ */

function ServiceForm({ service, departments, onClose, onSaved }) {
  const editing = Boolean(service)
  const [form, setForm] = useState({
    code: service?.code ?? '',
    name: service?.name ?? '',
    description: service?.description ?? '',
    department: service?.department ?? '',
    category: service?.category ?? 'consultation',
    is_taxable: service ? Number(service.is_taxable) === 1 : true,
    is_active: service ? Number(service.is_active) === 1 : true,
    // Only offered when creating: a price needs its own dated row, and the
    // create endpoint opens the first one for you.
    price: '',
    currency_code: service?.currency_code ?? 'PKR',
  })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const set = (k) => (e) =>
    setForm({ ...form, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      if (editing) {
        await api.updateService(service.id, {
          name: form.name,
          description: form.description || null,
          department: form.department || null,
          category: form.category,
          is_taxable: form.is_taxable,
          is_active: form.is_active,
        })
        onSaved('Service updated.')
      } else {
        await api.createService({
          code: form.code.trim().toUpperCase(),
          name: form.name,
          description: form.description || null,
          department: form.department || null,
          category: form.category,
          is_taxable: form.is_taxable,
          ...(form.price !== '' && {
            price: Number(form.price),
            currency_code: form.currency_code.toUpperCase(),
          }),
        })
        onSaved('Service added.')
      }
    } catch (err) {
      setError(err)
      setBusy(false)
    }
  }

  return (
    <Modal
      title={editing ? `Edit ${service.code}` : 'New service'}
      onClose={onClose}
      footer={
        <>
          <button className="btn btn-secondary" onClick={onClose}>Cancel</button>
          <button className="btn" form="service-form" disabled={busy}>
            {busy ? 'Saving…' : 'Save'}
          </button>
        </>
      }
    >
      <form id="service-form" onSubmit={submit}>
        {error && (
          <div className="alert">
            {error.fieldMessages?.length ? error.fieldMessages.join(' · ') : error.message}
          </div>
        )}

        <div className="grid-2">
          <div className="field">
            <label>Code {editing ? '' : '*'}</label>
            <input value={form.code} onChange={set('code')} required={!editing}
                   disabled={editing} placeholder="CONSULT-GEN" />
            {!editing && (
              <p className="hint">
                Permanent — invoice lines snapshot it, so it cannot be renamed later.
              </p>
            )}
          </div>
          <div className="field">
            <label>Category</label>
            <select value={form.category} onChange={set('category')}>
              {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
          </div>
        </div>

        <div className="field">
          <label>Name *</label>
          <input value={form.name} onChange={set('name')} required
                 placeholder="General Consultation" />
        </div>

        <div className="field">
          <label>Description</label>
          <input value={form.description} onChange={set('description')} />
        </div>

        <div className="field">
          <label>Department</label>
          <input value={form.department} onChange={set('department')} list="departments"
                 placeholder="Dental, Radiology…" />
          <datalist id="departments">
            {departments.map((d) => <option key={d} value={d} />)}
          </datalist>
        </div>

        {!editing && (
          <div className="grid-2">
            <div className="field">
              <label>Opening price</label>
              <input type="number" min="0" step="0.01" value={form.price} onChange={set('price')}
                     placeholder="Leave empty to set it later" />
            </div>
            <div className="field">
              <label>Currency</label>
              <input value={form.currency_code} onChange={set('currency_code')} maxLength={3} />
            </div>
          </div>
        )}

        <div className="row" style={{ gap: 18, marginTop: 6 }}>
          <label className="row" style={{ gap: 8, alignItems: 'center' }}>
            <input type="checkbox" checked={form.is_taxable} onChange={set('is_taxable')}
                   style={{ width: 'auto' }} />
            <span>Taxable</span>
          </label>
          {editing && (
            <label className="row" style={{ gap: 8, alignItems: 'center' }}>
              <input type="checkbox" checked={form.is_active} onChange={set('is_active')}
                     style={{ width: 'auto' }} />
              <span>Offered</span>
            </label>
          )}
        </div>
      </form>
    </Modal>
  )
}

/**
 * A new price for an existing service.
 *
 * The old one is closed by the API rather than replaced, which is why this is
 * "new price" and not "edit price": invoices already raised must keep the
 * figure they were raised at.
 */
function PriceForm({ service, onClose, onSaved }) {
  const [form, setForm] = useState({
    price: service.price ?? '',
    currency_code: service.currency_code ?? 'PKR',
    tax_rate: service.price_tax_rate != null ? String(Number(service.price_tax_rate) * 100) : '',
    max_discount_pct: service.max_discount_pct ?? '',
    effective_from: '',
  })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.addServicePrice(service.id, {
        price: Number(form.price),
        currency_code: form.currency_code.toUpperCase(),
        // Entered as a percentage, stored as a fraction — nobody types 0.17.
        ...(form.tax_rate !== '' && { tax_rate: Number(form.tax_rate) / 100 }),
        ...(form.max_discount_pct !== '' && { max_discount_pct: Number(form.max_discount_pct) }),
        ...(form.effective_from !== '' && { effective_from: form.effective_from }),
      })
      onSaved(`Price set for ${service.code}.`)
    } catch (err) {
      setError(err)
      setBusy(false)
    }
  }

  return (
    <Modal
      title={`Price — ${service.name}`}
      onClose={onClose}
      footer={
        <>
          <button className="btn btn-secondary" onClick={onClose}>Cancel</button>
          <button className="btn" form="price-form" disabled={busy}>
            {busy ? 'Saving…' : 'Save price'}
          </button>
        </>
      }
    >
      <form id="price-form" onSubmit={submit}>
        {error && (
          <div className="alert">
            {error.fieldMessages?.length ? error.fieldMessages.join(' · ') : error.message}
          </div>
        )}

        <div className="grid-2">
          <div className="field">
            <label>Price *</label>
            <input type="number" min="0" step="0.01" value={form.price}
                   onChange={set('price')} required />
          </div>
          <div className="field">
            <label>Currency *</label>
            <input value={form.currency_code} onChange={set('currency_code')} maxLength={3} required />
          </div>
        </div>

        <div className="grid-2">
          <div className="field">
            <label>Tax %</label>
            <input type="number" min="0" max="100" step="0.01" value={form.tax_rate}
                   onChange={set('tax_rate')} placeholder="Blank = the country's rate" />
          </div>
          <div className="field">
            <label>Max discount %</label>
            <input type="number" min="0" max="100" step="0.01" value={form.max_discount_pct}
                   onChange={set('max_discount_pct')} placeholder="0" />
          </div>
        </div>

        <div className="field">
          <label>Effective from</label>
          <input type="date" value={form.effective_from} onChange={set('effective_from')} />
          <p className="hint">
            Blank means today. The price in force before this date stays on the
            invoices that used it.
          </p>
        </div>
      </form>
    </Modal>
  )
}
