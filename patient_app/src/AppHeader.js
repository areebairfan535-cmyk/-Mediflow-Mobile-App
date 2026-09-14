import { View, Text, Pressable } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { c } from './ui'

/**
 * The header for screens pushed on top of the tabs — Notifications, Book,
 * Account.
 *
 * These used to use Android's native toolbar, which is 56dp tall and ignores
 * headerStyle.height: react-navigation's native stack supports backgroundColor
 * there and nothing else. So the blue bar on a pushed screen was taller than
 * the one on the tabs and there was no setting that would change it.
 *
 * Drawing it here costs a few lines and makes the height a decision rather
 * than a platform default. Everything the native header did is kept: the back
 * arrow, the title, and whatever the screen put in headerRight — Notifications
 * puts its ⋮ menu there, and it keeps working untouched.
 */
export function AppHeader({ navigation, options, back }) {
  const insets = useSafeAreaInsets()
  const title = options?.title ?? ''
  const Right = options?.headerRight

  return (
    <View style={{ backgroundColor: c.accentDark, paddingTop: insets.top }}>
      <View
        style={{
          // 64, because that is exactly what the tabs use. It is not a
          // guess: getDefaultHeaderHeight in @react-navigation/elements
          // returns 64 on Android, plus the status bar inset, and the
          // tabs header is that component. Android's NATIVE toolbar —
          // what a pushed screen used before — is 56, which is why the
          // blue bar changed height when you tapped the bell.
          height: 64,
          flexDirection: 'row',
          alignItems: 'center',
          paddingHorizontal: 6,
        }}
      >
        {back ? (
          <Pressable onPress={navigation.goBack} hitSlop={14}
                     style={{ paddingHorizontal: 8, paddingVertical: 4 }}>
            <Text style={{ color: '#fff', fontSize: 26, lineHeight: 28 }}>‹</Text>
          </Pressable>
        ) : null}

        <Text
          numberOfLines={1}
          style={{
            flex: 1,
            color: '#fff',
            fontWeight: '700',
            fontSize: 20,   // the same 20 the tabs header uses
            marginLeft: back ? 2 : 10,
          }}
        >
          {title}
        </Text>

        {Right ? Right({ tintColor: '#fff' }) : null}
      </View>
    </View>
  )
}
