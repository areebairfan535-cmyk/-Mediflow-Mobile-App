import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { Card, Badge, Loading, Empty, ErrorBox, Modal, dateOf } from '../components.jsx'

export default function Patients({ session, go }) {
  const [search, setSearch] = useState('')
  const [state, setState] = useState({ loading: true })
  const [showForm, setShowForm] = useState(false)

  async function load(term = search) {
    setState((s) => ({ ...s, loading: true }))
    try {
      const res = await api.patients({ search: term, per_page: 50 })
      setState({ loading: false, rows: res.data, meta: res.meta })
    } catch (error) {
      setState({ loading: false, error })
    }
  }

  useEffect(() => { load('') }, [])

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Patients</h1>
          <p>Search by name, MRN or phone.</p>
        </div>
        {session.can('patient.create') && (
          <button className="btn" onClick={() => setShowForm(true)}>Register patient</button>
        )}
      </div>

      <Card>
        <form className="row" onSubmit={(e) => { e.preventDefault(); load() }}>
          <input value={search} onChange={(e) => setSearch(e.target.value)}
                 placeholder="Name, MRN or phone…" style={{ flex: 1, minWidth: 200 }} />
          <button className="btn">Search</button>
          <button type="button" className="btn btn-secondary"
                  onClick={() => { setSearch(''); load('') }}>Reset</button>
        </form>
      </Card>

      <div style={{ marginTop: 16 }}>
        {state.loading ? (
          <Loading />
        ) : state.error ? (
          <ErrorBox error={state.error} onRetry={() => load()} />
        ) : state.rows.length === 0 ? (
          <Card bodyless><Empty icon="🧑‍⚕️" title="No patients found"
                                hint={search ? 'Try a different search.' : 'Register the first one.'} /></Card>
        ) : (
          <Card title={`${state.meta.total} patients`} bodyless>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>MRN</th><th>Name</th><th>Age</th><th>Gender</th>
                    <th>Phone</th><th>Visits</th><th>Last visit</th><th>Status</th><th />
                  </tr>
                </thead>
                <tbody>
                  {state.rows.map((p) => (
                    <tr key={p.id}>
                      <td className="mono">{p.mrn}</td>
                      <td className="strong">{p.first_name} {p.last_name}</td>
                      <td>{p.age ?? '—'}</td>
                      <td>{p.gender}</td>
                      <td className="mono">{p.phone || '—'}</td>
                      <td className="mono">{p.visit_count}</td>
                      <td>{p.last_visit_at ? dateOf(p.last_visit_at) : '—'}</td>
                      <td><Badge>{p.status}</Badge></td>
                      <td>
                        <button className="btn btn-sm btn-secondary"
                                onClick={() => go('chart', { patientId: p.id })}>
                          Open chart
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        )}
      </div>

      {showForm && (
        <RegisterPatient
          onClose={() => setShowForm(false)}
          onSaved={(patient) => { setShowForm(false); go('chart', { patientId: patient.id }) }}
        />
      )}
    </>
  )
}

function RegisterPatient({ onClose, onSaved }) {
  const [form, setForm] = useState({
    first_name: '', last_name: '', date_of_birth: '', gender: 'unknown',
    phone: '', email: '', blood_group: '', address: '', city: '',
    emergency_name: '', emergency_phone: '', emergency_relation: '',
  })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      // Send only what was filled in: empty strings would overwrite with blanks.
      const body = Object.fromEntries(
        Object.entries(form).filter(([, v]) => String(v).trim() !== ''),
      )
      const res = await api.createPatient(body)
      onSaved(res.data.patient)
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      title="Register patient"
      onClose={onClose}
      footer={
        <>
          <button className="btn btn-secondary" onClick={onClose}>Cancel</button>
          <button className="btn" form="patient-form" disabled={busy}>
            {busy ? 'Saving…' : 'Register'}
          </button>
        </>
      }
    >
      <form id="patient-form" onSubmit={submit}>
        {error && (
          <div className="alert">
            {error.message}
            {error.fieldMessages.length > 0 && (
              <ul style={{ margin: '6px 0 0 16px', padding: 0 }}>
                {error.fieldMessages.map((m, i) => <li key={i}>{m}</li>)}
              </ul>
            )}
          </div>
        )}

        <div className="grid-2">
          <div className="field">
            <label>First name *</label>
            <input value={form.first_name} onChange={set('first_name')} required />
          </div>
          <div className="field">
            <label>Last name *</label>
            <input value={form.last_name} onChange={set('last_name')} required />
          </div>
        </div>

        <div className="grid-3">
          <div className="field">
            <label>Date of birth</label>
            <input type="date" value={form.date_of_birth} onChange={set('date_of_birth')} />
          </div>
          <div className="field">
            <label>Gender</label>
            <select value={form.gender} onChange={set('gender')}>
              <option value="unknown">Unknown</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div className="field">
            <label>Blood group</label>
            <input value={form.blood_group} onChange={set('blood_group')} placeholder="O+" />
          </div>
        </div>

        <div className="grid-2">
          <div className="field">
            <label>Phone</label>
            <input value={form.phone} onChange={set('phone')} placeholder="0300-1234567" />
          </div>
          <div className="field">
            <label>Email</label>
            <input type="email" value={form.email} onChange={set('email')} />
          </div>
        </div>

        <div className="grid-2">
          <div className="field">
            <label>Address</label>
            <input value={form.address} onChange={set('address')} />
          </div>
          <div className="field">
            <label>City</label>
            <input value={form.city} onChange={set('city')} />
          </div>
        </div>

        <div className="grid-3">
          <div className="field">
            <label>Emergency contact</label>
            <input value={form.emergency_name} onChange={set('emergency_name')} />
          </div>
          <div className="field">
            <label>Their phone</label>
            <input value={form.emergency_phone} onChange={set('emergency_phone')} />
          </div>
          <div className="field">
            <label>Relation</label>
            <input value={form.emergency_relation} onChange={set('emergency_relation')}
                   placeholder="spouse" />
          </div>
        </div>

        <p className="hint">The MRN is assigned automatically.</p>
      </form>
    </Modal>
  )
}
