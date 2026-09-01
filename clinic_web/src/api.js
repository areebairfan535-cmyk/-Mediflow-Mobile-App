/**
 * API client for the clinic app.
 *
 * Same auth behaviour as the admin console — Bearer access token,
 * X-Organization-Id tenant header, one-shot transparent refresh on 401 — with
 * the Phase 2 clinical endpoints added.
 *
 * Storage keys are namespaced separately so signing into the clinic app does
 * not disturb an admin session open in another tab.
 */

const ACCESS = 'mediflow.clinic.access'
const REFRESH = 'mediflow.clinic.refresh'
const ORG = 'mediflow.clinic.org'

export const tokens = {
  get access() { return localStorage.getItem(ACCESS) },
  get refresh() { return localStorage.getItem(REFRESH) },
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
  /** Flatten field errors into lines a form can render under its inputs. */
  get fieldMessages() {
    return this.fields ? Object.values(this.fields).flat() : []
  }
}

/** Only one refresh in flight — a rotating token cannot be spent twice. */
let refreshing = null

async function rawRequest(path, { method = 'GET', body, auth = true, org = true } = {}) {
  const headers = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (auth && tokens.access) headers.Authorization = `Bearer ${tokens.access}`
  if (org && tokens.org) headers['X-Organization-Id'] = String(tokens.org)
  headers['X-Device-Name'] = 'MediFlow Clinic'

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
      error instanceof ApiError && error.status === 401 &&
      options.auth !== false && tokens.refresh && !options._retried

    if (!canRetry) throw error

    refreshing ??= rawRequest('/auth/refresh', {
      method: 'POST',
      body: { refresh_token: tokens.refresh },
      auth: false, org: false,
    })
      .then((res) => { tokens.set(res.data.auth); return res })
      .catch((e) => { tokens.clear(); throw e })
      .finally(() => { refreshing = null })

    await refreshing
    return rawRequest(path, { ...options, _retried: true })
  }
}

const qs = (params = {}) => {
  const s = new URLSearchParams(
    Object.entries(params).filter(([, v]) => v !== '' && v != null),
  ).toString()
  return s ? `?${s}` : ''
}

/**
 * Open a generated PDF in a new tab.
 *
 * A plain <a href> cannot be used: the document needs the Bearer token and the
 * tenant header, so the bytes are fetched, turned into a blob and handed to the
 * browser. That also keeps the token out of the URL bar and the server log.
 */
export async function openPdf(path) {
  const response = await fetch(`/api/v1${path}`, {
    headers: {
      Authorization: `Bearer ${tokens.access}`,
      ...(tokens.org ? { 'X-Organization-Id': String(tokens.org) } : {}),
    },
  })

  if (!response.ok) {
    let message = `Could not open the document (${response.status})`
    try { message = (await response.json())?.error?.message || message } catch { /* not JSON */ }
    throw new ApiError(message, response.status, 'error')
  }

  const url = URL.createObjectURL(await response.blob())
  window.open(url, '_blank')
  setTimeout(() => URL.revokeObjectURL(url), 60000)
}

