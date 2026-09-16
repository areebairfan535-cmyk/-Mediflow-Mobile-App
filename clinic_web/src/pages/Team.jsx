import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, Empty, ErrorBox, dateOf } from '../components.jsx'

/**
 * Who works here, and who is asking to (§2, §9).
 *
 * Applications sit at the top with Approve and Reject, because that is the
 * one thing on this page that is waiting on the reader. The rest of the team
 * is a list: role, status, when they joined. Disabling is the reversible
 * way to take someone off the roster; the API keeps their history either way.
 */
export default function Team({ session }) {
  const [state, setState] = useState({ loading: true })
  const [busy, setBusy] = useState(null)
  const [notice, setNotice] = useState(null)

  async function load() {
    setState((s) => ({ ...s, loading: !s.members }))
    try {
      const res = await api.members()
      setState({ loading: false, members: res.data.members || [] })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load() }, [])

  async function setStatus(member, status) {
    let reason
    if (status === 'rejected') {
      reason = window.prompt('Why not? The applicant will read this.')
      if (reason === null) return
      if (reason.trim() === '') { setNotice({ ok: false, message: 'A rejection needs a reason.' }); return }
    }
    setBusy(member.user_id)
    setNotice(null)
    try {
      await api.setMemberStatus(member.user_id, status, reason)
      setNotice({
        ok: true,
        message: status === 'active' && member.status === 'pending'
          ? `${member.name} is in. They have been told.`
          : status === 'rejected' ? `${member.name} has been told.`
            : `${member.name} is now ${status}.`,
      })
      await load()
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    } finally {
      setBusy(null)
    }
  }

  if (state.loading) return <Loading />
  if (state.error) return <ErrorBox error={state.error} onRetry={load} />

  const canManage = session.can('member.update')
  const pending = state.members.filter((m) => m.status === 'pending')
  const team = state.members.filter((m) => m.status !== 'pending')

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Team</h1>
          <p>Everyone with a login at this clinic, and anyone waiting to join.</p>
        </div>
      </div>

      {notice && <div className={notice.ok ? 'alert alert-ok' : 'alert'}>{notice.message}</div>}

      {pending.length > 0 && (
        <div style={{ marginBottom: 20 }}>
          <Card title={`${pending.length} waiting to join`} bodyless>
            {pending.map((m) => (
              <div className="slot-row" key={m.user_id}>
                <div className="slot-main">
                  <div className="who">
                    {m.name} <span className="hint">· {m.role_name}</span>
                  </div>
                  <div className="why">
                    {m.email}{m.phone ? ` · ${m.phone}` : ''}
                    {m.doctor_specialty ? ` · ${m.doctor_specialty}` : ''}
                    {m.doctor_qualification ? ` · ${m.doctor_qualification}` : ''}
                    {m.doctor_experience_years != null ? ` · ${m.doctor_experience_years} yrs` : ''}
                  </div>
                  <div className="why hint">Applied {dateOf(m.created_at)}</div>
                </div>
                <Badge tone="warn">awaiting approval</Badge>
                {canManage && (
                  <div className="slot-actions">
                    <button className="btn btn-sm" disabled={busy === m.user_id}
                            onClick={() => setStatus(m, 'active')}>Approve</button>
                    <button className="btn btn-sm btn-secondary" disabled={busy === m.user_id}
                            onClick={() => setStatus(m, 'rejected')}>Reject</button>
                  </div>
                )}
              </div>
            ))}
          </Card>
        </div>
      )}

      <Card title={`${team.length} on the team`} bodyless>
        {team.length === 0 ? (
          <Empty icon="👥" title="Nobody yet" />
        ) : (
          team.map((m) => (
            <div className="slot-row" key={m.user_id}>
              <div className="slot-main">
                <div className="who">
                  {m.name} <span className="hint">· {m.role_name}</span>
                </div>
                <div className="why">
                  {m.email}{m.phone ? ` · ${m.phone}` : ''}
                  {m.doctor_specialty ? ` · ${m.doctor_specialty}` : ''}
                  {m.joined_at ? ` · joined ${dateOf(m.joined_at)}` : ''}
                </div>
              </div>
              <Badge tone={m.status === 'active' ? 'ok' : m.status === 'rejected' ? 'danger' : 'warn'}>
                {m.status}
              </Badge>
              {canManage && m.user_id !== session.user.id && (
                <div className="slot-actions">
                  {m.status === 'active' ? (
                    <button className="btn btn-sm btn-secondary" disabled={busy === m.user_id}
                            onClick={() => setStatus(m, 'disabled')}>Disable</button>
                  ) : m.status === 'disabled' ? (
                    <button className="btn btn-sm btn-secondary" disabled={busy === m.user_id}
                            onClick={() => setStatus(m, 'active')}>Enable</button>
                  ) : null}
                </div>
              )}
            </div>
          ))
        )}
      </Card>
    </>
  )
}
