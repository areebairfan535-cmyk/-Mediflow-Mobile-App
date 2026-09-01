import { Tabs } from 'expo-router'
import { Text, View } from 'react-native'
import { c } from '../../src/ui'

/**
 * The four things §3 says a patient app is for, plus the profile.
 * Emoji stand in for a proper icon set so the app has no asset dependency.
 *
 * The selected tab carries a filled pill behind its icon. Colour alone was
 * doing all the work before, and on a phone held at arm's length two shades of
 * blue-grey are not a difference anyone can see — the shape is.
 */
const icon = (glyph) => ({ color, focused }) => (
  <View
    style={{
      width: 46,
      height: 30,
      borderRadius: 15,
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: focused ? c.accentSoft : 'transparent',
    }}
  >
    <Text style={{ fontSize: 19, color }}>{glyph}</Text>
  </View>
)

export default function TabsLayout() {
  return (
    <Tabs
      screenOptions={{
        tabBarActiveTintColor: c.accentDark,
        // Was `muted`, which is the colour of a disabled control — the four
        // tabs a patient is not currently on are perfectly usable, so they are
        // body text now and read as such.
        tabBarInactiveTintColor: c.body,
        tabBarStyle: {
          backgroundColor: c.surface,
          borderTopColor: c.border,
          height: 66,
          paddingTop: 6,
          paddingBottom: 8,
        },
        tabBarLabelStyle: { fontSize: 12, fontWeight: '700', marginTop: 2 },
        // The same blue the sign-in screen uses, so the app keeps one identity
        // from the moment it opens. Applied to every tab rather than only Home:
        // a header that changes colour as you move between tabs reads as a bug.
        headerStyle: { backgroundColor: c.accentDark },
        headerTintColor: '#fff',
        headerTitleStyle: { fontWeight: '700', color: '#fff' },
        headerShadowVisible: false,
      }}
    >
      <Tabs.Screen name="index" options={{ title: 'Home', tabBarIcon: icon('◧') }} />
      <Tabs.Screen name="appointments" options={{ title: 'Visits', tabBarIcon: icon('📅') }} />
      <Tabs.Screen name="records" options={{ title: 'Records', tabBarIcon: icon('📋') }} />
      <Tabs.Screen name="bills" options={{ title: 'Bills', tabBarIcon: icon('🧾') }} />
      <Tabs.Screen name="profile" options={{ title: 'Profile', tabBarIcon: icon('👤') }} />
    </Tabs>
  )
}
