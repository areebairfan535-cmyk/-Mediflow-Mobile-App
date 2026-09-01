import { useCallback, useEffect, useState } from 'react'
import { api, tokens } from './api.js'
import { Loading } from './components.jsx'
import Login from './pages/Login.jsx'
import SignUp from './pages/SignUp.jsx'
import ForgotPassword from './pages/ForgotPassword.jsx'
import Overview from './pages/Overview.jsx'
import Members from './pages/Members.jsx'
import Roles from './pages/Roles.jsx'
import AuditLogs from './pages/AuditLogs.jsx'
import Account from './pages/Account.jsx'
import Subscription from './pages/Subscription.jsx'
import Platform from './pages/Platform.jsx'
import PlatformConfig from './pages/PlatformConfig.jsx'

/**
 * Nav is driven by the permission list the API returns, not by hard-coded
 * role names. A page the user cannot use is not shown — and the API refuses
 * it anyway, so hiding it is a courtesy, never the control.
 */
const PAGES = [
  { key: 'overview', label: 'Overview', icon: '◧', section: 'Organization' },
  { key: 'members', label: 'Team', icon: '👥', section: 'Organization', perm: 'member.view' },
  { key: 'roles', label: 'Roles', icon: '🔑', section: 'Organization', perm: 'member.view' },
  { key: 'plan', label: 'Plan & usage', icon: '◈', section: 'Organization', perm: 'member.view' },
  { key: 'audit', label: 'Audit log', icon: '🗒', section: 'Security', perm: 'audit.view' },
  { key: 'account', label: 'Account & security', icon: '🔐', section: 'Security' },
  { key: 'platform', label: 'All tenants', icon: '🏥', section: 'Platform', platformOnly: true },
  { key: 'catalogue', label: 'Plans & markets', icon: '🌍', section: 'Platform', platformOnly: true },
]

export default function App() {
  const [session, setSession] = useState(null)
  const [booting, setBooting] = useState(true)
  const [page, setPage] = useState('overview')
  const [showSignUp, setShowSignUp] = useState(false)
  const [forgot, setForgot] = useState(false)

  /**
   * Rehydrate from the stored token. /me/context is tenant-scoped and returns
   * the permission set; a platform admin with no membership falls back to /me.
   */
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

      // A user with several memberships and no stored choice: take the first.
      if (!tokens.org && data.organizations?.length) {
        tokens.setOrg(data.organizations[0].organization_id)
      }

      setSession({
        user: data.user,
        organizations: data.organizations || [],
        organization: data.active_organization || null,
        role: data.role || null,
        permissions: data.permissions || [],
        can: (slug) => Boolean(data.user?.is_platform_admin) || (data.permissions || []).includes(slug),
      })
    } catch {
      tokens.clear()
      setSession(null)
    } finally {
      setBooting(false)
    }
  }, [])

  useEffect(() => {
    bootstrap()
  }, [bootstrap])

  async function signOut() {
    try {
      await api.logout()
    } catch {
      /* the local session is being discarded either way */
    }
    tokens.clear()
    setSession(null)
    setPage('overview')
  }

  async function switchOrganization(id) {
    tokens.setOrg(Number(id))
    setBooting(true)
    await bootstrap()
  }

  if (booting) {
    return (
      <div style={{ display: 'grid', placeItems: 'center', minHeight: '100vh' }}>
        <Loading label="Loading console…" />
      </div>
    )
  }

  if (!session) {
    const signedIn = async () => {
      setBooting(true)
      await bootstrap()
    }

    if (forgot) return <ForgotPassword onDone={() => setForgot(false)} />

    return showSignUp
      ? <SignUp onSignedIn={signedIn} onCancel={() => setShowSignUp(false)} />
      : (
        <Login
          onSignedIn={signedIn}
          onCreateAccount={() => setShowSignUp(true)}
          onForgot={() => setForgot(true)}
        />
      )
  }

  const isPlatformAdmin = Boolean(session.user.is_platform_admin)

  const visible = PAGES.filter((p) => {
    if (p.platformOnly) return isPlatformAdmin
    if (p.perm) return session.can(p.perm)
    return true
  })

  // A platform admin with no membership has no tenant pages to show.
  const active = visible.some((p) => p.key === page) ? page : visible[0]?.key
  const current = PAGES.find((p) => p.key === active)

  const sections = [...new Set(visible.map((p) => p.section))]

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">
          <div className="brand-mark">M</div>
          <div className="brand-name">MediFlow</div>
        </div>

        {sections.map((section) => (
          <div key={section}>
            <div className="nav-section">{section}</div>
            {visible
              .filter((p) => p.section === section)
              .map((p) => (
                <button
                  key={p.key}
                  className={`nav-item${p.key === active ? ' active' : ''}`}
                  onClick={() => setPage(p.key)}
                >
                  <span className="nav-icon">{p.icon}</span>
                  <span>{p.label}</span>
                </button>
              ))}
          </div>
        ))}

        <div className="sidebar-footer">
          <div className="user-chip">
            <div className="name">{session.user.name}</div>
            <div className="meta">
              {isPlatformAdmin ? 'platform admin' : session.role || 'no role'}
            </div>
          </div>
          <button className="nav-item" onClick={signOut}>
            <span className="nav-icon">⏻</span>
            <span>Sign out</span>
          </button>
        </div>
      </aside>

      <div className="main">
        <header className="topbar">
          <div className="crumb">
            {session.organization ? (
              <>
                <strong>{session.organization.name}</strong> · {current?.label}
              </>
            ) : (
              <strong>{current?.label}</strong>
            )}
          </div>

          {session.organizations.length > 1 && (
            <select
              value={tokens.org ?? ''}
              onChange={(e) => switchOrganization(e.target.value)}
              style={{ width: 'auto', padding: '6px 10px', fontSize: 13 }}
            >
              {session.organizations.map((o) => (
                <option key={o.organization_id} value={o.organization_id}>
                  {o.organization_name}
                </option>
              ))}
            </select>
          )}
        </header>

        <main className="content">
          {active === 'overview' && <Overview session={session} />}
          {active === 'members' && <Members session={session} />}
          {active === 'roles' && <Roles />}
          {active === 'audit' && <AuditLogs />}
          {active === 'plan' && <Subscription session={session} />}
          {active === 'account' && <Account session={session} onSignedOut={signOut} />}
          {active === 'platform' && <Platform />}
          {active === 'catalogue' && <PlatformConfig />}
          {!active && (
            <p style={{ color: 'var(--muted)' }}>
              Your account has no organization yet. Ask an owner to invite you.
            </p>
          )}
        </main>
      </div>
    </div>
  )
}
