import { useCallback, useEffect, useState } from 'react'
import { api, tokens } from './api.js'
import { Loading, setClinicTimeZone } from './components.jsx'
import Login from './pages/Login.jsx'
import ForgotPassword from './pages/ForgotPassword.jsx'
import Dashboard from './pages/Dashboard.jsx'
import Patients from './pages/Patients.jsx'
import Services from './pages/Services.jsx'
import Search from './pages/Search.jsx'
import Account from './pages/Account.jsx'
import PatientChart from './pages/PatientChart.jsx'
import Appointments from './pages/Appointments.jsx'
import Consultation from './pages/Consultation.jsx'
import Billing from './pages/Billing.jsx'
import InvoiceDetail from './pages/InvoiceDetail.jsx'
import Claims from './pages/Claims.jsx'
import ClaimDetail from './pages/ClaimDetail.jsx'

/**
 * Nav is built from the permission list the API returns, so a receptionist and
 * a doctor see different apps from the same build. Hiding a page is a
 * courtesy — the API refuses it regardless.
 */
const NAV = [
  { key: 'dashboard', label: 'My day', icon: '◧' },
  { key: 'appointments', label: 'Appointments', icon: '📅', perm: 'appointment.view' },
  { key: 'patients', label: 'Patients', icon: '🧑‍⚕️', perm: 'patient.view' },
  { key: 'search', label: 'Search', icon: '🔍', perm: 'patient.view' },
  { key: 'billing', label: 'Billing', icon: '🧾', perm: 'invoice.view' },
  { key: 'services', label: 'Services', icon: '🏷', perm: 'service.view' },
  { key: 'claims', label: 'Claims', icon: '🛡', perm: 'claim.view' },
]

export default function App() {
  const [session, setSession] = useState(null)
  const [booting, setBooting] = useState(true)
  const [forgot, setForgot] = useState(false)
  const [route, setRoute] = useState({ page: 'dashboard' })

  const bootstrap = useCallback(async () => {
    if (!tokens.access) {
      setSession(null)
      setBooting(false)
      return
    }
    try {
      let data
      try {
        data = (await api.context()).data
      } catch {
        data = (await api.me()).data
      }

      if (!tokens.org && data.organizations?.length) {
        tokens.setOrg(data.organizations[0].organization_id)
      }

      // Register the tenant's timezone before any screen formats a time.
      setClinicTimeZone(data.active_organization?.timezone)

      const permissions = data.permissions || []
      setSession({
        user: data.user,
        organization: data.active_organization || null,
        organizations: data.organizations || [],
        role: data.role || null,
        permissions,
        can: (slug) => Boolean(data.user?.is_platform_admin) || permissions.includes(slug),
      })
    } catch {
      tokens.clear()
      setSession(null)
    } finally {
      setBooting(false)
    }
  }, [])

  useEffect(() => { bootstrap() }, [bootstrap])

  const go = useCallback((page, params = {}) => {
    setRoute({ page, ...params })
    window.scrollTo(0, 0)
  }, [])

  async function signOut() {
    try { await api.logout() } catch { /* discarding the session either way */ }
    tokens.clear()
    setSession(null)
    setRoute({ page: 'dashboard' })
  }

  if (booting) {
    return (
      <div style={{ display: 'grid', placeItems: 'center', minHeight: '100vh' }}>
        <Loading label="Loading clinic…" />
      </div>
    )
  }

  if (!session) {
    if (forgot) return <ForgotPassword onDone={() => setForgot(false)} />

    return (
      <Login
        onSignedIn={async () => { setBooting(true); await bootstrap() }}
        onForgot={() => setForgot(true)}
      />
    )
  }

  const visible = NAV.filter((n) => !n.perm || session.can(n.perm))

  // Chart and consultation are reached from a row, not the sidebar; keep the
  // nearest nav item highlighted while they are open.
  const navActive = route.page === 'chart' ? 'patients'
    : route.page === 'consultation' ? 'dashboard'
      : route.page === 'invoice' ? 'billing'
        : route.page === 'claim' ? 'claims'
      : route.page

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">
          <div className="brand-mark">M</div>
          <div className="brand-name">MediFlow</div>
        </div>

        <div className="nav-section">Clinic</div>
        {visible.map((n) => (
          <button key={n.key}
                  className={`nav-item${n.key === navActive ? ' active' : ''}`}
                  onClick={() => go(n.key)}>
            <span className="nav-icon">{n.icon}</span>
            <span>{n.label}</span>
          </button>
        ))}

        <div className="sidebar-footer">
          <button
            className="user-chip"
            onClick={() => setRoute({ page: 'account' })}
            title="Account & security"
          >
            <div className="name">{session.user.name}</div>
            <div className="meta">{session.role || 'no role'} · account</div>
          </button>
          <button className="nav-item" onClick={signOut}>
            <span className="nav-icon">⏻</span>
            <span>Sign out</span>
          </button>
        </div>
      </aside>

      <div className="main">
        <header className="topbar">
          <div className="crumb">
            {session.organization && <strong>{session.organization.name}</strong>}
          </div>
          <div className="hint">
            {session.user.is_platform_admin
              ? 'platform admin'
              : `${session.permissions.length} permissions`}
          </div>
        </header>

        <main className="content">
          {route.page === 'dashboard' && <Dashboard session={session} go={go} />}
          {route.page === 'appointments' && <Appointments session={session} go={go} />}
          {route.page === 'patients' && <Patients session={session} go={go} />}
          {route.page === 'services' && <Services session={session} />}
          {route.page === 'search' && <Search session={session} go={go} />}
          {route.page === 'account' && <Account session={session} onSignedOut={() => setSession(null)} />}
          {route.page === 'chart' && (
            <PatientChart patientId={route.patientId} session={session} go={go} />
          )}
          {route.page === 'consultation' && (
            <Consultation encounterId={route.encounterId} session={session} go={go} />
          )}
          {route.page === 'billing' && <Billing session={session} go={go} />}
          {route.page === 'invoice' && (
            <InvoiceDetail invoiceId={route.invoiceId} session={session} go={go} />
          )}
          {route.page === 'claims' && <Claims session={session} go={go} />}
          {route.page === 'claim' && (
            <ClaimDetail claimId={route.claimId} session={session} go={go} />
          )}
        </main>
      </div>
    </div>
  )
}
