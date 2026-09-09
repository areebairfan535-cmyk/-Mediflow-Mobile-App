import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Stat, Badge, Loading, Empty, ErrorBox, when } from '../components.jsx'

/**
 * Super Admin Panel (§21) — the only cross-tenant view in the product.
 * Reachable solely by users with is_platform_admin; the API enforces that
 * independently of this component being hidden from the nav.
 */
export default function Platform() {
  const [dash, setDash] = useState({ loading: true })
  const [orgs, setOrgs] = useState({ loading: true })
  const [search, setSearch] = useState('')
  const [notice, setNotice] = useState(null)
  // The clinic whose people are open below the table, if any.
  const [people, setPeople] = useState(null)
  const [who, setWho] = useState('all')

  async function openPeople(org) {
    if (people?.orgId === org.id) { setPeople(null); return }   // click again to close
    setPeople({ loading: true, orgId: org.id, orgName: org.name })
    setWho('all')
    try {
      const res = await api.platformOrganization(org.id)
      setPeople({ orgId: org.id, orgName: org.name, rows: res.data.members || [] })
    } catch (error) {
      setPeople({ orgId: org.id, orgName: org.name, error })
    }
  }

  async function loadDashboard() {
    setDash({ loading: true })
    try {
      const res = await api.platformDashboard()
      setDash({ loading: false, ...res.data })
    } catch (error) {
      setDash({ loading: false, error })
    }
  }

  async function loadOrgs(q = '') {
    setOrgs({ loading: true })
    try {
      const res = await api.platformOrganizations({ search: q, per_page: 50 })
      setOrgs({ loading: false, rows: res.data, meta: res.meta })
    } catch (error) {
      setOrgs({ loading: false, error })
    }
  }

  // The plan list drives the per-row picker. If it fails to load the column
  // falls back to a read-only badge rather than an empty select.
  const [plans, setPlans] = useState([])

  useEffect(() => {
    loadDashboard()
    loadOrgs()
    api.platformPlans().then((r) => setPlans(r.data.plans)).catch(() => setPlans([]))
  }, [])

  async function setStatus(id, status) {
    setNotice(null)
    try {
      await api.setOrganizationStatus(id, status)
      setNotice({ ok: true, message: `Organization #${id} is now ${status}.` })
      await loadOrgs(search)
      await loadDashboard()
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    }
  }

  async function setPlan(id, planId) {
    setNotice(null)
    try {
      const res = await api.setOrganizationPlan(id, Number(planId))
      setNotice({ ok: true, message: `Organization #${id} moved to ${res.data.plan.name}.` })
      await loadOrgs(search)
    } catch (error) {
      // A clinic already over the target plan comes back as a field error
      // listing each limit in the way; the bare message says only "failed".
      const detail = error.fieldMessages?.length ? error.fieldMessages.join(' · ') : error.message
      setNotice({ ok: false, message: detail })
    }
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Platform</h1>
          <p>Every organization on this deployment. Cross-tenant by design — nothing else in the API is.</p>
        </div>
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      {dash.loading ? (
        <Loading />
      ) : dash.error ? (
        <ErrorBox error={dash.error} onRetry={loadDashboard} />
      ) : (
        <>
          <div className="stat-grid">
            <Stat
              label="Organizations"
              value={dash.counts.active_organizations}
              hint={`${dash.counts.total_organizations} total`}
            />
            <Stat label="Active users" value={dash.counts.active_users} />
            <Stat
              label="Active doctors"
              value={dash.counts.doctors}
              hint={dash.counts.doctors_total > dash.counts.doctors
                ? `${dash.counts.doctors_total} on record`
                : undefined}
            />
            <Stat label="Active patients" value={dash.counts.patients} />
          </div>

          {/* §21 asks for the appointment, invoice and claim record here. The
              API had already counted all three; only the panel was missing
              them, so an admin had to open three other pages to learn the size
              of the thing they were running. */}
          <div className="stat-grid">
            <Stat label="Appointments" value={dash.counts.appointments} hint="all time" />
            <Stat label="Invoices" value={dash.counts.invoices} hint="all time" />
            <Stat
              label="Claims"
              value={dash.counts.claims}
              hint={dash.counts.claims > 0 ? 'insurance' : 'none filed'}
            />
          </div>

          {/* The "Phase 2"/"Phase 3" hints that used to sit on these are gone:
              those phases shipped, and a finished panel that still labels its
              own numbers as forthcoming reads as unfinished. */}
          <div className="stat-grid">
            <Stat label="Billed" value={dash.money.billed_total} money />
            <Stat label="Collected" value={dash.money.collected_total} money />
            <Stat label="Outstanding" value={dash.money.outstanding_total} money
                  hint={Number(dash.money.outstanding_total) > 0 ? 'still owed' : 'nothing owed'} />
            <Stat
              label="Failed payments"
              value={dash.failed_payments}
              hint={dash.failed_payments > 0 ? 'needs attention' : 'none'}
            />
          </div>
        </>
      )}

      <Card
        title="Organizations"
        action={
          <form
            className="row"
            onSubmit={(e) => {
              e.preventDefault()
              loadOrgs(search)
            }}
          >
            <input
              placeholder="Search name, slug or city…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              style={{ width: 240, padding: '6px 10px', fontSize: 13 }}
            />
            <button className="btn btn-sm">Search</button>
          </form>
        }
        bodyless
      >
        {orgs.loading ? (
          <Loading />
        ) : orgs.error ? (
          <div style={{ padding: 18 }}><ErrorBox error={orgs.error} onRetry={() => loadOrgs(search)} /></div>
        ) : orgs.rows.length === 0 ? (
          <Empty icon="🏥" title="No organizations found" />
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Slug</th>
                  <th>Country</th>
                  <th>Members</th>
                  <th>Patients</th>
                  <th>Plan</th>
                  <th>Created</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {orgs.rows.map((o) => (
                  <tr key={o.id}>
                    <td className="strong">{o.name}</td>
                    <td className="mono">{o.slug}</td>
                    <td>{o.country_code} · {o.currency_code}</td>
                    <td className="mono">{o.members}</td>
                    <td className="mono">{o.patients}</td>
                    <td>
                      {plans.length === 0 ? (
                        o.plan ? <Badge tone="accent">{o.plan}</Badge> : '—'
                      ) : (
                        <select
                          value={plans.find((p) => p.slug === o.plan)?.id ?? ''}
                          onChange={(e) => setPlan(o.id, e.target.value)}
                          style={{ width: 'auto', padding: '4px 8px', fontSize: 12.5 }}
                        >
                          <option value="" disabled>—</option>
                          {plans.map((p) => (
                            <option key={p.id} value={p.id}>{p.name}</option>
                          ))}
                        </select>
                      )}
                    </td>
                    <td>{when(o.created_at)}</td>
                    <td><Badge>{o.status}</Badge></td>
                    <td>
                      <button
                        className="btn btn-sm btn-secondary"
                        style={{ marginRight: 6 }}
                        onClick={() => openPeople(o)}
                      >
                        {people?.orgId === o.id ? 'Hide people' : 'People'}
                      </button>
                      <button
                        className="btn btn-sm btn-secondary"
                        onClick={() => setStatus(o.id, o.status === 'active' ? 'suspended' : 'active')}
                      >
                        {o.status === 'active' ? 'Suspend' : 'Activate'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {/* §21 asks a platform admin to look after doctor and patient accounts.
          The counts in the table above say how many; this says who. The
          endpoint has returned them all along — nothing here was calling it. */}
      {people && <People people={people} who={who} setWho={setWho} />}
    </>
  )
}

/** One clinic's members, grouped the way §21 names them. */
function People({ people, who, setWho }) {
  if (people.loading) return <Card title="Loading people…"><Loading /></Card>
  if (people.error) return <Card title={people.orgName}><ErrorBox error={people.error} /></Card>

  const rows = people.rows || []

  // A doctor is somebody with a row in `doctors`, not somebody whose role slug
  // reads like one — the clinic's own doctor holds `solo_practitioner` and its
  // owner is a dentist holding `org_owner`. Anyone who is neither a doctor nor
  // a patient is the front desk, the lab, the accounts room.
  const groupOf = (m) => {
    if (m.doctor_id) return 'doctors'
    if (m.patient_id) return 'patients'
    return 'staff'
  }

  const counts = rows.reduce((acc, m) => {
    acc[groupOf(m)] = (acc[groupOf(m)] || 0) + 1
    return acc
  }, {})

  const shown = who === 'all' ? rows : rows.filter((m) => groupOf(m) === who)

  const tabs = [
    ['all', `Everyone (${rows.length})`],
    ['doctors', `Doctors (${counts.doctors || 0})`],
    ['patients', `Patients (${counts.patients || 0})`],
    ['staff', `Staff (${counts.staff || 0})`],
  ]

  return (
    <Card
      title={people.orgName}
      action={
        <div className="row" style={{ gap: 6 }}>
          {tabs.map(([key, label]) => (
            <button key={key}
                    className={`btn btn-sm ${who === key ? '' : 'btn-secondary'}`}
                    onClick={() => setWho(key)}>
              {label}
            </button>
          ))}
        </div>
      }
      bodyless
    >
      {shown.length === 0 ? (
        <Empty icon="👤" title="Nobody in this group" />
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th><th>Email</th><th>Phone</th>
                <th>Role</th><th>Joined</th><th>Account</th>
              </tr>
            </thead>
            <tbody>
              {shown.map((m) => (
                <tr key={m.id}>
                  <td className="strong">{m.name}</td>
                  <td className="mono">{m.email}</td>
                  <td className="mono">{m.phone || '—'}</td>
                  <td>
                    {m.role_name || m.role_slug || '—'}
                    {/* What they are, where it differs from what they may
                        press — the owner here is also a dentist. */}
                    {m.specialty && <div className="hint">{m.specialty}</div>}
                    {m.mrn && <div className="hint mono">{m.mrn}</div>}
                  </td>
                  <td>{when(m.joined_at)}</td>
                  <td>
                    {/* Two statuses, and they are not the same thing: the
                        membership can be revoked while the login still works
                        elsewhere. Say so only when they disagree. */}
                    <Badge tone={m.user_status === 'active' ? 'ok' : 'danger'}>
                      {m.user_status || 'unknown'}
                    </Badge>
                    {m.status !== m.user_status && (
                      <span className="hint"> · membership {m.status}</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}
