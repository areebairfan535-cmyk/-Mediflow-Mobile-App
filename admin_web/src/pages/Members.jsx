import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, Empty, ErrorBox, when } from '../components.jsx'

export default function Members({ session }) {
  const [state, setState] = useState({ loading: true })
  const [notice, setNotice] = useState(null)
  const [saving, setSaving] = useState(null)

  const canEdit = session.can('member.update')

  async function load() {
    setState({ loading: true })
    try {
      const [members, roles] = await Promise.all([api.members(), api.roles()])
      setState({ loading: false, members: members.data.members, roles: roles.data.roles })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => {
    load()
  }, [])

  async function changeRole(userId, roleId) {
    setSaving(userId)
    setNotice(null)
    try {
      await api.changeMemberRole(userId, Number(roleId))
      setNotice({ ok: true, message: 'Role updated.' })
      await load()
    } catch (error) {
      // The API refuses to demote the last owner; surface that verbatim.
      setNotice({ ok: false, message: error.message })
    } finally {
      setSaving(null)
    }
  }

  async function toggleStatus(member) {
    const next = member.status === 'active' ? 'disabled' : 'active'
    setSaving(member.user_id)
    setNotice(null)
    try {
      await api.changeMemberStatus(member.user_id, next)
      setNotice({ ok: true, message: `${member.name} is now ${next}.` })
      await load()
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    } finally {
      setSaving(null)
    }
  }

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Team</h1>
          <p>Everyone with access to this organization, and the role that decides what they can do.</p>
        </div>
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      <Card title={`${state.members.length} members`} bodyless>
        {state.members.length === 0 ? (
          <Empty icon="👥" title="No members yet" />
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Job title</th>
                  <th>Status</th>
                  <th>Joined</th>
                  {canEdit && <th />}
                </tr>
              </thead>
              <tbody>
                {state.members.map((m) => (
                  <tr key={m.user_id}>
                    <td className="strong">{m.name}</td>
                    <td className="mono">{m.email}</td>
                    <td>
                      {canEdit ? (
                        <select
                          value={m.role_id}
                          disabled={saving === m.user_id}
                          onChange={(e) => changeRole(m.user_id, e.target.value)}
                          style={{ width: 'auto', padding: '5px 8px', fontSize: 13 }}
                        >
                          {state.roles.map((r) => (
                            <option key={r.id} value={r.id}>{r.name}</option>
                          ))}
                        </select>
                      ) : (
                        <Badge tone="accent">{m.role_slug}</Badge>
                      )}
                    </td>
                    <td>{m.job_title || '—'}</td>
                    <td><Badge>{m.status}</Badge></td>
                    <td>{when(m.joined_at)}</td>
                    {canEdit && (
                      <td>
                        <button
                          className="btn btn-sm btn-secondary"
                          disabled={saving === m.user_id}
                          onClick={() => toggleStatus(m)}
                        >
                          {m.status === 'active' ? 'Disable' : 'Enable'}
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {!canEdit && (
        <p style={{ color: 'var(--muted)', fontSize: 13, marginTop: 12 }}>
          Your role does not hold <code>member.update</code>, so roles and status are read-only here.
        </p>
      )}
    </>
  )
}
