import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, Empty, ErrorBox, when } from '../components.jsx'

const ACTION_TONE = {
  login: 'ok',
  register: 'ok',
  create: 'ok',
  login_failed: 'danger',
  login_blocked: 'danger',
  refresh_failed: 'danger',
  delete: 'danger',
  update: 'warn',
  password_changed: 'warn',
  logout_all: 'warn',
}

export default function AuditLogs() {
  const [filters, setFilters] = useState({ action: '', resource_type: '', from: '', to: '' })
  const [page, setPage] = useState(1)
  const [state, setState] = useState({ loading: true })

  async function load() {
    setState((s) => ({ ...s, loading: true }))
    try {
      const res = await api.auditLogs({ ...filters, page, per_page: 25 })
      setState({ loading: false, entries: res.data, meta: res.meta })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page])

  function applyFilters(e) {
    e.preventDefault()
    if (page === 1) load()
    else setPage(1)
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Audit log</h1>
          <p>
            Append-only trail of who did what, from where. Passwords and tokens are
            redacted before a row is written.
          </p>
        </div>
      </div>

      <Card>
        <form className="filters" onSubmit={applyFilters}>
          <div className="field">
            <label>Action</label>
            <select
              value={filters.action}
              onChange={(e) => setFilters({ ...filters, action: e.target.value })}
            >
              <option value="">Any</option>
              <option value="login">login</option>
              <option value="login_failed">login_failed</option>
              <option value="logout">logout</option>
              <option value="refresh">refresh</option>
              <option value="register">register</option>
              <option value="create">create</option>
              <option value="update">update</option>
              <option value="delete">delete</option>
              <option value="view">view</option>
            </select>
          </div>
          <div className="field">
            <label>Resource</label>
            <select
              value={filters.resource_type}
              onChange={(e) => setFilters({ ...filters, resource_type: e.target.value })}
            >
              <option value="">Any</option>
              <option value="auth">auth</option>
              <option value="user">user</option>
              <option value="organization">organization</option>
              <option value="organization_member">organization_member</option>
            </select>
          </div>
          <div className="field">
            <label>From</label>
            <input type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
          </div>
          <div className="field">
            <label>To</label>
            <input type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
          </div>
          <button className="btn">Filter</button>
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() => {
              setFilters({ action: '', resource_type: '', from: '', to: '' })
              setPage(1)
            }}
          >
            Reset
          </button>
        </form>
      </Card>

      <div style={{ marginTop: 18 }}>
        {state.loading ? (
          <Loading />
        ) : state.error ? (
          <ErrorBox error={state.error} onRetry={load} />
        ) : state.entries.length === 0 ? (
          <Card bodyless><Empty icon="🗒" title="No entries match those filters" /></Card>
        ) : (
          <Card
            title={`${state.meta.total} entries`}
            bodyless
            action={
              <div className="row">
                <button
                  className="btn btn-sm btn-secondary"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => p - 1)}
                >
                  Prev
                </button>
                <span style={{ color: 'var(--muted)', fontSize: 13 }}>
                  {state.meta.page} / {state.meta.last_page}
                </span>
                <button
                  className="btn btn-sm btn-secondary"
                  disabled={page >= state.meta.last_page}
                  onClick={() => setPage((p) => p + 1)}
                >
                  Next
                </button>
              </div>
            }
          >
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>When</th>
                    <th>Who</th>
                    <th>Action</th>
                    <th>Resource</th>
                    <th>Route</th>
                    <th>IP</th>
                    <th>Changes</th>
                  </tr>
                </thead>
                <tbody>
                  {state.entries.map((row) => (
                    <tr key={row.id}>
                      <td style={{ whiteSpace: 'nowrap' }}>{when(row.created_at)}</td>
                      <td>
                        {row.user_name || <em style={{ color: 'var(--muted)' }}>anonymous</em>}
                      </td>
                      <td>
                        <Badge tone={ACTION_TONE[row.action] || 'neutral'}>{row.action}</Badge>
                      </td>
                      <td className="mono">
                        {row.resource_type}
                        {row.resource_id ? `#${row.resource_id}` : ''}
                      </td>
                      <td className="mono" style={{ fontSize: 12 }}>
                        {row.method} {row.route}
                      </td>
                      <td className="mono" style={{ fontSize: 12 }}>{row.ip_address}</td>
                      <td className="mono" style={{ fontSize: 11.5, maxWidth: 240, wordBreak: 'break-all' }}>
                        {row.new_values || <span style={{ color: 'var(--muted)' }}>—</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        )}
      </div>
    </>
  )
}
