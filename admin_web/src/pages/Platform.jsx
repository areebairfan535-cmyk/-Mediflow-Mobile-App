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
            <Stat label="Doctors" value={dash.counts.doctors} />
            <Stat label="Patients" value={dash.counts.patients} hint="Phase 2" />
          </div>

          <div className="stat-grid">
            <Stat label="Billed" value={dash.money.billed_total} money hint="Phase 3" />
            <Stat label="Collected" value={dash.money.collected_total} money hint="Phase 3" />
            <Stat label="Outstanding" value={dash.money.outstanding_total} money hint="Phase 3" />
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
    </>
  )
}
