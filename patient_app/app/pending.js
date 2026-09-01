import { useState } from 'react'
import { Pressable, ScrollView, Text, View } from 'react-native'
import { useLocalSearchParams, useRouter } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import { api, auth } from '../src/api'
import { c } from '../src/ui'
import { a, AuthError, PillButton } from '../src/authui'

/**
 * Signed in, but not yet part of a clinic.
 *
 * Registering makes a login; it does not make a medical record, because the
 * record belongs to a clinic and only the clinic can attach one. Between those
 * two moments the account is real and completely empty, and this is what that
 * looks like — a plain explanation with the address to quote, instead of the
 * tabs failing one after another.
 *
 * "Check again" asks /me whether a clinic has appeared. It needs no password:
 * the session is already valid, it just has no tenant on it yet.
 */
export default function Pending() {
  const router = useRouter()
  const params = useLocalSearchParams()
  const email = typeof params.email === 'string' ? params.email : ''

  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [checked, setChecked] = useState(false)

  async function checkAgain() {
    if (busy) return
    setBusy(true)
    setError(null)
    try {
      const res = await api.me()
      const orgs = res.data.organizations || []

      if (orgs.length > 0) {
        await auth.saveOrg(orgs[0].organization_id)
        router.replace('/(tabs)')
        return
      }
      setChecked(true)
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }

  async function signOut() {
    // The API call is best-effort: what matters is that this device forgets.
    try { await api.logout() } catch { /* already gone */ }
    await auth.clear()
    router.replace('/login')
  }

  return (
    <View style={{ flex: 1, backgroundColor: a.bg }}>
      <StatusBar style="light" backgroundColor={c.accentDark} translucent={false} />

      <ScrollView contentContainerStyle={{ flexGrow: 1 }}>
        <View style={{
          backgroundColor: c.accentDark,
          borderBottomLeftRadius: 30,
          borderBottomRightRadius: 30,
          paddingTop: 48,
          paddingBottom: 34,
          alignItems: 'center',
        }}>
          <View style={{
            width: 74, height: 74, borderRadius: 22, backgroundColor: '#fff',
            alignItems: 'center', justifyContent: 'center',
          }}>
            <Text style={{ fontSize: 36, fontWeight: '800', color: c.accentDark }}>M</Text>
          </View>

          <Text style={{
            fontSize: 26, fontWeight: '800', color: '#fff',
            letterSpacing: -0.5, marginTop: 14,
          }}>
            Almost there
          </Text>
          <Text style={{ color: '#bcd9e8', marginTop: 5, fontSize: 13.5 }}>
            Your account works. Your record is not attached yet.
          </Text>
        </View>

        <View style={{ paddingHorizontal: 26, paddingTop: 28, paddingBottom: 28, flexGrow: 1 }}>
          <AuthError error={error} />

          <Text style={{ color: c.body, fontSize: 14.5, lineHeight: 22 }}>
            Give this email to your clinic&apos;s front desk. They attach it to your
            medical record, and your appointments, prescriptions and bills appear
            here straight away.
          </Text>

          <View style={{
            backgroundColor: c.accentSoft,
            borderWidth: 1,
            borderColor: 'rgba(13,126,168,0.22)',
            borderRadius: 12,
            paddingVertical: 14,
            marginTop: 16,
          }}>
            <Text style={{
              textAlign: 'center', fontWeight: '700',
              color: c.accentDark, fontSize: 15,
            }}>
              {email || 'the email you signed up with'}
            </Text>
          </View>

          {checked && (
            <Text style={{ color: c.muted, fontSize: 13, marginTop: 14, lineHeight: 19 }}>
              Still nothing. The clinic has not attached your record yet — it is
              done at the front desk, not from this app.
            </Text>
          )}

          <View style={{ marginTop: 22 }}>
            <PillButton
              label={busy ? 'Checking…' : 'Check again'}
              onPress={checkAgain}
              disabled={busy}
            />
          </View>

          <View style={{ flex: 1, minHeight: 30 }} />

          <Pressable onPress={signOut} hitSlop={8} style={{ alignItems: 'center', marginTop: 20 }}>
            <Text style={{ color: c.accentDark, fontWeight: '700', fontSize: 14 }}>
              Log out
            </Text>
          </Pressable>
        </View>
      </ScrollView>
    </View>
  )
}
