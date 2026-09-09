import { Platform } from 'react-native'
import * as Device from 'expo-device'
import * as Notifications from 'expo-notifications'
import Constants from 'expo-constants'
import { api } from './api'

/**
 * Getting notifications out of the app and onto the phone (§20).
 *
 * The in-app inbox only helps somebody who has already opened the app. A
 * reminder for tomorrow's appointment has to arrive when the app is closed,
 * or it is not a reminder.
 *
 * Three things have to happen, in this order:
 *
 *   1. the person agrees to be notified
 *   2. the OS issues a push token for this install
 *   3. the server is told where to send
 *
 * Any of them can fail for ordinary reasons — permission declined, running in
 * a simulator, no network — and none of them should stop the app working. The
 * patient can still open it and read the same messages inside.
 */

// How a notification behaves when it arrives while the app is open. Showing
// it is the point: the patient should see "your prescription is ready" land
// whether or not they happen to be on the notifications screen.
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: true,
  }),
})

/** A name the person would recognise in a list of their own devices. */
function deviceName() {
  const name = Device.deviceName || Device.modelName
  return name ? String(name).slice(0, 120) : null
}

/**
 * Ask for permission and tell the server where to reach this phone.
 *
 * Called after every sign-in rather than once at install: the OS reissues
 * push tokens from time to time, and a device that registered a year ago and
 * never again would have quietly stopped being reachable. The server upserts
 * on the token, so calling it often costs nothing.
 *
 * @returns {Promise<boolean>} whether this phone can now be reached
 */
export async function registerForPush() {
  try {
    // A simulator has no push token to give. Not a failure worth showing.
    if (!Device.isDevice) return false

    // Android needs a channel before anything will make a sound.
    if (Platform.OS === 'android') {
      await Notifications.setNotificationChannelAsync('default', {
        name: 'Appointments and results',
        importance: Notifications.AndroidImportance.DEFAULT,
        sound: 'default',
      })
    }

    const existing = await Notifications.getPermissionsAsync()
    let status = existing.status

    // Only ask if we have not been told already. Asking again after a refusal
    // is how an app gets its notifications turned off in system settings.
    if (status !== 'granted') {
      const asked = await Notifications.requestPermissionsAsync()
      status = asked.status
    }
    if (status !== 'granted') return false

    // projectId is required in a build; in Expo Go it comes from the manifest.
    const projectId =
      Constants?.expoConfig?.extra?.eas?.projectId ?? Constants?.easConfig?.projectId

    const { data: token } = await Notifications.getExpoPushTokenAsync(
      projectId ? { projectId } : undefined,
    )
    if (!token) return false

    await api.registerDevice({
      token,
      platform: Platform.OS === 'ios' ? 'ios' : Platform.OS === 'web' ? 'web' : 'android',
      device_name: deviceName(),
    })

    return true
  } catch (error) {
    // Deliberately swallowed. Push is an improvement on the inbox, not a
    // precondition for it — a patient who declined notifications, or whose
    // registration failed on a bad connection, must still be able to use the
    // app. The inbox inside still has everything.
    console.warn('[push] not registered:', error?.message ?? error)
    return false
  }
}

/**
 * What to do when the patient taps a notification.
 *
 * The server sends subject_type and subject_id alongside the text, so a tap
 * can open the thing the message is about rather than dropping the person on
 * a generic list and making them find it.
 *
 * @param {(target: {type: string|null, id: number|null}) => void} onOpen
 * @returns {() => void} call to stop listening
 */
export function onNotificationTap(onOpen) {
  const sub = Notifications.addNotificationResponseReceivedListener((response) => {
    const data = response?.notification?.request?.content?.data ?? {}
    onOpen({
      type: data.subject_type ?? null,
      id: data.subject_id ? Number(data.subject_id) : null,
      event: data.event ?? null,
    })
  })

  return () => sub.remove()
}
