import { Stack } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import { c } from '../src/ui'

export default function RootLayout() {
  return (
    <>
      {/* Headers are the dark blue now, so the clock and battery beside them
          have to be light or they disappear into it.

          Not translucent, and painted the same blue: on Android a translucent
          status bar lets the header slide up underneath the clock, which is
          what made the title look jammed against the top of the screen. */}
      <StatusBar style="light" backgroundColor={c.accentDark} translucent={false} />
      <Stack
        screenOptions={{
          headerStyle: { backgroundColor: c.accentDark },
          headerTintColor: '#fff',
          headerTitleStyle: { fontWeight: '700', color: '#fff' },
          headerShadowVisible: false,
          contentStyle: { backgroundColor: c.bg },
        }}
      >
        <Stack.Screen name="index" options={{ headerShown: false }} />
        <Stack.Screen name="login" options={{ headerShown: false }} />
        <Stack.Screen name="signup" options={{ headerShown: false }} />
        <Stack.Screen name="forgot" options={{ headerShown: false }} />
        <Stack.Screen name="pending" options={{ headerShown: false }} />
        <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        {/* No extra top padding: the screen uses the same content spacing as
            Home and the other tabs, so the gap under the header matches. */}
        <Stack.Screen name="notifications" options={{ title: "Notifications" }} />
        <Stack.Screen name="book" options={{ title: "Book an appointment" }} />
        <Stack.Screen name="account" options={{ title: "Account & security" }} />
      </Stack>
    </>
  )
}
