import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Stat, Badge, Loading, ErrorBox } from '../components.jsx'

/**
 * Tenant overview. Deliberately honest about scope: it shows what Phase 1
 * actually holds, and names the Phase 2/3 entities that are schema-only so
 * nobody mistakes an empty table for a broken feature.
 */
export default function Overview({ session }) {
  const [state, setState] = useState({ loading: true })

  async function load() {
    setState({ loading: true })
    try {
      const [org, members, roles] = await Promise.all([
        api.organization(),
        api.members().catch(() => null),
        api.roles().catch(() => null),
      ])
      setState({
        loading: false,
        org: org.data.organization,
        members: members?.data.members ?? null,
        roles: roles?.data.roles ?? null,
      })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => {
    load()
  }, [])

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  const { org, members, roles } = state
  const active = members?.filter((m) => m.status === 'active').length

  return (
    <>
      <div className="page-head">
        <div>
          <h1>{org.name}</h1>
          <p>
            {org.country_name} · {org.currency_code} · {org.timezone}
          </p>
        </div>
        <Badge>{org.status}</Badge>
      </div>

      <div className="stat-grid">
        <Stat
          label="Team members"
          value={members ? members.length : '—'}
          hint={members ? `${active} active` : 'needs member.view'}
        />
        <Stat
          label="Roles available"
          value={roles ? roles.length : '—'}
          hint={roles ? 'system + custom' : 'needs member.view'}
        />
        <Stat label="Tax rate" value={`${(Number(org.tax_rate) * 100).toFixed(1)}%`} hint="from country default" />
        <Stat label="Invoice prefix" value={org.invoice_prefix} hint={`next: ${org.next_invoice_no}`} />
      </div>

      <div className="grid-2">
        <Card title="Your access">
          <table>
            <tbody>
              <tr>
                <td className="strong" style={{ width: 130 }}>Signed in as</td>
                <td>{session.user.name}</td>
              </tr>
              <tr>
                <td className="strong">Email</td>
                <td className="mono">{session.user.email}</td>
              </tr>
              <tr>
                <td className="strong">Role</td>
                <td><Badge tone="accent">{session.role ?? 'platform_admin'}</Badge></td>
              </tr>
              <tr>
                <td className="strong">Permissions</td>
                <td>
                  {session.user.is_platform_admin
                    ? <em style={{ color: 'var(--muted)' }}>all (platform administrator)</em>
                    : `${session.permissions.length} granted`}
                </td>
              </tr>
            </tbody>
          </table>
        </Card>

        <Card title="Organization settings">
          <table>
            <tbody>
              <tr><td className="strong" style={{ width: 130 }}>Slug</td><td className="mono">{org.slug}</td></tr>
              <tr><td className="strong">Country</td><td>{org.country_name} ({org.country_code})</td></tr>
              <tr><td className="strong">Currency</td><td>{org.currency_code} {org.currency_symbol}</td></tr>
              <tr><td className="strong">Date format</td><td className="mono">{org.date_format}</td></tr>
              <tr><td className="strong">Timezone</td><td className="mono">{org.timezone}</td></tr>
            </tbody>
          </table>
        </Card>
      </div>

      <div style={{ marginTop: 20 }}>
        <Card title="Build status">
          <p style={{ margin: '0 0 14px', color: 'var(--body)' }}>
            Phase 1 (Foundation) is complete. The tables for every later phase already
            exist — those phases add API endpoints and screens, not schema changes.
          </p>
          <table>
            <thead>
              <tr><th>Phase</th><th>Scope</th><th>Status</th></tr>
            </thead>
            <tbody>
              <tr>
                <td className="strong">1 · Foundation</td>
                <td>Auth, RBAC, tenancy, audit, admin console</td>
                <td><Badge tone="ok">built</Badge></td>
              </tr>
              <tr>
                <td className="strong">2 · Core healthcare</td>
                <td>Patients, appointments, encounters, prescriptions</td>
                <td><Badge tone="neutral">schema only</Badge></td>
              </tr>
              <tr>
                <td className="strong">3 · Billing</td>
                <td>Services, invoices, payments, refunds</td>
                <td><Badge tone="neutral">schema only</Badge></td>
              </tr>
              <tr>
                <td className="strong">4 · Patient app</td>
                <td>React Native mobile client</td>
                <td><Badge tone="neutral">not started</Badge></td>
              </tr>
              <tr>
                <td className="strong">5 · Insurance</td>
                <td>Policies, claims, resubmission</td>
                <td><Badge tone="neutral">schema only</Badge></td>
              </tr>
              <tr>
                <td className="strong">6 · AI</td>
                <td>Billing, documentation and claim assistants</td>
                <td><Badge tone="neutral">not started</Badge></td>
              </tr>
            </tbody>
          </table>
        </Card>
      </div>
    </>
  )
}
