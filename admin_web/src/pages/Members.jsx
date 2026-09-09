import { Fragment, useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, Empty, ErrorBox, when } from '../components.jsx'

/** The employment fields, in the order a form asks for them. */
const STAFF_FIELDS = [
  { key: 'employee_no', label: 'Employee no.', placeholder: 'EMP-0001' },
  { key: 'department', label: 'Department', placeholder: 'Front desk' },
  { key: 'designation', label: 'Designation', placeholder: 'Office manager' },
  { key: 'hired_at', label: 'Started', type: 'date' },
]

export default function Members({ session }) {
  const [state, setState] = useState({ loading: true })
  const [notice, setNotice] = useState(null)
  const [saving, setSaving] = useState(null)
  // Which member's employment row is open, and the values being typed into it.
  const [editing, setEditing] = useState(null)
  const [draft, setDraft] = useState({})

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

  function openStaff(member) {
    setNotice(null)
    setEditing(member.user_id)
    // Seed from what the list already carries, so the form opens filled in.
    setDraft({
      employee_no: member.employee_no || '',
      department: member.department || '',
      designation: member.designation || '',
      hired_at: member.hired_at || '',
    })
  }

  async function saveStaff(userId) {
    setSaving(userId)
    setNotice(null)
    try {
      await api.saveStaffProfile(userId, draft)
      setNotice({ ok: true, message: 'Employment details saved.' })
      setEditing(null)
      await load()
    } catch (error) {
      // A duplicate employee number comes back as a 409 with a plain reason.
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
                  <th>Employee no.</th>
                  <th>Department</th>
                  <th>Started</th>
                  <th>Status</th>
                  <th>Joined</th>
                  {canEdit && <th />}
                </tr>
              </thead>
              <tbody>
                {state.members.map((m) => (
                  <Fragment key={m.user_id}>
                  <tr>
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
                    <td className="mono">{m.employee_no || '—'}</td>
                    <td>{m.department || '—'}</td>
                    <td>{m.hired_at ? when(m.hired_at) : '—'}</td>
                    <td><Badge>{m.status}</Badge></td>
                    <td>{when(m.joined_at)}</td>
                    {canEdit && (
                      <td style={{ whiteSpace: 'nowrap' }}>
                        <button
                          className="btn btn-sm btn-secondary"
                          disabled={saving === m.user_id}
                          onClick={() => (editing === m.user_id ? setEditing(null) : openStaff(m))}
                        >
                          {editing === m.user_id ? 'Close' : 'Employment'}
                        </button>{' '}
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

                  {canEdit && editing === m.user_id && (
                    <tr>
                      <td colSpan={9} style={{ background: 'var(--sunken, #fafafa)' }}>
                        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
                          {STAFF_FIELDS.map((f) => (
                            <label key={f.key} style={{ fontSize: 12, color: 'var(--muted)' }}>
                              {f.label}
                              <input
                                type={f.type || 'text'}
                                value={draft[f.key] || ''}
                                placeholder={f.placeholder || ''}
                                onChange={(e) => setDraft({ ...draft, [f.key]: e.target.value })}
                                style={{ display: 'block', marginTop: 4, padding: '6px 8px', fontSize: 13 }}
                              />
                            </label>
                          ))}
                          <button
                            className="btn btn-sm"
                            disabled={saving === m.user_id}
                            onClick={() => saveStaff(m.user_id)}
                          >
                            {saving === m.user_id ? 'Saving…' : 'Save'}
                          </button>
                        </div>
                        <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 8, marginBottom: 0 }}>
                          Employment details, not access. Changing someone's role
                          leaves these alone, and they are removed when the person
                          leaves the organization.
                        </p>
                      </td>
                    </tr>
                  )}
                  </Fragment>
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
