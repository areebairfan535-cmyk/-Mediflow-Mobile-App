import { useState } from 'react'
import {
  KeyboardAvoidingView, Platform, Pressable, ScrollView,
  Text, TextInput, View,
} from 'react-native'
import { useRouter } from 'expo-router'
import { api, auth } from '../src/api'
import { c, s, ErrorBox } from '../src/ui'
import { useKeyboardInset } from '../src/authui'

/**
 * Attaching a login to a chart the clinic already holds (§3).
 *
 * This form does not create a medical record — the clinic did that when the
 * patient first walked in. It finds that record and puts a password on it.
 *
 * Which is why it asks for the patient ID and the date of birth. The ID is
 * printed on the patient's own prescriptions and invoices, so it is theirs to
 * read off; asking for the date of birth as well means a stranger who guessed
 * an ID still cannot open somebody else's history. Nothing else medical is
 * collected here.
 *
 * A successful claim comes back with the clinic attached, so it goes straight
 * into the tabs on the tokens it already has. If the details do not match, the
 * screen says how to get the account made the other way — at the front desk.
 */
export default function SignUp() {
  const router = useRouter()

  const [mrn, setMrn] = useState('')
  const [dob, setDob] = useState('')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [show, setShow] = useState(false)

  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const keyboard = useKeyboardInset()
  const [done, setDone] = useState(null)

  // Only complain about the second password once there is something to
  // compare; flagging a mismatch on the first keystroke is just noise.
  const mismatch = confirm !== '' && confirm !== password
  const tooShort = password !== '' && password.length < 8
  // The server is the authority on the date; this only stops the obvious
  // typo reaching it as a 422.
  const badDate = dob !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(dob.trim())
  const ready =
    mrn.trim() !== '' && !badDate && dob.trim() !== '' &&
    email.trim() !== '' && password.length >= 8 && confirm === password

  async function submit() {
    if (!ready || busy) return
    setBusy(true)
    setError(null)
    try {
      const res = await api.claimChart(
        mrn.trim(), dob.trim(), name.trim() || undefined, email.trim(), password,
      )
      const orgs = res.data.organizations || []

      // The claim attached the clinic, so the tokens that came back are worth
      // keeping: signing up and then being sent to the login screen to type
      // the same password again is a step that earns nothing.
      if (orgs.length > 0) {
        await auth.save(res.data.auth)
        await auth.saveOrg(orgs[0].organization_id)
        router.replace('/(tabs)')
        return
      }

      // Should not happen — a claim that succeeds has a clinic by definition.
      // If it ever does, tokens would only let the tabs fail.
      await auth.clear()
      setDone(email.trim())
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }

  /* ---- after it worked ---- */
  if (done) {
    return (
      <ScrollView
        style={{ backgroundColor: c.accentDark }}
        contentContainerStyle={{ flexGrow: 1, justifyContent: 'center', padding: 22 }}
      >
        <View style={{
          backgroundColor: c.surface, borderRadius: 18, padding: 24, alignItems: 'center',
          shadowColor: '#000', shadowOpacity: 0.18, shadowRadius: 20,
          shadowOffset: { width: 0, height: 10 }, elevation: 8,
        }}>
          <View style={{
            width: 58, height: 58, borderRadius: 29, backgroundColor: '#e6f4ea',
            alignItems: 'center', justifyContent: 'center', marginBottom: 14,
          }}>
            <Text style={{ fontSize: 26, color: '#1e7d40', fontWeight: '800' }}>✓</Text>
          </View>

          <Text style={{ fontSize: 19, fontWeight: '800', color: c.ink }}>Account created</Text>

          <Text style={[s.muted, { textAlign: 'center', marginTop: 8, lineHeight: 20 }]}>
            Give this email to your clinic&apos;s front desk and they will link it
            to your medical record. Your appointments, prescriptions and bills
            appear here as soon as they do.
          </Text>

          <View style={{
            backgroundColor: c.bg, borderRadius: 10, paddingVertical: 10,
            paddingHorizontal: 14, marginTop: 16, alignSelf: 'stretch',
          }}>
            <Text style={{ textAlign: 'center', fontWeight: '700', color: c.ink }}>{done}</Text>
          </View>

          <Pressable
            onPress={() => router.replace('/login')}
            style={[s.btn, { marginTop: 20, backgroundColor: c.accentDark, alignSelf: 'stretch' }]}
          >
            <Text style={s.btnText}>Log in</Text>
          </Pressable>
        </View>
      </ScrollView>
    )
  }

  /* ---- the form ---- */
  return (
    <KeyboardAvoidingView
      style={{ flex: 1, backgroundColor: c.accentDark }}
      // Android used to get `undefined` here, which is the same as having
      // no KeyboardAvoidingView at all — the keyboard simply covered
      // whatever you were typing into.
      behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
    >
      <ScrollView
        contentContainerStyle={{
          flexGrow: 1, justifyContent: 'center',
          padding: 22, paddingBottom: 22 + keyboard,
        }}
        keyboardShouldPersistTaps="handled"
      >
        <View style={{ alignItems: 'center', marginBottom: 22 }}>
          <View style={{
            width: 60, height: 60, borderRadius: 18, backgroundColor: '#fff',
            alignItems: 'center', justifyContent: 'center', marginBottom: 12,
            shadowColor: '#000', shadowOpacity: 0.2, shadowRadius: 12,
            shadowOffset: { width: 0, height: 6 }, elevation: 6,
          }}>
            <Text style={{ fontSize: 27, fontWeight: '800', color: c.accentDark }}>M</Text>
          </View>
          <Text style={{ fontSize: 27, fontWeight: '800', color: '#fff', letterSpacing: -0.6 }}>
            MediFlow
          </Text>
        </View>

        <View style={{
          backgroundColor: c.surface, borderRadius: 18, padding: 22,
          shadowColor: '#000', shadowOpacity: 0.18, shadowRadius: 20,
          shadowOffset: { width: 0, height: 10 }, elevation: 8,
        }}>
          {/* No heading: the button at the foot already says what this is, and
              saying it twice on one short form only pushed the fields down. */}
          <Text style={[s.muted, { marginBottom: 8 }]}>
            Your clinic already has your record. This puts a password on it.
          </Text>

          <ErrorBox error={error} />

          <Text style={s.label}>Patient ID</Text>
          <TextInput
            style={s.input}
            value={mrn}
            onChangeText={setMrn}
            autoCapitalize="characters"
            autoCorrect={false}
            placeholder="On your prescription or invoice"
            placeholderTextColor={c.muted}
            returnKeyType="next"
          />

          <Text style={s.label}>Date of birth</Text>
          <TextInput
            style={[s.input, badDate && { borderColor: c.danger }]}
            value={dob}
            onChangeText={setDob}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="numbers-and-punctuation"
            placeholder="YYYY-MM-DD"
            placeholderTextColor={c.muted}
            returnKeyType="next"
          />
          {badDate && (
            <Text style={{ color: c.danger, fontSize: 12.5, marginTop: 4 }}>
              Write it as YYYY-MM-DD, for example 1994-03-21.
            </Text>
          )}

          <Text style={s.label}>Full name</Text>
          <TextInput
            style={s.input}
            value={name}
            onChangeText={setName}
            autoCapitalize="words"
            placeholder="As the clinic has it (optional)"
            placeholderTextColor={c.muted}
            returnKeyType="next"
          />

          <Text style={s.label}>Email</Text>
          <TextInput
            style={s.input}
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            autoComplete="email"
            placeholder="you@example.com"
            placeholderTextColor={c.muted}
            returnKeyType="next"
          />

          <Text style={s.label}>Password</Text>
          <View style={{ position: 'relative', justifyContent: 'center' }}>
            <TextInput
              style={[s.input, { paddingRight: 62 }, tooShort && { borderColor: c.danger }]}
              value={password}
              onChangeText={setPassword}
              secureTextEntry={!show}
              autoComplete="new-password"
              placeholder="At least 8 characters"
              placeholderTextColor={c.muted}
              returnKeyType="next"
            />
            {/* One toggle for both password boxes — they are typed together and
                the point of looking is to check they match. */}
            <Pressable
              onPress={() => setShow(!show)}
              hitSlop={10}
              style={{ position: 'absolute', right: 12 }}
            >
              <Text style={{ color: c.accentDark, fontWeight: '700', fontSize: 13 }}>
                {show ? 'Hide' : 'Show'}
              </Text>
            </Pressable>
          </View>
          {tooShort && (
            <Text style={{ color: c.danger, fontSize: 12.5, marginTop: 4 }}>
              Use at least 8 characters.
            </Text>
          )}

          <Text style={s.label}>Confirm password</Text>
          <TextInput
            style={[s.input, mismatch && { borderColor: c.danger }]}
            value={confirm}
            onChangeText={setConfirm}
            secureTextEntry={!show}
            autoComplete="new-password"
            placeholder="Type it again"
            placeholderTextColor={c.muted}
            returnKeyType="go"
            onSubmitEditing={submit}
          />
          {mismatch && (
            <Text style={{ color: c.danger, fontSize: 12.5, marginTop: 4 }}>
              The two passwords are not the same.
            </Text>
          )}

          <Pressable
            onPress={submit}
            disabled={!ready || busy}
            style={[s.btn, { marginTop: 22, backgroundColor: c.accentDark },
                    (!ready || busy) && s.btnDisabled]}
          >
            <Text style={s.btnText}>{busy ? 'Creating…' : 'Create account'}</Text>
          </Pressable>

          <Pressable
            onPress={() => router.replace('/login')}
            style={{ marginTop: 14, alignItems: 'center' }}
          >
            <Text style={{ color: c.accent, fontWeight: '700', fontSize: 13.5 }}>
              I already have an account
            </Text>
          </Pressable>
        </View>

        <View style={{ marginTop: 20, alignItems: 'center', paddingHorizontal: 10 }}>
          <Text style={{ color: '#bcd9e8', fontSize: 12.5, textAlign: 'center', lineHeight: 19 }}>
            Cannot find your patient ID? The front desk can set the account up
            for you instead.
          </Text>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  )
}