export const api = {
  // ---- auth ----
  login: (email, password) =>
    request('/auth/login', { method: 'POST', body: { email, password }, auth: false, org: false }),
  logout: () => request('/auth/logout', { method: 'POST' }),

  // ---- forgotten password (§11): both halves are public ----
  forgotPassword: (email) =>
    request('/auth/forgot-password', { method: 'POST', body: { email }, auth: false, org: false }),
  resetPassword: (email, code, password) =>
    request('/auth/reset-password', { method: 'POST',
      body: { email, code, password }, auth: false, org: false }),

  context: () => request('/me/context'),
  changePassword: (current_password, new_password) =>
    request('/auth/change-password', { method: 'POST', body: { current_password, new_password } }),
  sessions: () => request('/auth/sessions'),
  revokeSession: (id) => request(`/auth/sessions/${id}`, { method: 'DELETE' }),
  me: () => request('/me'),

  // ---- doctors ----
  doctors: (params) => request(`/doctors${qs(params)}`),
  doctor: (id) => request(`/doctors/${id}`),
  doctorDashboard: () => request('/doctors/dashboard'),
  schedule: (id) => request(`/doctors/${id}/schedule`),
  saveSchedule: (id, slots) => request(`/doctors/${id}/schedule`, { method: 'PUT', body: { slots } }),
  availableSlots: (id, date) => request(`/doctors/${id}/available-slots${qs({ date })}`),

  // ---- patients ----
  patients: (params) => request(`/patients${qs(params)}`),
  patient: (id) => request(`/patients/${id}`),
  createPatient: (body) => request('/patients', { method: 'POST', body }),
  updatePatient: (id, body) => request(`/patients/${id}`, { method: 'PUT', body }),
  addAllergy: (id, body) => request(`/patients/${id}/allergies`, { method: 'POST', body }),
  removeAllergy: (id, allergyId) =>
    request(`/patients/${id}/allergies/${allergyId}`, { method: 'DELETE' }),
  addCondition: (id, body) => request(`/patients/${id}/conditions`, { method: 'POST', body }),
  patientPrescriptions: (id) => request(`/patients/${id}/prescriptions`),

  // ---- appointments ----
  appointments: (params) => request(`/appointments${qs(params)}`),
  appointment: (id) => request(`/appointments/${id}`),
  book: (body) => request('/appointments', { method: 'POST', body }),
  setAppointmentStatus: (id, status, reason) =>
    request(`/appointments/${id}/status`, { method: 'PUT', body: { status, reason } }),

  // ---- encounters ----
  encounters: (params) => request(`/encounters${qs(params)}`),
  encounter: (id) => request(`/encounters/${id}`),
  startEncounter: (body) => request('/encounters', { method: 'POST', body }),
  updateEncounter: (id, body) => request(`/encounters/${id}`, { method: 'PUT', body }),
  completeEncounter: (id, body) => request(`/encounters/${id}/complete`, { method: 'POST', body }),
  cancelEncounter: (id, reason) =>
    request(`/encounters/${id}/cancel`, { method: 'POST', body: { reason } }),
  addDiagnosis: (id, body) => request(`/encounters/${id}/diagnoses`, { method: 'POST', body }),
  addProcedure: (id, body) => request(`/encounters/${id}/procedures`, { method: 'POST', body }),
  orderLab: (id, body) => request(`/encounters/${id}/lab-orders`, { method: 'POST', body }),
  removeChild: (id, kind, childId) =>
    request(`/encounters/${id}/${kind}/${childId}`, { method: 'DELETE' }),

  // ---- prescriptions ----
  medications: (search) => request(`/prescriptions/medications${qs({ search })}`),
  prescription: (id) => request(`/prescriptions/${id}`),
  createPrescription: (body) => request('/prescriptions', { method: 'POST', body }),
  updatePrescription: (id, body) => request(`/prescriptions/${id}`, { method: 'PUT', body }),
  issuePrescription: (id) => request(`/prescriptions/${id}/issue`, { method: 'POST' }),

  // ---- labs ----
  labOrders: (params) => request(`/lab-orders${qs(params)}`),
  recordLabResults: (id, results) =>
    request(`/lab-orders/${id}/results`, { method: 'POST', body: { results } }),

  // ---- billing (Phase 3) ----
  services: (params) => request(`/services${qs(params)}`),
  createService: (body) => request('/services', { method: 'POST', body }),
  updateService: (id, body) => request(`/services/${id}`, { method: 'PUT', body }),
  addServicePrice: (id, body) => request(`/services/${id}/prices`, { method: 'POST', body }),
  invoices: (params) => request(`/invoices${qs(params)}`),
  invoice: (id) => request(`/invoices/${id}`),
  createInvoice: (body) => request('/invoices', { method: 'POST', body }),
  updateInvoice: (id, body) => request(`/invoices/${id}`, { method: 'PUT', body }),
  issueInvoice: (id, body = {}) => request(`/invoices/${id}/issue`, { method: 'POST', body }),
  cancelInvoice: (id, reason) =>
    request(`/invoices/${id}/cancel`, { method: 'POST', body: { reason } }),
  invoiceFromEncounter: (encounterId, body = {}) =>
    request(`/encounters/${encounterId}/invoice`, { method: 'POST', body }),
  recordPayment: (invoiceId, body) =>
    request(`/invoices/${invoiceId}/payments`, { method: 'POST', body }),
  payments: (params) => request(`/payments${qs(params)}`),
  requestRefund: (paymentId, body) =>
    request(`/payments/${paymentId}/refunds`, { method: 'POST', body }),
  financialReport: (params) => request(`/reports/financial${qs(params)}`),

  // ---- insurance & claims (Phase 5) ----
  insurers: () => request('/insurance/providers'),
  policies: (patientId) => request(`/patients/${patientId}/policies`),
  createPolicy: (patientId, body) =>
    request(`/patients/${patientId}/policies`, { method: 'POST', body }),
  updatePolicy: (id, body) => request(`/insurance/policies/${id}`, { method: 'PUT', body }),
  eligibility: (invoiceId, policyId) =>
    request(`/invoices/${invoiceId}/eligibility${qs({ policy_id: policyId })}`),
  quote: (patient_id, amount) =>
    request('/insurance/check', { method: 'POST', body: { patient_id, amount } }),

  claims: (params) => request(`/claims${qs(params)}`),
  claim: (id) => request(`/claims/${id}`),
  claimPipeline: () => request('/claims/pipeline'),
  createClaim: (body) => request('/claims', { method: 'POST', body }),
  submitClaim: (id, external_claim_no) =>
    request(`/claims/${id}/submit`, { method: 'POST', body: { external_claim_no } }),
  claimProcessing: (id) => request(`/claims/${id}/processing`, { method: 'POST' }),
  decideClaim: (id, body) => request(`/claims/${id}/decision`, { method: 'POST', body }),
  payClaim: (id, body) => request(`/claims/${id}/paid`, { method: 'POST', body }),
  resubmitClaim: (id) => request(`/claims/${id}/resubmit`, { method: 'POST' }),

  // ---- clinical notes (§5) ----
  addNote: (encounterId, body) =>
    request(`/encounters/${encounterId}/notes`, { method: 'POST', body }),

  // ---- AI assistants (Phase 6, §9) ----
  //
  // Drafting and approving are separate calls on purpose: nothing the model
  // produces reaches the record without a person calling approveNote.
  aiStatus: () => request('/ai/status'),
  aiDraftNote: (encounterId, shorthand) =>
    request(`/encounters/${encounterId}/ai/draft-note`, {
      method: 'POST', body: { shorthand },
    }),
  approveNote: (noteId, body) =>
    request(`/clinical-notes/${noteId}/approve`, {
      method: 'POST', body: body == null ? {} : { body },
    }),
  discardNote: (noteId) => request(`/clinical-notes/${noteId}`, { method: 'DELETE' }),
  aiBillingSuggestions: (encounterId) =>
    request(`/encounters/${encounterId}/ai/billing-suggestions`),
  aiClaimReview: (claimId) => request(`/claims/${claimId}/ai/review`),
  aiPatientSummary: (patientId) => request(`/patients/${patientId}/ai/summary`),

  // §25: one box across patients, invoices, prescriptions, diagnoses and
  // medicines. No provider involved, so it works with AI switched off.
  search: (q) => request(`/search?q=${encodeURIComponent(q)}`),
}
