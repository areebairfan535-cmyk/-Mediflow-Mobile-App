import { useState } from 'react'
import {
  KeyboardAvoidingView, Platform, Pressable, ScrollView,
  Text, View,
} from 'react-native'
import { useRouter } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import { api, auth } from '../src/api'
import { c } from '../src/ui'
import { a, AuthError, Field, PillButton, Reveal, useKeyboardInset } from '../src/authui'

/**
 * The way in (§3).
 *
 * A blue band across the top carrying the mark, and the form on white beneath
 * it: the colour says whose app this is before a word is read, and the part
 * you have to type into stays plain.
 *
 * Below the button, the two ways out of a login screen — a forgotten password,
 * and no account yet — sit at the far end, away from the one button most
 * people came here to press.
 *
 * Creating an account only makes a login: the medical record belongs to a
 * clinic and only the clinic can attach one. signup.js says so at the end
 * rather than leaving someone in an empty dashboard.
 */
export default function Login() {
  const router = useRouter()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [show, setShow] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const keyboard = useKeyboardInset()

  const ready = email.trim() !== '' && password !== ''

  async function submit() {
    if (!ready || busy) return
    setBusy(true)
    setError(null)
    try {
      const res = await api.login(email.trim(), password)
      await auth.save(res.data.auth)

      const orgs = res.data.organizations || []

      // A brand-new account belongs to nobody yet. Sending it into the
      // tabs means every screen asks the API for a clinic that is not
      // there and fails one after another; say so once instead.
      if (orgs.length === 0) {
        router.replace({ pathname: '/pending', params: { email: email.trim() } })
        return
      }

      await auth.saveOrg(res.data.active_org_id ?? orgs[0].organization_id)

      router.replace('/(tabs)')
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }

  function useDemo() {
    setEmail('patient@demo.test')
    setPassword('Password123')
    setError(null)
  }

  return (
    <KeyboardAvoidingView
      style={{ flex: 1, backgroundColor: a.bg }}
      // Android used to get `undefined` here, which is the same as having
      // no KeyboardAvoidingView at all — the keyboard simply covered
      // whatever you were typing into.
      behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
    >
      {/* The band runs to the top edge, so the bar above it is the same blue. */}
      <StatusBar style="light" backgroundColor={c.accentDark} translucent={false} />

      <ScrollView
        contentContainerStyle={{ flexGrow: 1 }}
        keyboardShouldPersistTaps="handled"
      >
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
            shadowColor: '#000', shadowOpacity: 0.18, shadowRadius: 12,
            shadowOffset: { width: 0, height: 6 }, elevation: 6,
          }}>
            <Text style={{ fontSize: 36, fontWeight: '800', color: c.accentDark }}>M</Text>
          </View>

          <Text style={{
            fontSize: 26, fontWeight: '800', color: '#fff',
            letterSpacing: -0.5, marginTop: 14,
          }}>
            MediFlow
          </Text>
          <Text style={{ color: '#bcd9e8', marginTop: 5, fontSize: 13.5 }}>
            Your appointments, records and bills
          </Text>
        </View>

        <View style={{
          paddingHorizontal: 26, paddingTop: 28, flexGrow: 1,
          // Room for the keyboard, so the field being typed into can be
          // scrolled clear of it.
          paddingBottom: 28 + keyboard,
        }}>
          <AuthError error={error} />

          <Field
            label="Email"
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            autoComplete="email"
            returnKeyType="next"
          />

          <Field
            label="Password"
            value={password}
            onChangeText={setPassword}
            secureTextEntry={!show}
            autoComplete="current-password"
            returnKeyType="go"
            onSubmitEditing={submit}
            right={<Reveal on={show} onToggle={() => setShow(!show)} />}
          />

          <View style={{ marginTop: 12 }}>
            <PillButton
              label={busy ? 'Logging in…' : 'Log in'}
              onPress={submit}
              disabled={!ready || busy}
            />
          </View>

          <Pressable
            onPress={() => router.push('/forgot')}
            hitSlop={8}
            style={{ alignItems: 'center', marginTop: 20 }}
          >
            <Text style={{ color: c.accentDark, fontWeight: '700', fontSize: 14 }}>
              Forgotten password?
            </Text>
          </Pressable>

          {/* Everything below is for people who are not signing in. */}
          <View style={{ flex: 1, minHeight: 44 }} />

          <PillButton
            label="Create new account"
            tone="outline"
            onPress={() => router.push('/signup')}
          />

          <Pressable onPress={useDemo} hitSlop={8} style={{ alignItems: 'center', marginTop: 16 }}>
            <Text style={{ color: a.muted, fontSize: 12 }}>
              Demo: patient@demo.test · Password123
            </Text>
          </Pressable>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  )
}
