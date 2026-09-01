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
      })
    } catch (error) {
      setState({ loading: false, error })
    }
  }, [])

  useFocusEffect(useCallback(() => { load() }, [load]))

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
      await api.updateProfile({
        ...selfForm,
        date_of_birth: selfForm.date_of_birth.trim() === '' ? null : selfForm.date_of_birth.trim(),
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
    return (
      <ScrollView style={s.screen} contentContainerStyle={s.content}>
        <ErrorBox error={state.error} />
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
            ].map(([key, label]) => (
              <View key={key}>
                <Text style={s.label}>{label}</Text>
                <TextInput
                  style={s.input}
                  value={selfForm[key]}
                  onChangeText={(v) => setSelfForm({ ...selfForm, [key]: v })}
                  autoCapitalize="words"
                  placeholder={key === 'date_of_birth' ? '1994-03-12' : ''}
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
            </View>
            {/* Says what this card does NOT cover, so the missing Edit on
                allergies and conditions reads as a rule, not an oversight. */}
            <Text style={[s.muted, { fontSize: 11.5 }]}>
              Allergies, conditions and insurance are maintained by your clinic.
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

      <SectionTitle>Insurance</SectionTitle>
      <Card>
        {(p.insurance || []).length === 0 ? (
          <Text style={s.body}>No policy on file.</Text>
        ) : (
          p.insurance.map((pol) => (
            <View key={pol.id} style={{ marginTop: 6 }}>
              <Text style={s.itemName}>{pol.provider_name}</Text>
              <Text style={s.docNo}>Policy {pol.policy_number}</Text>
              <Text style={s.muted}>
                {pol.coverage_type || 'Coverage'}
                {pol.valid_to ? ` · valid to ${dateOnly(pol.valid_to)}` : ''}
              </Text>
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
function Field({ label, value }) {
  return (
    <View style={[s.spread, { marginTop: 7 }]}>
      {/* The question carries the weight; the answer sits back. */}
      <Text style={{ color: c.ink, fontWeight: '700', fontSize: 14.5 }}>{label}</Text>
      <Text style={{ color: c.body, fontWeight: '500', fontSize: 14.5 }}>{value}</Text>
    </View>
  )
}
