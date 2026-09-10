import { Platform } from 'react-native'
import * as Device from 'expo-device'
import Constants from 'expo-constants'
import { api } from './api'

/**
 * expo-notifications is loaded lazily, and that is the whole point.
 *
 * Expo Go dropped remote push in SDK 53, and the module now throws the moment
 * it is imported there. A static `import` at the top of this file therefore
 * took the entire app down on launch — the login screen imports this module,
 * so the crash happened before anything could be drawn, and every careful
 * try/catch below never got the chance to run. The protection was all on the
 * runtime path; the failure was on the load path.
 *
 * Requiring it here means an unavailable module is a fact this file can
 * handle rather than an error that ends the process: push is off, the in-app
 * inbox still has everything, and the patient can use the app. That is the
 * same rule the server already follows for an unconfigured channel — skipped,
 * not failed.
 */
let notificationsModule = null
let notificationsChecked = false

function notifications() {
  if (notificationsChecked) return notificationsModule
  notificationsChecked = true
  try {
    notificationsModule = require('expo-notifications')
  } catch (error) {
    notificationsModule = null
    // console.log, not console.warn: in Expo Go this is expected, permanent,
    // and nothing anybody can act on, so LogBox should not throw a banner
    // over the app on every launch. A warning is for something that might be
    // wrong. This is just how Expo Go is since SDK 53.
    console.log(
      '[push] remote notifications are unavailable here (Expo Go) — the in-app inbox still works.',
    )
  }
  return notificationsModule
}

/** Whether this build can be reached by a push at all. */
export function pushAvailable() {
  return notifications() !== null
}

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
{
  const N = notifications()
  if (N) {
    N.setNotificationHandler({
      handleNotification: async () => ({
        shouldShowBanner: true,
        shouldShowList: true,
        shouldPlaySound: true,
        shouldSetBadge: true,
      }),
    })
  }
}

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
    // Expo Go since SDK 53, and the web build, have no remote push to offer.
    // Nothing is wrong — there is simply nowhere to send one.
    const N = notifications()
    if (!N) return false

    // A simulator has no push token to give. Not a failure worth showing.
    if (!Device.isDevice) return false

    // Android needs a channel before anything will make a sound.
    if (Platform.OS === 'android') {
      await N.setNotificationChannelAsync('default', {
        name: 'Appointments and results',
        importance: N.AndroidImportance.DEFAULT,
        sound: 'default',
      })
    }

    const existing = await N.getPermissionsAsync()
    let status = existing.status

    // Only ask if we have not been told already. Asking again after a refusal
    // is how an app gets its notifications turned off in system settings.
    if (status !== 'granted') {
      const asked = await N.requestPermissionsAsync()
      status = asked.status
    }
    if (status !== 'granted') return false

    // projectId is required in a build; in Expo Go it comes from the manifest.
    const projectId =
      Constants?.expoConfig?.extra?.eas?.projectId ?? Constants?.easConfig?.projectId

    const { data: token } = await N.getExpoPushTokenAsync(
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
  const N = notifications()
  // No push here means no taps to hear. Hand back a no-op unsubscribe so the
  // caller's cleanup works exactly the same either way.
  if (!N) return () => {}

  const sub = N.addNotificationResponseReceivedListener((response) => {
    const data = response?.notification?.request?.content?.data ?? {}
    onOpen({
      type: data.subject_type ?? null,
      id: data.subject_id ? Number(data.subject_id) : null,
      event: data.event ?? null,
    })
  })

  return () => sub.remove()
}
