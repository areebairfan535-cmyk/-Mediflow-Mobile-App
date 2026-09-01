import { Fragment, useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, ErrorBox } from '../components.jsx'

/**
 * Read-only RBAC matrix. Editing custom roles is a Phase 2 concern; seeing
 * exactly what each role grants is a Phase 1 one, because it is the thing a
 * clinic owner needs before handing anyone an account.
 */
export default function Roles() {
  const [state, setState] = useState({ loading: true })
  const [open, setOpen] = useState(null)

  async function load() {
    setState({ loading: true })
    try {
      const res = await api.roles()
      setState({ loading: false, roles: res.data.roles })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => {
    load()
  }, [])

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Roles &amp; permissions</h1>
          <p>
            Permissions are <code>module.action</code> slugs. A route declares the slug it
            needs; the role either holds it or the request is refused with 403.
          </p>
        </div>
      </div>

      <Card title={`${state.roles.length} roles`} bodyless>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Role</th>
                <th>Slug</th>
                <th>Description</th>
                <th>Permissions</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {state.roles.map((role) => (
                <Fragment key={role.id}>
                  <tr>
                    <td className="strong">{role.name}</td>
                    <td><Badge tone={role.is_system ? 'accent' : 'neutral'}>{role.slug}</Badge></td>
                    <td style={{ maxWidth: 340 }}>{role.description}</td>
                    <td className="mono">{role.permissions.length}</td>
                    <td>
                      <button
                        className="btn btn-sm btn-secondary"
                        onClick={() => setOpen(open === role.id ? null : role.id)}
                      >
                        {open === role.id ? 'Hide' : 'Show'}
                      </button>
                    </td>
                  </tr>
                  {open === role.id && (
                    <tr>
                      <td colSpan={5} style={{ background: '#fafbfd' }}>
                        {role.permissions.length === 0 ? (
                          <em style={{ color: 'var(--muted)' }}>No permissions granted.</em>
                        ) : (
                          role.permissions.map((p) => (
                            <span className="perm-chip" key={p}>{p}</span>
                          ))
                        )}
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  )
}
