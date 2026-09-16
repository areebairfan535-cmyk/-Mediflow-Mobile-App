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
 * Two ways in, on one screen.
 *
 * New patient — name, email, password. The clinic this app was built for
 * opens a chart for them, so they land in the tabs on their own empty record
 * rather than on a "not attached yet" dead end.
 *
 * Already a patient — the clinic made the record when they first walked in,
 * and this puts a password on it (§3). It asks for the patient ID and the
 * date of birth: the ID is printed on their own prescriptions and invoices,
 * so it is theirs to read off, and the date of birth means a stranger who
 * guessed an ID still cannot open somebody else's history.
 *
 * Both come back with the clinic attached, so both go straight into the tabs
 * on the tokens they already have. Being sent to the login screen to type
 * the same password again is a step that earns nothing.
 */
export default function SignUp() {
  const router = useRouter()

  // 'new' is the default because most people arriving here have never been
  // to the clinic; the ones with a patient ID know they have one.
  const [mode, setMode] = useState('new')
  const claiming = mode === 'claim'

  const [mrn, setMrn] = useState('')
  const [dob, setDob] = useState('')
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  // The ID card: the number and the date it stops being one. Optional at
  // sign-up — the clinic can add it at the desk — but asked for here so a
  // patient who has the card to hand ends up with a complete profile.
  const [nationalId, setNationalId] = useState('')
  const [idExpiry, setIdExpiry] = useState('')
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
  const badIdExpiry = idExpiry !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(idExpiry.trim())
  const account = email.trim() !== '' && password.length >= 8 && confirm === password
  const ready = claiming
    ? account && mrn.trim() !== '' && dob.trim() !== '' && !badDate
    : account && name.trim().length >= 2 && !badIdExpiry

  async function submit() {
    if (!ready || busy) return
    setBusy(true)
    setError(null)
    try {
      const res = claiming
        ? await api.claimChart(
            mrn.trim(), dob.trim(), name.trim() || undefined, email.trim(), password,
          )
        : await api.register(name.trim(), email.trim(), password, {
            phone: phone.trim() || undefined,
            national_id: nationalId.trim() || undefined,
            national_id_expiry: idExpiry.trim() || undefined,
          })
      const orgs = res.data.organizations || []

      // The clinic is attached, so the tokens that came back are worth keeping.
      if (orgs.length > 0) {
        await auth.save(res.data.auth)
        await auth.saveOrg(orgs[0].organization_id)
        router.replace('/(tabs)')
        return
      }

      // The account exists but no chart was opened — the server could not
      // find this build's clinic. Tokens would only let the tabs fail, so the
      // screen explains the other way in instead.
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
          {/* Two tabs instead of a heading: which one is lit says what the
              form below will do, and the rest of the form reads the same. */}
          <View style={{
            flexDirection: 'row', backgroundColor: c.bg, borderRadius: 12,
            padding: 4, marginBottom: 14,
          }}>
            {[['new', 'New patient'], ['claim', 'Already a patient']].map(([key, label]) => {
              const on = mode === key
              return (
                <Pressable
                  key={key}
                  onPress={() => { setMode(key); setError(null) }}
                  style={{
                    flex: 1, paddingVertical: 9, borderRadius: 9, alignItems: 'center',
                    backgroundColor: on ? c.surface : 'transparent',
                    shadowColor: '#000', shadowOpacity: on ? 0.08 : 0, shadowRadius: 6,
                    shadowOffset: { width: 0, height: 2 }, elevation: on ? 2 : 0,
                  }}
                >
                  <Text style={{
                    fontSize: 13.5, fontWeight: '700',
                    color: on ? c.accentDark : c.muted,
                  }}>
                    {label}
                  </Text>
                </Pressable>
              )
            })}
          </View>

          <Text style={[s.muted, { marginBottom: 8 }]}>
            {claiming
              ? 'Your clinic already has your record. This puts a password on it.'
              : 'A few details and your record is opened. Nothing medical is asked here.'}
          </Text>

          <ErrorBox error={error} />

          {claiming && (
            <>
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
            </>
          )}

          <Text style={s.label}>Full name</Text>
          <TextInput
            style={s.input}
            value={name}
            onChangeText={setName}
            autoCapitalize="words"
            placeholder={claiming ? 'As the clinic has it (optional)' : 'Your full name'}
            placeholderTextColor={c.muted}
            returnKeyType="next"
          />

          {!claiming && (
            <>
              <Text style={s.label}>Phone</Text>
              <TextInput
                style={s.input}
                value={phone}
                onChangeText={setPhone}
                keyboardType="phone-pad"
                autoComplete="tel"
                placeholder="03xx xxxxxxx"
                placeholderTextColor={c.muted}
                returnKeyType="next"
              />

              <Text style={s.label}>ID card number</Text>
              <TextInput
                style={s.input}
                value={nationalId}
                onChangeText={setNationalId}
                keyboardType="numbers-and-punctuation"
                autoCorrect={false}
                placeholder="33100-1234567-1 (optional)"
                placeholderTextColor={c.muted}
                returnKeyType="next"
              />

              <Text style={s.label}>ID card expiry</Text>
              <TextInput
                style={[s.input, badIdExpiry && { borderColor: c.danger }]}
                value={idExpiry}
                onChangeText={setIdExpiry}
                keyboardType="numbers-and-punctuation"
                autoCorrect={false}
                placeholder="YYYY-MM-DD (optional)"
                placeholderTextColor={c.muted}
                returnKeyType="next"
              />
              {badIdExpiry && (
                <Text style={{ color: c.danger, fontSize: 12.5, marginTop: 4 }}>
                  Write it as YYYY-MM-DD, for example 2031-05-20.
                </Text>
              )}
            </>
          )}

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
            {claiming
              ? 'Cannot find your patient ID? Sign up as a new patient instead — the clinic can merge the records later.'
              : 'Been to the clinic before? Switch to "Already a patient" and your history comes with you.'}
          </Text>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  )
}
