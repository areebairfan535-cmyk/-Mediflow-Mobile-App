import Constants from 'expo-constants'
import * as SecureStore from 'expo-secure-store'
import { Platform } from 'react-native'

/**
 * API client for the patient app.
 *
 * Tokens live in expo-secure-store — the iOS Keychain and Android
 * EncryptedSharedPreferences — not AsyncStorage. A phone is lost far more
 * often than a desktop, and this token opens someone's medical record.
 *
 * SecureStore has no web implementation, so the web preview falls back to
 * localStorage. That is acceptable for a demo in a browser and never ships to
 * a device, where the native path is always taken.
 */

const ACCESS = 'mediflow_access'
const REFRESH = 'mediflow_refresh'
const ORG = 'mediflow_org'

const isWeb = Platform.OS === 'web'

const store = {
  async get(key) {
    try {
      return isWeb ? window.localStorage.getItem(key) : await SecureStore.getItemAsync(key)
    } catch {
      return null
    }
  },
  async set(key, value) {
    try {
      if (isWeb) window.localStorage.setItem(key, value)
      else await SecureStore.setItemAsync(key, value)
    } catch {
      /* a storage failure must not crash the app */
    }
  },
  async remove(key) {
    try {
      if (isWeb) window.localStorage.removeItem(key)
      else await SecureStore.deleteItemAsync(key)
    } catch {
      /* noop */
    }
  },
}

/**
 * Where the API lives.
 *
 * A phone cannot resolve `localhost` to the development machine — that would
 * be the phone itself, which is why "cannot reach the clinic" was the usual
 * first experience of scanning the QR code.
 *
 * So the address is worked out rather than configured: Expo already tells the
 * app which machine served the bundle (`hostUri`), and the API sits on that
 * same machine on port 8000. Scan the QR and it connects — no environment
 * variable, and nothing to forget when the laptop's IP changes.
 *
 * EXPO_PUBLIC_API_URL still wins when set, which is what a real deployment
 * uses. It is trimmed because `set VAR=value` on Windows keeps the space
 * before the newline, and "…/api/v1 " turns every path into a 404 that looks
 * like an empty app rather than a typo.
 */
function resolveApiBase() {
  const configured = process.env.EXPO_PUBLIC_API_URL;
  if (configured) return configured.trim().replace(/\/+$/, '');

  // '10.0.2.15:8081' while developing; absent in a production build.
  const hostUri =
    Constants.expoConfig?.hostUri ||
    Constants.expoGoConfig?.debuggerHost ||
    '';

  const host = hostUri.split(':')[0];

  return host ? `http://${host}:8000/api/v1` : 'http://localhost:8000/api/v1';
}

export const API_BASE = resolveApiBase()

export class ApiError extends Error {
  constructor(message, status, code, fields) {
    super(message)
    this.status = status
    this.code = code
    this.fields = fields || null
  }
  get fieldMessages() {
    return this.fields ? Object.values(this.fields).flat() : []
  }
}

export const auth = {
  async tokens() {
    return {
      access: await store.get(ACCESS),
      refresh: await store.get(REFRESH),
      org: await store.get(ORG),
    }
  },
  async save({ access_token, refresh_token }) {
    if (access_token) await store.set(ACCESS, access_token)
    if (refresh_token) await store.set(REFRESH, refresh_token)
  },
  async saveOrg(id) {
    if (id != null) await store.set(ORG, String(id))
  },
  async clear() {
    await Promise.all([store.remove(ACCESS), store.remove(REFRESH), store.remove(ORG)])
  },
  async isSignedIn() {
    return Boolean(await store.get(ACCESS))
  },
}

let refreshing = null

