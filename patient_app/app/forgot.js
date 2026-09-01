import { useState } from 'react'
import {
  KeyboardAvoidingView, Platform, Pressable, ScrollView,
  Text, TextInput, View,
} from 'react-native'
import { useRouter } from 'expo-router'
import { api } from '../src/api'
import { c, s, ErrorBox } from '../src/ui'
import { useKeyboardInset } from '../src/authui'

/**
 * Forgotten password (§11).
 *
 * Two steps on one screen rather than two screens: the email stays visible
 * while the code is typed, so anyone who mistyped it can see that and fix it
 * without starting again.
 *
 * The server answers the first step the same way whether or not the address is
 * registered, so this screen cannot say "no such account" — and does not try.
 * It says a code is on its way and moves on.
 */
export default function Forgot() {
  const router = useRouter()

  const [step, setStep] = useState(1)
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [show, setShow] = useState(false)

  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const keyboard = useKeyboardInset()
  const [hint, setHint] = useState(null)
  const [done, setDone] = useState(false)

  const mismatch = confirm !== '' && confirm !== password
  const tooShort = password !== '' && password.length < 8
  const canSend  = email.trim() !== ''
  const canReset = code.trim().length >= 4 && password.length >= 8 && confirm === password

  async function send() {
    if (!canSend || busy) return
    setBusy(true)
    setError(null)
    try {
      const res = await api.forgotPassword(email.trim())
      // Outside production the server hands the code back so the flow can be
      // finished without a mail server. It is never there in production.
      if (res.data.code) {
        setCode(String(res.data.code))
        setHint(`Development build — the code is ${res.data.code}.`)
      } else {
        setHint(`Check ${email.trim()} for a 6-digit code. It expires in ${res.data.expires_in_minutes} minutes.`)
      }
      setStep(2)
    } catch (err) {
      setError(err)
    } finally {
      setBusy(false)
    }
  }

  async function reset() {
    if (!canReset || busy) return
    setBusy(true)
    setError(null)
    try {
      await api.resetPassword(email.trim(), code.trim(), password)
      setDone(true)
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

          <Text style={{ fontSize: 19, fontWeight: '800', color: c.ink }}>Password changed</Text>
          <Text style={[s.muted, { textAlign: 'center', marginTop: 8, lineHeight: 20 }]}>
            Every device that was signed in has been signed out. Log in with the
            new password.
          </Text>

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
          <Text style={{ fontSize: 19, fontWeight: '800', color: c.ink }}>
            Forgotten password
          </Text>
          <Text style={[s.muted, { marginTop: 2, marginBottom: 6 }]}>
            {step === 1
              ? 'We will email you a 6-digit code.'
              : 'Type the code, then choose a new password.'}
          </Text>

          <ErrorBox error={error} />

          {hint !== null && step === 2 && (
            <View style={{
              backgroundColor: c.accentSoft, borderRadius: 10,
              paddingVertical: 10, paddingHorizontal: 12, marginTop: 4, marginBottom: 4,
            }}>
              <Text style={{ color: c.accentDark, fontSize: 13, lineHeight: 18 }}>{hint}</Text>
            </View>
          )}

          <Text style={s.label}>Email</Text>
          <TextInput
            style={s.input}
            value={email}
            onChangeText={setEmail}
            editable={step === 1}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            autoComplete="email"
            placeholder="you@example.com"
            placeholderTextColor={c.muted}
            returnKeyType="go"
            onSubmitEditing={send}
          />

          {step === 1 ? (
            <Pressable
              onPress={send}
              disabled={!canSend || busy}
              style={[s.btn, { marginTop: 22, backgroundColor: c.accentDark },
                      (!canSend || busy) && s.btnDisabled]}
            >
              <Text style={s.btnText}>{busy ? 'Sending…' : 'Send code'}</Text>
            </Pressable>
          ) : (
            <>
              <Text style={s.label}>Code</Text>
              <TextInput
                style={[s.input, { letterSpacing: 6, fontSize: 18, fontWeight: '700' }]}
                value={code}
                onChangeText={setCode}
                keyboardType="number-pad"
                maxLength={6}
                placeholder="000000"
                placeholderTextColor={c.muted}
              />

              <Text style={s.label}>New password</Text>
              <View style={{ position: 'relative', justifyContent: 'center' }}>
                <TextInput
                  style={[s.input, { paddingRight: 62 }, tooShort && { borderColor: c.danger }]}
                  value={password}
                  onChangeText={setPassword}
                  secureTextEntry={!show}
                  autoComplete="new-password"
                  placeholder="At least 8 characters"
                  placeholderTextColor={c.muted}
                />
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
                onSubmitEditing={reset}
              />
              {mismatch && (
                <Text style={{ color: c.danger, fontSize: 12.5, marginTop: 4 }}>
                  The two passwords are not the same.
                </Text>
              )}

              <Pressable
                onPress={reset}
                disabled={!canReset || busy}
                style={[s.btn, { marginTop: 22, backgroundColor: c.accentDark },
                        (!canReset || busy) && s.btnDisabled]}
              >
                <Text style={s.btnText}>{busy ? 'Changing…' : 'Change password'}</Text>
              </Pressable>

              {/* A code that never arrived is the commonest reason to be stuck
                  here, so the way to ask for another is on the screen. */}
              <Pressable
                onPress={() => { setStep(1); setCode(''); setHint(null); setError(null) }}
                style={{ marginTop: 12, alignItems: 'center' }}
              >
                <Text style={{ color: c.accent, fontWeight: '700', fontSize: 13.5 }}>
                  Send a new code
                </Text>
              </Pressable>
            </>
          )}

          <Pressable
            onPress={() => router.replace('/login')}
            style={{ marginTop: 14, alignItems: 'center' }}
          >
            <Text style={{ color: c.muted, fontWeight: '600', fontSize: 13.5 }}>
              Back to log in
            </Text>
          </Pressable>
        </View>

        <View style={{ marginTop: 20, alignItems: 'center', paddingHorizontal: 10 }}>
          <Text style={{ color: '#bcd9e8', fontSize: 12.5, textAlign: 'center', lineHeight: 19 }}>
            The code works once and expires in 30 minutes. Changing the password
            signs out every device.
          </Text>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  )
}
