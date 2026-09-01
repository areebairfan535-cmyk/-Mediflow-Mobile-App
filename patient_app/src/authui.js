import { useEffect, useState } from 'react'
import { Keyboard, Pressable, Text, TextInput, View } from 'react-native'
import { c } from './ui'

/**
 * How tall the keyboard is right now, or 0.
 *
 * KeyboardAvoidingView alone was not enough on Android: it shuffles the layout
 * but leaves the scroll content exactly as tall as it was, so there is nothing
 * to scroll and the field you are typing into can sit under the keyboard.
 * Adding this much padding at the foot gives the ScrollView somewhere to go,
 * and React Native then brings the focused field into view by itself.
 */
export function useKeyboardInset() {
  const [inset, setInset] = useState(0)

  useEffect(() => {
    const shown = Keyboard.addListener('keyboardDidShow', (e) => {
      setInset(e.endCoordinates?.height ?? 0)
    })
    const hidden = Keyboard.addListener('keyboardDidHide', () => setInset(0))

    return () => { shown.remove(); hidden.remove() }
  }, [])

  return inset
}

/**
 * The three auth screens share a look the rest of the app does not: one plain
 * ground with no cards on it, outlined fields whose label sits inside the box,
 * and pill buttons. It lives here so login, sign-up and the reset flow cannot
 * drift apart.
 *
 * Light, like every other screen in the app — the way in should not look like
 * a different product from what is behind it.
 */
export const a = {
  bg: '#ffffff',
  field: '#ffffff',
  border: '#dde4ed',
  borderOn: c.accent,
  ink: c.ink,
  label: c.muted,
  muted: c.muted,
}

/**
 * A field whose label lives inside the box.
 *
 * Empty and untouched, the box shows one word — the label doing the
 * placeholder's job. As soon as there is something to type or something typed,
 * the label shrinks to the top and gets out of the way, so the value is never
 * competing with the word describing it.
 */
export function Field({
  label,
  value,
  onChangeText,
  right = null,
  style = null,
  inputStyle = null,
  ...rest
}) {
  const [focused, setFocused] = useState(false)
  const lifted = focused || String(value ?? '') !== ''

  return (
    <View
      style={[{
        backgroundColor: a.field,
        borderWidth: 1,
        borderColor: focused ? a.borderOn : a.border,
        borderRadius: 12,
        paddingHorizontal: 14,
        paddingTop: lifted ? 8 : 0,
        paddingBottom: lifted ? 6 : 0,
        height: 62,
        justifyContent: 'center',
        marginBottom: 10,
      }, style]}
    >
      {lifted && (
        <Text style={{ color: a.label, fontSize: 11.5, marginBottom: 1 }}>{label}</Text>
      )}

      <View style={{ flexDirection: 'row', alignItems: 'center' }}>
        <TextInput
          style={[{
            flex: 1,
            color: a.ink,
            fontSize: 15.5,
            padding: 0,
          }, inputStyle]}
          value={value}
          onChangeText={onChangeText}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          placeholder={lifted ? '' : label}
          placeholderTextColor={a.label}
          {...rest}
        />
        {right}
      </View>
    </View>
  )
}

/** The one bright thing on the screen. */
export function PillButton({ label, onPress, disabled = false, tone = 'solid' }) {
  const solid = tone === 'solid'

  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      style={{
        height: 50,
        borderRadius: 25,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: solid ? (disabled ? '#bcd7e3' : c.accent) : 'transparent',
        borderWidth: solid ? 0 : 1.4,
        borderColor: c.accent,
        opacity: disabled && !solid ? 0.45 : 1,
      }}
    >
      <Text style={{
        color: solid ? '#fff' : c.accent,
        fontWeight: '700',
        fontSize: 15.5,
      }}>
        {label}
      </Text>
    </Pressable>
  )
}

/** Show / Hide, for the password boxes. */
export function Reveal({ on, onToggle }) {
  return (
    <Pressable onPress={onToggle} hitSlop={10} style={{ paddingLeft: 10 }}>
      <Text style={{ color: c.accentDark, fontWeight: '700', fontSize: 13 }}>
        {on ? 'Hide' : 'Show'}
      </Text>
    </Pressable>
  )
}

/** The mark, centred, above everything. */
export function AuthMark({ caption = null }) {
  return (
    <View style={{ alignItems: 'center' }}>
      <View style={{
        width: 74, height: 74, borderRadius: 22, backgroundColor: c.accent,
        alignItems: 'center', justifyContent: 'center',
      }}>
        <Text style={{ fontSize: 36, fontWeight: '800', color: '#fff' }}>M</Text>
      </View>

      <Text style={{
        fontSize: 25, fontWeight: '800', color: a.ink,
        letterSpacing: -0.5, marginTop: 14,
      }}>
        MediFlow
      </Text>

      {caption !== null && (
        <Text style={{ color: a.label, marginTop: 6, fontSize: 13.5, textAlign: 'center' }}>
          {caption}
        </Text>
      )}
    </View>
  )
}

/** Errors, sized to sit between the fields without shoving them about. */
export function AuthError({ error }) {
  if (!error) return null

  const lines = error.fieldMessages?.length > 0 ? error.fieldMessages : [error.message]

  return (
    <View style={{
      backgroundColor: c.dangerSoft,
      borderWidth: 1,
      borderColor: 'rgba(193,54,47,0.22)',
      borderRadius: 12,
      paddingVertical: 10,
      paddingHorizontal: 13,
      marginBottom: 12,
    }}>
      {lines.map((line, i) => (
        <Text key={i} style={{ color: c.danger, fontSize: 13, lineHeight: 18 }}>{line}</Text>
      ))}
    </View>
  )
}

/** "Here is what happens next" — neither an error nor a success. */
export function AuthNotice({ children }) {
  return (
    <View style={{
      backgroundColor: c.accentSoft,
      borderWidth: 1,
      borderColor: 'rgba(13,126,168,0.22)',
      borderRadius: 12,
      paddingVertical: 10,
      paddingHorizontal: 13,
      marginBottom: 12,
    }}>
      <Text style={{ color: c.accentDark, fontSize: 13, lineHeight: 18 }}>{children}</Text>
    </View>
  )
}
