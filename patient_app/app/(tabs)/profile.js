import { useCallback, useEffect, useState } from 'react'
import { Pressable, ScrollView, Text, TextInput, View } from 'react-native'
import { useFocusEffect, useRouter } from 'expo-router'
import { api, auth } from '../../src/api'
import {
  Badge, Card, ErrorBox, Loading, SectionTitle, c, s, dateOnly,
} from '../../src/ui'

/**
 * §3 profile: personal details, emergency contact, blood group, allergies,
 * conditions and insurance.
 *
 * Only contact details are editable. Clinical facts belong to the clinician —
 * the API allow-list enforces that, and this screen shows them read-only so
 * the boundary is visible rather than surprising.
 */
const EMPTY_POLICY = {
  insurance_provider_id: null, policy_number: '', member_id: '',
  policy_holder_name: '', valid_from: '', valid_to: '',
}

export default function Profile() {
  const router = useRouter()
  const [state, setState] = useState({ loading: true })
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState({})
  // Personal details are edited in their own card, so opening one editor does
  // not blank out the other half of the screen while you are reading it.
  const [editingSelf, setEditingSelf] = useState(false)
  const [selfForm, setSelfForm] = useState({})
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState(null)
  // The insurance form, or null when closed. Carries an id when editing.
  const [insuranceForm, setInsuranceForm] = useState(null)
  const [providers, setProviders] = useState([])

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: !s.p }))
    try {
      const res = await api.profile()
      const p = res.data.patient
      setState({ loading: false, p })
      setForm({
        phone: p.phone || '',
        email: p.email || '',
        address: p.address || '',
        city: p.city || '',
        emergency_name: p.emergency_name || '',
        emergency_phone: p.emergency_phone || '',
        emergency_relation: p.emergency_relation || '',
      })
      setSelfForm({
        first_name: p.first_name || '',
        last_name: p.last_name || '',
        date_of_birth: p.date_of_birth || '',
        gender: p.gender || 'unknown',
        national_id: p.national_id || '',
        national_id_expiry: p.national_id_expiry || '',
      })
    } catch (error) {
      setState({ loading: false, error })
    }
  }, [])

  useFocusEffect(useCallback(() => { load() }, [load]))

  // Insurers are a short, stable list; fetched once for the picker.
  useEffect(() => {
    api.insuranceProviders()
      .then((res) => setProviders(res.data.providers || []))
      .catch(() => {})
  }, [])

  async function saveInsurance() {
    const f = insuranceForm
    if (!f.insurance_provider_id || f.policy_number.trim() === '') {
      setNotice({ ok: false, message: 'Pick your insurer and type the policy number.' })
      return
    }
    setBusy(true)
    setNotice(null)
    try {
      const blank = (v) => (String(v || '').trim() === '' ? undefined : String(v).trim())
      const body = {
        policy_number: f.policy_number.trim(),
        member_id: blank(f.member_id),
        policy_holder_name: blank(f.policy_holder_name),
        valid_from: blank(f.valid_from),
        valid_to: blank(f.valid_to),
      }
      if (f.id) {
        await api.updateInsurance(f.id, body)
      } else {
        await api.submitInsurance({ ...body, insurance_provider_id: f.insurance_provider_id })
      }
      setNotice({ ok: true, message: 'Sent to the clinic. They will approve it after checking your card.' })
      setInsuranceForm(null)
      await load()
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    } finally {
      setBusy(false)
    }
  }

  /**
   * "Saved" is worth saying once and then getting out of the way.
   *
   * A confirmation that stays on screen stops being a confirmation — you
   * cannot tell whether it refers to what you just did or to something from
   * five minutes ago. Failures get longer on screen than successes, because
   * the user still has to act on those.
   */
  useEffect(() => {
    if (!notice) return undefined
    const timer = setTimeout(() => setNotice(null), notice.ok ? 3000 : 6000)
    return () => clearTimeout(timer)
  }, [notice])

  async function save() {
    setBusy(true)
    setNotice(null)
    try {
      await api.updateProfile(form)
      setNotice({ ok: true, message: 'Details updated.' })
      setEditing(false)
      await load()
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function saveSelf() {
    setBusy(true)
    setNotice(null)
    try {
      // An empty date is sent as null, not as "" — the column is a DATE and
      // MySQL will not accept an empty string for one.
      const blank = (v) => (String(v || '').trim() === '' ? null : String(v).trim())
      await api.updateProfile({
        ...selfForm,
        date_of_birth: blank(selfForm.date_of_birth),
        national_id: blank(selfForm.national_id),
        national_id_expiry: blank(selfForm.national_id_expiry),
      })
      setNotice({ ok: true, message: 'Your details were updated.' })
      setEditingSelf(false)
      await load()
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function signOut() {
    try { await api.logout() } catch { /* leaving either way */ }
    await auth.clear()
    router.replace('/login')
  }

  if (state.loading) return <Loading />
  if (state.error) {
    // The chart failing to load must not take the way out with it — this is
    // the only tab that has one, and a stale login is the likeliest cause.
    return (
      <ScrollView style={s.screen} contentContainerStyle={s.content}>
        <ErrorBox error={state.error} />
        <Pressable onPress={load} style={[s.btn, { backgroundColor: c.accentDark, marginTop: 16 }]}>
          <Text style={s.btnText}>Try again</Text>
        </Pressable>
        <Pressable onPress={signOut} style={[s.btnGhost, { marginTop: 10 }]}>
          <Text style={[s.btnGhostText, { color: c.accentDark, fontWeight: '700' }]}>
            Sign out
          </Text>
        </Pressable>
      </ScrollView>
    )
  }

  const p = state.p

  return (
    <ScrollView style={s.screen} contentContainerStyle={s.content}>
      {/* Same blue band as every other section, so the personal card is
          labelled rather than floating at the top unexplained. */}
      <SectionTitle
        action={
          <Pressable onPress={() => setEditingSelf(!editingSelf)}>
            <Text style={{ color: '#fff', fontWeight: '700', fontSize: 13.5 }}>
              {editingSelf ? 'Cancel' : 'Edit'}
            </Text>
          </Pressable>
        }
      >
        My details
      </SectionTitle>

      <Card>
        <View style={s.row}>
          <View style={{
            width: 52, height: 52, borderRadius: 26, backgroundColor: c.accentSoft,
            alignItems: 'center', justifyContent: 'center',
          }}>
            <Text style={{ fontSize: 19, fontWeight: '700', color: c.accentDark }}>
              {(p.first_name?.[0] || '') + (p.last_name?.[0] || '')}
            </Text>
          </View>
          <View style={{ flex: 1 }}>
            {/* Whose record this is — the first thing to read on the screen. */}
            <Text style={{ fontSize: 20, fontWeight: '800', color: c.accentDark, letterSpacing: -0.3 }}>
              {p.first_name} {p.last_name}
            </Text>
            {/* Labelled, because on its own "P-000001" reads as noise. It is
                the number the clinic asks for on the phone and at reception,
                and the one printed on every invoice and prescription.
                Label and number share a size so the line does not wobble. */}
            <View style={[s.row, { marginTop: 3, gap: 6 }]}>
              <Text style={{
                fontSize: 10.5, fontWeight: '700', color: c.muted,
                textTransform: 'uppercase', letterSpacing: 0.6,
              }}>
                Patient ID
              </Text>
              <Text style={{
                fontFamily: 'monospace', fontSize: 13, fontWeight: '700', color: c.ink,
              }}>
                {p.mrn}
              </Text>
            </View>
          </View>
        </View>

        {editingSelf ? (
          <View style={{ marginTop: 10 }}>
            {[
              ['first_name', 'First name'],
              ['last_name', 'Last name'],
              ['date_of_birth', 'Date of birth (YYYY-MM-DD)'],
              ['national_id', 'ID card number'],
              ['national_id_expiry', 'ID card expiry (YYYY-MM-DD)'],
            ].map(([key, label]) => (
              <View key={key}>
                <Text style={s.label}>{label}</Text>
                <TextInput
                  style={s.input}
                  value={selfForm[key]}
                  onChangeText={(v) => setSelfForm({ ...selfForm, [key]: v })}
                  autoCapitalize="words"
                  placeholder={
                    key === 'date_of_birth' ? '1994-03-12'
                      : key === 'national_id' ? '33100-1234567-1'
                        : key === 'national_id_expiry' ? '2031-05-20' : ''
                  }
                />
              </View>
            ))}

            <Text style={s.label}>Gender</Text>
            <View style={[s.row, { flexWrap: 'wrap', marginTop: 2 }]}>
              {['male', 'female', 'other', 'unknown'].map((g) => (
                <Pressable
                  key={g}
                  onPress={() => setSelfForm({ ...selfForm, gender: g })}
                  style={{
                    paddingVertical: 7, paddingHorizontal: 14, borderRadius: 999,
                    borderWidth: 1, marginRight: 8, marginTop: 6,
                    borderColor: selfForm.gender === g ? c.accentDark : c.border,
                    backgroundColor: selfForm.gender === g ? c.accentSoft : c.surface,
                  }}
                >
                  <Text style={{
                    fontSize: 13.5,
                    fontWeight: selfForm.gender === g ? '700' : '500',
                    color: selfForm.gender === g ? c.accentDark : c.body,
                  }}>
                    {g}
                  </Text>
                </Pressable>
              ))}
            </View>

            <Text style={[s.muted, { marginTop: 14, fontSize: 11.5 }]}>
              Blood group is a test result, not a detail you set — ask the clinic
              to correct it.
            </Text>

            <Pressable onPress={saveSelf} disabled={busy}
                       style={[s.btn, { marginTop: 14 }, busy && s.btnDisabled]}>
              <Text style={s.btnText}>{busy ? 'Saving…' : 'Save'}</Text>
            </Pressable>
          </View>
        ) : (
          <>
            <View style={{ marginTop: 14 }}>
              <Field label="Date of birth" value={p.date_of_birth ? dateOnly(p.date_of_birth) : '—'} />
              <Field label="Age" value={p.age != null ? `${p.age} years` : '—'} />
              <Field label="Gender" value={p.gender} />
              <Field label="Blood group" value={p.blood_group || '—'} />
              <Field label="ID card" value={p.national_id || '—'} />
              <Field
                label="ID card expiry"
                value={p.national_id_expiry ? dateOnly(p.national_id_expiry) : '—'}
                badge={expiryBadge(p.national_id_expiry)}
              />
            </View>
            {/* Says what this card does NOT cover, so the missing Edit on
                allergies and conditions reads as a rule, not an oversight. */}
            <Text style={[s.muted, { fontSize: 11.5 }]}>
              Allergies and conditions are maintained by your clinic. Insurance you add is checked by them first.
            </Text>
          </>
        )}
      </Card>

      {notice && (
        <View style={[
          s.errorBox,
          notice.ok && { backgroundColor: c.okSoft, borderColor: 'rgba(15,138,95,0.2)' },
        ]}>
          <Text style={[s.errorText, notice.ok && { color: c.ok }]}>{notice.message}</Text>
        </View>
      )}

      <SectionTitle
        action={
          <Pressable onPress={() => setEditing(!editing)}>
            <Text style={{ color: '#fff', fontWeight: '700', fontSize: 13.5 }}>
              {editing ? 'Cancel' : 'Edit'}
            </Text>
          </Pressable>
        }
      >
        Contact details
      </SectionTitle>

      <Card>
        {editing ? (
          <>
            {[
              ['phone', 'Phone'],
              ['email', 'Email'],
              ['address', 'Address'],
              ['city', 'City'],
              ['emergency_name', 'Emergency contact'],
              ['emergency_relation', 'Relation'],
            ].map(([key, label]) => (
              <View key={key}>
                <Text style={s.label}>{label}</Text>
                <TextInput
                  style={s.input}
                  value={form[key]}
                  onChangeText={(v) => setForm({ ...form, [key]: v })}
                  autoCapitalize={key === 'email' ? 'none' : 'sentences'}
                  keyboardType={key.includes('phone') ? 'phone-pad'
                    : key === 'email' ? 'email-address' : 'default'}
                />
              </View>
            ))}
            <Pressable onPress={save} disabled={busy}
                       style={[s.btn, { marginTop: 18 }, busy && s.btnDisabled]}>
              <Text style={s.btnText}>{busy ? 'Saving…' : 'Save'}</Text>
            </Pressable>
          </>
        ) : (
          <>
            <Field label="Phone" value={p.phone || '—'} />
            <Field label="Email" value={p.email || '—'} />
            <Field label="Address" value={p.address || '—'} />
            <Field label="City" value={p.city || '—'} />
            <Field label="Emergency" value={p.emergency_name || '—'} />
            <Field label="Relation" value={p.emergency_relation || '—'} />
          </>
        )}
      </Card>

      <SectionTitle>Allergies</SectionTitle>
      <Card>
        {(p.allergies || []).length === 0 ? (
          <Text style={s.body}>No known allergies on file.</Text>
        ) : (
          p.allergies.map((a) => (
            <View key={a.id} style={[s.spread, { marginTop: 6 }]}>
              <View style={{ flex: 1 }}>
                <Text style={s.itemName}>{a.substance}</Text>
                {a.reaction ? <Text style={s.muted}>{a.reaction}</Text> : null}
              </View>
              <Badge tone={['severe', 'life_threatening'].includes(a.severity) ? 'danger' : 'warn'}>
                {a.severity}
              </Badge>
            </View>
          ))
        )}
      </Card>

      <SectionTitle>Medical conditions</SectionTitle>
      <Card>
        {(p.conditions || []).length === 0 ? (
          <Text style={s.body}>None recorded.</Text>
        ) : (
          p.conditions.map((cond) => (
            <View key={cond.id} style={[s.spread, { marginTop: 6 }]}>
              <View style={{ flex: 1 }}>
                <Text style={s.itemName}>{cond.name}</Text>
                {cond.diagnosed_on ? (
                  <Text style={s.muted}>Since {dateOnly(cond.diagnosed_on)}</Text>
                ) : null}
              </View>
              <Badge>{cond.status}</Badge>
            </View>
          ))
        )}
      </Card>

      <SectionTitle
        action={
          <Pressable onPress={() => setInsuranceForm(insuranceForm ? null : { ...EMPTY_POLICY })}>
            <Text style={{ color: '#fff', fontWeight: '700', fontSize: 13.5 }}>
              {insuranceForm ? 'Cancel' : 'Add'}
            </Text>
          </Pressable>
        }
      >
        Insurance
      </SectionTitle>
      <Card>
        {insuranceForm ? (
          <InsuranceForm
            form={insuranceForm}
            setForm={setInsuranceForm}
            providers={providers}
            busy={busy}
            onSave={saveInsurance}
          />
        ) : (p.insurance || []).length === 0 ? (
          <Text style={s.body}>
            No policy on file. Add yours and the clinic will check it before it
            is used for billing.
          </Text>
        ) : (
          p.insurance.map((pol, i) => (
            <View key={pol.id} style={{ marginTop: i === 0 ? 0 : 12 }}>
              <View style={s.spread}>
                <Text style={s.itemName}>{pol.provider_name}</Text>
                <View style={[s.row, { gap: 6 }]}>
                  {pol.status === 'pending' ? <Badge tone="warn">awaiting approval</Badge>
                    : pol.status === 'rejected' ? <Badge tone="danger">not accepted</Badge>
                      : pol.status === 'active' ? (expiryBadge(pol.valid_to) || <Badge tone="ok">active</Badge>)
                        : <Badge>{pol.status}</Badge>}
                </View>
              </View>
              <Text style={s.docNo}>
                Policy {pol.policy_number}{pol.member_id ? ` · Member ${pol.member_id}` : ''}
              </Text>
              <Text style={s.muted}>
                {pol.coverage_type || 'Coverage'}
                {pol.valid_to ? ` · expires ${dateOnly(pol.valid_to)}` : ' · no expiry given'}
              </Text>
              {pol.status === 'rejected' && pol.review_note ? (
                <Text style={[s.muted, { color: c.danger, marginTop: 2 }]}>{pol.review_note}</Text>
              ) : null}
              {/* Only what the patient put in is theirs to correct. */}
              {pol.submitted_by ? (
                <Pressable
                  onPress={() => setInsuranceForm({
                    id: pol.id,
                    insurance_provider_id: pol.insurance_provider_id,
                    policy_number: pol.policy_number || '',
                    member_id: pol.member_id || '',
                    policy_holder_name: pol.policy_holder_name || '',
                    valid_from: pol.valid_from || '',
                    valid_to: pol.valid_to || '',
                  })}
                  style={{ marginTop: 4 }}
                >
                  <Text style={{ color: c.accentDark, fontWeight: '700', fontSize: 13 }}>Edit</Text>
                </Pressable>
              ) : null}
            </View>
          ))
        )}
      </Card>

      {/* The account — password and devices — lives on its own screen. It is
          not clinical, and a patient hunting for "sign out everywhere" should
          not have to scroll past their allergies to find it. */}
      <Pressable
        onPress={() => router.push('/account')}
        style={[s.btn, { backgroundColor: c.accentDark, marginTop: 26 }]}
      >
        <Text style={s.btnText}>Account & security</Text>
      </Pressable>

      <Pressable onPress={signOut} style={[s.btnGhost, { marginTop: 10 }]}>
        <Text style={[s.btnGhostText, { color: c.accentDark, fontWeight: '700' }]}>
          Sign out
        </Text>
      </Pressable>
    </ScrollView>
  )
}

/**
 * One "question and answer" row — Date of birth, Phone, Blood group.
 *
 * The label was body-grey and the value near-black, which read as though the
 * questions were the faint background to the answers. On a profile screen both
 * halves are being scanned, so the label is ink and bold now too; the value
 * stays a shade heavier so the pair still has a direction to it.
 */
function Field({ label, value, badge }) {
  return (
    <View style={[s.spread, { marginTop: 7 }]}>
      {/* The question carries the weight; the answer sits back. */}
      <Text style={{ color: c.ink, fontWeight: '700', fontSize: 14.5 }}>{label}</Text>
      <View style={[s.row, { gap: 8 }]}>
        <Text style={{ color: c.body, fontWeight: '500', fontSize: 14.5 }}>{value}</Text>
        {badge}
      </View>
    </View>
  )
}

/**
 * How a dated thing is doing — an ID card, an insurance policy. Said before
 * it bites: an expired card is not identification, and an expiring one is
 * the reminder to renew while it is still convenient.
 */
export function expiryBadge(date) {
  if (!date) return null
  const days = Math.ceil((new Date(String(date).slice(0, 10) + 'T00:00:00') - new Date()) / 86400000)
  if (days < 0) return <Badge tone="danger">expired</Badge>
  if (days <= 30) return <Badge tone="warn">{days === 0 ? 'expires today' : `${days} days left`}</Badge>
  return null
}

/**
 * The boxes a policy is entered through. The insurer is a row of chips
 * rather than a dropdown — the list is four or five names, and chips need
 * no native picker to look the same on both platforms.
 */
function InsuranceForm({ form, setForm, providers, busy, onSave }) {
  const set = (key) => (v) => setForm({ ...form, [key]: v })
  return (
    <View>
      <Text style={s.label}>Insurer</Text>
      <View style={[s.row, { flexWrap: 'wrap', marginTop: 2 }]}>
        {providers.map((prov) => {
          const on = form.insurance_provider_id === prov.id
          return (
            <Pressable
              key={prov.id}
              disabled={Boolean(form.id)}
              onPress={() => set('insurance_provider_id')(prov.id)}
              style={{
                paddingVertical: 7, paddingHorizontal: 14, borderRadius: 999,
                borderWidth: 1, marginRight: 8, marginTop: 6,
                borderColor: on ? c.accentDark : c.border,
                backgroundColor: on ? c.accentSoft : c.surface,
                opacity: form.id && !on ? 0.5 : 1,
              }}
            >
              <Text style={{
                fontSize: 13.5, fontWeight: on ? '700' : '500',
                color: on ? c.accentDark : c.body,
              }}>
                {prov.name}
              </Text>
            </Pressable>
          )
        })}
      </View>

      {[
        ['policy_number', 'Policy number', 'As printed on your card'],
        ['member_id', 'Member ID (optional)', ''],
        ['policy_holder_name', 'Policy holder (optional)', 'If the policy is in someone else\'s name'],
        ['valid_from', 'Valid from (YYYY-MM-DD, optional)', '2026-01-01'],
        ['valid_to', 'Expiry date (YYYY-MM-DD)', '2027-12-31'],
      ].map(([key, label, placeholder]) => (
        <View key={key}>
          <Text style={s.label}>{label}</Text>
          <TextInput
            style={s.input}
            value={form[key] ?? ''}
            onChangeText={set(key)}
            autoCapitalize={key === 'policy_holder_name' ? 'words' : 'characters'}
            autoCorrect={false}
            placeholder={placeholder}
            placeholderTextColor={c.muted}
          />
        </View>
      ))}

      <Text style={[s.muted, { marginTop: 10, fontSize: 11.5 }]}>
        The clinic checks this against your card before it is used for billing.
      </Text>

      <Pressable onPress={onSave} disabled={busy}
                 style={[s.btn, { marginTop: 14 }, busy && s.btnDisabled]}>
        <Text style={s.btnText}>
          {busy ? 'Sending…' : form.id ? 'Save changes' : 'Send to clinic'}
        </Text>
      </Pressable>
    </View>
  )
}