async function raw(path, { method = 'GET', body, withAuth = true } = {}) {
  const headers = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  if (withAuth) {
    const { access, org } = await auth.tokens()
    if (access) headers.Authorization = `Bearer ${access}`
    if (org) headers['X-Organization-Id'] = org
  }
  headers['X-Device-Name'] = `MediFlow app (${Platform.OS})`

  let response
  try {
    response = await fetch(`${API_BASE}${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    // A phone loses signal constantly; say so plainly instead of showing a
    // stack trace.
    throw new ApiError('Cannot reach the clinic. Check your connection.', 0, 'offline')
  }

  const text = await response.text()
  let payload = null
  try {
    payload = text ? JSON.parse(text) : null
  } catch {
    throw new ApiError('Unexpected response from the server.', response.status, 'bad_response')
  }

  if (!response.ok) {
    const err = payload?.error || {}
    throw new ApiError(
      err.message || `Request failed (${response.status})`,
      response.status,
      err.code || 'error',
      err.fields,
    )
  }
  return payload
}

async function request(path, options = {}) {
  try {
    return await raw(path, options)
  } catch (error) {
    const { refresh } = await auth.tokens()
    const canRetry =
      error instanceof ApiError && error.status === 401 &&
      options.withAuth !== false && refresh && !options._retried

    if (!canRetry) throw error

    // One refresh at a time — the backend rotates refresh tokens, so a second
    // concurrent attempt would present one that has already been spent.
    refreshing ??= raw('/auth/refresh', {
      method: 'POST',
      body: { refresh_token: refresh },
      withAuth: false,
    })
      .then(async (res) => { await auth.save(res.data.auth); return res })
      .catch(async (e) => { await auth.clear(); throw e })
      .finally(() => { refreshing = null })

    await refreshing
    return raw(path, { ...options, _retried: true })
  }
}

export const api = {
  register: (name, email, password) =>
    request('/auth/register', { method: 'POST', body: { name, email, password }, withAuth: false }),
  login: (email, password) =>
    request('/auth/login', { method: 'POST', body: { email, password }, withAuth: false }),
  logout: () => request('/auth/logout', { method: 'POST' }),

  // ---- forgotten password (§11) ----
  forgotPassword: (email) =>
    request('/auth/forgot-password', { method: 'POST', body: { email }, withAuth: false }),
  resetPassword: (email, code, password) =>
    request('/auth/reset-password', { method: 'POST',
      body: { email, code, password }, withAuth: false }),

  logoutAll: () => request('/auth/logout-all', { method: 'POST' }),
  changePassword: (current_password, new_password) =>
    request('/auth/change-password', { method: 'POST', body: { current_password, new_password } }),

  // ---- the account itself, not the medical record ----
  sessions: () => request('/auth/sessions'),
  revokeSession: (id) => request(`/auth/sessions/${id}`, { method: 'DELETE' }),

  // Who am I, and which clinics can I see? Answers without a tenant, which
  // is what makes it usable while an account is still unattached.
  me: () => request('/me'),

  dashboard: () => request('/patient/dashboard'),
  profile: () => request('/patient/profile'),
  updateProfile: (body) => request('/patient/profile', { method: 'PUT', body }),

  appointments: (scope) =>
    request(`/patient/appointments${scope ? `?scope=${scope}` : ''}`),
  cancelAppointment: (id, reason) =>
    request(`/patient/appointments/${id}/cancel`, { method: 'POST', body: { reason } }),

  // ---- booking, from the patient's own app (§3) ----
  bookableDoctors: (search) =>
    request(`/patient/doctors${search ? `?search=${encodeURIComponent(search)}` : ''}`),
  doctorSlots: (doctorId, date) =>
    request(`/patient/doctors/${doctorId}/slots?date=${date}`),
  book: (doctor_id, scheduled_at, reason) =>
    request('/patient/appointments', {
      method: 'POST', body: { doctor_id, scheduled_at, reason },
    }),
  rescheduleAppointment: (id, scheduled_at, reason) =>
    request(`/patient/appointments/${id}/reschedule`, {
      method: 'POST', body: { scheduled_at, reason },
    }),

  /** The printable prescription (§4) — same delivery as a released report. */
  openPrescriptionPdf: (id, number = 'prescription') =>
    api.openFile(`/patient/prescriptions/${id}/pdf`, number),

  /** The printable invoice (§6). Drafts are refused by the API, not hidden here. */
  openInvoicePdf: (id, number = 'invoice') =>
    api.openFile(`/patient/invoices/${id}/pdf`, number),

  openDocument(id, title = 'report') {
    return api.openFile(`/patient/documents/${id}/download`, title)
  },

  /**
   * Open a released report — a lab PDF, an X-ray, a discharge summary (§3).
   *
   * It goes through the API with the patient's own token rather than a public
   * link: the file is a medical record, so the same access rules and the same
   * audit row apply as anywhere else it is read (§16, §19).
   *
   * On a phone the bytes are written to the app's own cache and handed to the
   * system viewer; on web the blob is opened in a tab. Neither path puts a
   * token in a URL, where it would end up in history and server logs.
   */
  async openFile(path, title = 'file') {
    const { access, org } = await auth.tokens()
    const url = `${API_BASE}${path}`
    const headers = {
      Authorization: `Bearer ${access}`,
      ...(org ? { 'X-Organization-Id': org } : {}),
    }

    if (isWeb) {
      const res = await fetch(url, { headers })
      if (!res.ok) throw new ApiError('That report could not be opened.', res.status, 'error')
      const blobUrl = URL.createObjectURL(await res.blob())
      window.open(blobUrl, '_blank')
      // Given back after the tab has had a chance to read it.
      setTimeout(() => URL.revokeObjectURL(blobUrl), 60000)
      return
    }

    // SDK 54's File/Directory API. The old FileSystem.downloadAsync now
    // throws at runtime, so this is the current call, not a preference.
    const { Directory, File, Paths } = require('expo-file-system')
    const Sharing = require('expo-sharing')

    const safe = String(title).replace(/[^a-z0-9._-]+/gi, '_').slice(0, 60) || 'report'
    const folder = new Directory(Paths.cache, 'reports')
    if (!folder.exists) folder.create({ intermediates: true })

    const target = new File(folder, `${safe}.pdf`)
    if (target.exists) target.delete()          // always fetch the current copy

    const saved = await File.downloadFileAsync(url, target, { headers })

    if (!(await Sharing.isAvailableAsync())) {
      throw new ApiError('This device cannot open the file.', 0, 'error')
    }
    await Sharing.shareAsync(saved.uri)
  },

  records: () => request('/patient/records'),
  prescriptions: () => request('/patient/prescriptions'),
  labResults: () => request('/patient/lab-results'),
  documents: () => request('/patient/documents'),

  bills: () => request('/patient/bills'),
  invoice: (id) => request(`/patient/invoices/${id}`),

  notifications: (unread) =>
    request(`/patient/notifications${unread ? '?unread=1' : ''}`),
  markRead: (id) =>
    request(id ? `/patient/notifications/${id}/read` : '/patient/notifications/read',
      { method: 'POST' }),
  /**
   * Clears from the inbox only — the clinic's record of having told you stays.
   *
   *   dismissNotifications()        every one already read
   *   dismissNotifications('all')   the entire inbox, read or not
   *   dismissNotifications(7)       just that one
   *   dismissNotifications([7, 9])  a hand-picked selection
   */
  dismissNotifications: (target) => {
    if (typeof target === 'number') {
      return request(`/patient/notifications/${target}`, { method: 'DELETE' })
    }
    if (Array.isArray(target)) {
      return request('/patient/notifications', { method: 'DELETE', body: { ids: target } })
    }
    // 'all' clears the whole inbox on the server — not just the page of
    // notifications this screen happens to have loaded.
    return request('/patient/notifications', {
      method: 'DELETE',
      body: target === 'all' ? { all: true } : undefined,
    })
  },
}
