import { useCallback, useEffect, useState } from 'react'
import { Pressable, ScrollView, Text, TextInput, View } from 'react-native'
import { useFocusEffect, useRouter } from 'expo-router'
import { api, auth } from '../src/api'
import { Card, ErrorBox, Loading, c, s, when } from '../src/ui'

/**
 * The account, as distinct from the medical record (§11).
 *
 * Profile is "who I am to the clinic"; this is "how I get in" — the password
 * and the devices holding a live session. They are kept apart because losing a
 * phone is not a clinical event, and because a patient looking for the sign-out
 * on every device should not have to scroll past their allergies to find it.
 *
 * Every session here is a real token the server will accept until it is
 * revoked, which is why they are listed at all: a device you no longer have is
 * a device that can still read your record.
 */
export default function Account() {
  const router = useRouter()
  const [me, setMe] = useState(null)
  const [sessions, setSessions] = useState({ loading: true, rows: [] })
  const [form, setForm] = useState({ current: '', next: '', confirm: '' })
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState(null)

  const load = useCallback(async () => {
    try {
      const [profile, list] = await Promise.all([
        api.profile(),
        api.sessions().catch(() => null),
      ])
      setMe(profile.data.patient)
      setSessions({ loading: false, rows: list?.data.sessions ?? [] })
    } catch (error) {
      setSessions({ loading: false, rows: [], error })
    }
  }, [])

  useFocusEffect(useCallback(() => { load() }, [load]))

  // Confirmations get out of the way; failures stay long enough to act on.
  useEffect(() => {
    if (!notice) return undefined
    const t = setTimeout(() => setNotice(null), notice.ok ? 3000 : 6000)
    return () => clearTimeout(t)
  }, [notice])

  async function changePassword() {
    if (form.next !== form.confirm) {
      setNotice({ ok: false, message: 'The two new passwords do not match.' })
      return
    }
    setBusy(true)
    try {
      await api.changePassword(form.current, form.next)
      setForm({ current: '', next: '', confirm: '' })
      // Changing a password revokes every other session server-side, so the
      // list on screen is now stale.
      setNotice({ ok: true, message: 'Password changed. Other devices were signed out.' })
      await load()
    } catch (error) {
      setNotice({ ok: false, message: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function signOut(everywhere = false) {
    try {
      await (everywhere ? api.logoutAll() : api.logout())
    } catch {
      /* leaving either way */
    }
    await auth.clear()
    router.replace('/login')
  }

  if (!me) return <Loading />

  const canSave =
    form.current !== '' && form.next.length >= 8 && form.confirm !== '' && !busy

  return (
    <ScrollView style={s.screen} contentContainerStyle={s.content}>
      {notice && (
        <View style={[
          s.errorBox,
          notice.ok && { backgroundColor: c.okSoft, borderColor: 'rgba(15,138,95,0.2)' },
        ]}>
          <Text style={[s.errorText, notice.ok && { color: c.ok }]}>{notice.message}</Text>
        </View>
      )}

      {/* ---- who is signed in ---- */}
      <Card>
        <View style={s.row}>
          <View style={{
            width: 52, height: 52, borderRadius: 26, backgroundColor: c.accentSoft,
            alignItems: 'center', justifyContent: 'center',
          }}>
            <Text style={{ fontSize: 19, fontWeight: '800', color: c.accentDark }}>
              {(me.first_name?.[0] || '') + (me.last_name?.[0] || '')}
            </Text>
          </View>
          <View style={{ flex: 1 }}>
            <Text style={{ fontSize: 18, fontWeight: '800', color: c.accentDark }}>
              {me.first_name} {me.last_name}
            </Text>
            <Text style={s.muted}>{me.email || 'No email on file'}</Text>
          </View>
        </View>
      </Card>

      {/* ---- password ---- */}
      <Text style={[s.label, { marginTop: 20 }]}>Change password</Text>
      <Card>
        <Text style={s.label}>Current password</Text>
        <TextInput
          style={s.input}
          value={form.current}
          onChangeText={(v) => setForm({ ...form, current: v })}
          secureTextEntry
          placeholder="••••••••"
          placeholderTextColor={c.muted}
        />

        <Text style={s.label}>New password</Text>
        <TextInput
          style={s.input}
          value={form.next}
          onChangeText={(v) => setForm({ ...form, next: v })}
          secureTextEntry
          placeholder="At least 8 characters"
          placeholderTextColor={c.muted}
        />

        <Text style={s.label}>Repeat the new password</Text>
        <TextInput
          style={s.input}
          value={form.confirm}
          onChangeText={(v) => setForm({ ...form, confirm: v })}
          secureTextEntry
          placeholder="••••••••"
          placeholderTextColor={c.muted}
          onSubmitEditing={() => canSave && changePassword()}
        />

        {form.next !== '' && form.next.length < 8 && (
          <Text style={[s.muted, { color: c.warn, marginTop: 6 }]}>
            Eight characters or more.
          </Text>
        )}

        <Pressable
          onPress={changePassword}
          disabled={!canSave}
          style={[s.btn, { marginTop: 18 }, !canSave && s.btnDisabled]}
        >
          <Text style={s.btnText}>{busy ? 'Saving…' : 'Change password'}</Text>
        </Pressable>

        <Text style={[s.muted, { marginTop: 10, fontSize: 11.5 }]}>
          Changing it signs out every other device — which is the point if you
          are changing it because you lost one.
        </Text>
      </Card>

      {/* ---- devices ---- */}
      <Text style={[s.label, { marginTop: 20 }]}>Signed-in devices</Text>
      <Card>
        {sessions.loading ? (
          <Loading label="Checking…" />
        ) : sessions.error ? (
          <ErrorBox error={sessions.error} />
        ) : sessions.rows.length === 0 ? (
          <Text style={s.body}>Only this device.</Text>
        ) : (
          sessions.rows.map((row) => (
            <View key={row.id} style={[s.spread, { marginTop: 8 }]}>
              <View style={{ flex: 1 }}>
                <Text style={{ fontWeight: '700', color: c.ink }}>
                  {row.device_name || 'Unknown device'}
                  {row.current ? '  ·  this device' : ''}
                </Text>
                <Text style={s.muted}>
                  {row.ip_address ? `${row.ip_address} · ` : ''}
                  last used {when(row.last_used_at || row.created_at)}
                </Text>
              </View>

              {!row.current && (
                <Pressable
                  onPress={() => api.revokeSession(row.id).then(load).catch(() => {})}
                  hitSlop={8}
                >
                  <Text style={{ color: c.danger, fontWeight: '700', fontSize: 13 }}>
                    Sign out
                  </Text>
                </Pressable>
              )}
            </View>
          ))
        )}
      </Card>

      {/* ---- leaving ---- */}
      <Pressable
        onPress={() => signOut(false)}
        style={[s.btn, { backgroundColor: c.accentDark, marginTop: 26 }]}
      >
        <Text style={s.btnText}>Sign out</Text>
      </Pressable>

      <Pressable onPress={() => signOut(true)} style={[s.btnGhost, { marginTop: 10 }]}>
        <Text style={[s.btnGhostText, { color: c.danger, fontWeight: '700' }]}>
          Sign out everywhere
        </Text>
      </Pressable>
    </ScrollView>
  )
}
