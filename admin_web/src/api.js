/**
 * API client for the MediFlow backend.
 *
 * Handles the two things every call needs:
 *   - the Bearer access token, and the X-Organization-Id tenant header
 *   - transparent refresh: on a 401 it spends the refresh token once, then
 *     replays the original request with the new access token
 *
 * Tokens live in localStorage. That is right for an internal admin console on
 * a trusted machine; the patient mobile app uses expo-secure-store instead.
 */

const ACCESS = 'mediflow.access'
const REFRESH = 'mediflow.refresh'
const ORG = 'mediflow.org'

export const tokens = {
  get access() {
    return localStorage.getItem(ACCESS)
  },
  get refresh() {
    return localStorage.getItem(REFRESH)
  },
  get org() {
    const v = localStorage.getItem(ORG)
    return v ? Number(v) : null
  },
  set({ access_token, refresh_token }) {
    if (access_token) localStorage.setItem(ACCESS, access_token)
    if (refresh_token) localStorage.setItem(REFRESH, refresh_token)
  },
  setOrg(id) {
    if (id == null) localStorage.removeItem(ORG)
    else localStorage.setItem(ORG, String(id))
  },
  clear() {
    localStorage.removeItem(ACCESS)
    localStorage.removeItem(REFRESH)
    localStorage.removeItem(ORG)
  },
}

export class ApiError extends Error {
  constructor(message, status, code, fields) {
    super(message)
    this.status = status
    this.code = code
    this.fields = fields || null
  }

  /**
   * Field errors flattened into lines.
   *
   * Some refusals carry their reason only here — a plan downgrade names each
   * limit that is in the way — so a screen that renders `message` alone shows
   * "Validation failed" and throws the answer away.
   */
  get fieldMessages() {
    return this.fields ? Object.values(this.fields).flat() : []
  }
}

/** Only one refresh may be in flight — a rotating token cannot be spent twice. */
let refreshing = null

async function rawRequest(path, { method = 'GET', body, auth = true, org = true } = {}) {
  const headers = { Accept: 'application/json' }

  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (auth && tokens.access) headers.Authorization = `Bearer ${tokens.access}`
  if (org && tokens.org) headers['X-Organization-Id'] = String(tokens.org)

  headers['X-Device-Name'] = 'MediFlow Admin Console'

  const response = await fetch(`/api/v1${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  })

  const text = await response.text()
  let payload = null
  try {
    payload = text ? JSON.parse(text) : null
  } catch {
    throw new ApiError('Server returned a non-JSON response', response.status, 'bad_response')
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

export async function request(path, options = {}) {
  try {
    return await rawRequest(path, options)
  } catch (error) {
    const canRetry =
      error instanceof ApiError &&
      error.status === 401 &&
      options.auth !== false &&
      tokens.refresh &&
      !options._retried

    if (!canRetry) throw error

    // Coalesce concurrent refreshes: the backend rotates the refresh token, so
    // a second parallel attempt would present an already-spent one and fail.
    refreshing ??= rawRequest('/auth/refresh', {
      method: 'POST',
      body: { refresh_token: tokens.refresh },
      auth: false,
      org: false,
    })
      .then((res) => {
        tokens.set(res.data.auth)
        return res
      })
      .catch((e) => {
        tokens.clear()
        throw e
      })
      .finally(() => {
        refreshing = null
      })

    await refreshing
    return rawRequest(path, { ...options, _retried: true })
  }
}

export const api = {
  login: (email, password) =>
    request('/auth/login', { method: 'POST', body: { email, password }, auth: false, org: false }),

  logout: () => request('/auth/logout', { method: 'POST' }),

  // ---- forgotten password (§11): both halves are public ----
  forgotPassword: (email) =>
    request('/auth/forgot-password', { method: 'POST', body: { email }, auth: false, org: false }),
  resetPassword: (email, code, password) =>
    request('/auth/reset-password', { method: 'POST',
      body: { email, code, password }, auth: false, org: false }),


  // ---- onboarding (§22): plan and market are readable before an account ----
  publicPlans: () => request('/public/plans', { auth: false, org: false }),
  publicCountries: () => request('/public/countries', { auth: false, org: false }),
  register: (body) => request('/auth/register', { method: 'POST', body, auth: false, org: false }),
  createOrganization: (body) => request('/organizations', { method: 'POST', body, org: false }),

  me: () => request('/me'),
  context: () => request('/me/context'),

  health: () => request('/health', { auth: false, org: false }),

  changePassword: (current_password, new_password) =>
    request('/auth/change-password', { method: 'POST', body: { current_password, new_password } }),
  sessions: () => request('/auth/sessions'),
  revokeSession: (id) => request(`/auth/sessions/${id}`, { method: 'DELETE' }),

  organization: () => request('/organizations/current'),
  members: () => request('/organizations/current/members'),
  roles: () => request('/organizations/current/roles'),
  addMember: (payload) =>
    request('/organizations/current/members', { method: 'POST', body: payload }),
  changeMemberRole: (userId, roleId) =>
    request(`/organizations/current/members/${userId}/role`, {
      method: 'PUT',
      body: { role_id: roleId },
    }),
  changeMemberStatus: (userId, status) =>
    request(`/organizations/current/members/${userId}/status`, {
      method: 'PUT',
      body: { status },
    }),

  auditLogs: (params = {}) => {
    const qs = new URLSearchParams(
      Object.entries(params).filter(([, v]) => v !== '' && v != null),
    ).toString()
    return request(`/audit-logs${qs ? `?${qs}` : ''}`)
  },

  platformDashboard: () => request('/platform/dashboard'),
  platformOrganizations: (params = {}) => {
    const qs = new URLSearchParams(
      Object.entries(params).filter(([, v]) => v !== '' && v != null),
    ).toString()
    return request(`/platform/organizations${qs ? `?${qs}` : ''}`)
  },
  setOrganizationStatus: (id, status) =>
    request(`/platform/organizations/${id}/status`, { method: 'PUT', body: { status } }),

  // ---- platform: the price list and the markets (§21, §22, §23) ----
  platformPlans: () => request('/platform/plans'),
  createPlatformPlan: (body) => request('/platform/plans', { method: 'POST', body }),
  updatePlatformPlan: (id, body) => request(`/platform/plans/${id}`, { method: 'PUT', body }),
  setOrganizationPlan: (id, planId) =>
    request(`/platform/organizations/${id}/plan`, { method: 'PUT', body: { plan_id: planId } }),

  platformCountries: () => request('/platform/countries'),
  createCountry: (body) => request('/platform/countries', { method: 'POST', body }),
  updateCountry: (id, body) => request(`/platform/countries/${id}`, { method: 'PUT', body }),

  // ---- subscription & plans (§22) ----
  plans: () => request('/plans'),
  subscription: () => request('/organizations/current/subscription'),
  changePlan: (planId) =>
    request('/organizations/current/subscription', { method: 'PUT', body: { plan_id: planId } }),
}
