import { ActivityIndicator, StyleSheet, Text, View } from 'react-native'

/** Shared design tokens — one palette, so screens stay consistent. */
export const c = {
  bg: '#f4f7fb',
  surface: '#ffffff',
  border: '#e3e8ef',
  ink: '#0f1c2e',
  body: '#445068',
  muted: '#7c8798',
  accent: '#0d7ea8',
  accentDark: '#0a6486',
  accentSoft: '#e6f3f9',
  ok: '#0f8a5f',
  okSoft: '#e4f6ee',
  warn: '#b46a00',
  warnSoft: '#fdf3e2',
  danger: '#c1362f',
  dangerSoft: '#fdecea',
}

const TONE = {
  active: 'ok', booked: 'accent', confirmed: 'accent', arrived: 'warn',
  in_consultation: 'warn', completed: 'ok', cancelled: 'danger', no_show: 'danger',
  issued: 'accent', partially_paid: 'warn', paid: 'ok', overdue: 'danger', refunded: 'warn',
  ordered: 'accent', processing: 'warn',
}

export function Badge({ children, tone }) {
  const key = String(children ?? '').toLowerCase().replace(/\s/g, '_')
  const resolved = tone || TONE[key] || 'neutral'
  const palette = {
    ok: [c.okSoft, c.ok],
    warn: [c.warnSoft, c.warn],
    danger: [c.dangerSoft, c.danger],
    accent: [c.accentSoft, c.accentDark],
    neutral: [c.bg, c.body],
  }[resolved]

  return (
    <View style={[s.badge, { backgroundColor: palette[0] }]}>
      <Text style={[s.badgeText, { color: palette[1] }]}>
        {String(children).replace(/_/g, ' ')}
      </Text>
    </View>
  )
}

export function Card({ children, style }) {
  return <View style={[s.card, style]}>{children}</View>
}

/**
 * A section heading, styled as a small header band rather than a caption.
 *
 * The blue rule down the left is what turns a line of text into a heading: on
 * a screen that is one card after another, colour alone was not enough to say
 * "a new part starts here".
 */
export function SectionTitle({ children, action }) {
  return (
    <View style={s.sectionRow}>
      <Text style={s.section}>{children}</Text>
      {action}
    </View>
  )
}

export function Loading({ label = 'Loading…' }) {
  return (
    <View style={s.center}>
      <ActivityIndicator color={c.accent} size="large" />
      <Text style={s.muted}>{label}</Text>
    </View>
  )
}

export function EmptyState({ icon = '—', title, hint }) {
  return (
    <View style={s.empty}>
      <Text style={s.emptyIcon}>{icon}</Text>
      <Text style={s.emptyTitle}>{title}</Text>
      {hint ? <Text style={s.muted}>{hint}</Text> : null}
    </View>
  )
}

export function ErrorBox({ error }) {
  if (!error) return null
  return (
    <View style={s.errorBox}>
      <Text style={s.errorText}>{error.message}</Text>
      {error.fieldMessages?.map((m, i) => (
        <Text key={i} style={s.errorText}>• {m}</Text>
      ))}
    </View>
  )
}

/**
 * The API sends UTC without a zone marker. Parse it as UTC, then render in
 * the device's locale — a patient reads times where they are standing.
 */
function toDate(value) {
  if (!value) return null
  const str = String(value)
  const iso = str.includes('T') ? str : str.replace(' ', 'T')
  const d = new Date(iso.endsWith('Z') ? iso : `${iso}Z`)
  return Number.isNaN(d.getTime()) ? null : d
}

export function when(value) {
  const d = toDate(value)
  if (!d) return '—'
  return d.toLocaleString(undefined, {
    weekday: 'short', day: '2-digit', month: 'short',
    hour: '2-digit', minute: '2-digit',
  })
}

/** Just the clock time — for slot chips, where the day is already chosen. */
export function timeOnly(value) {
  const d = toDate(value)
  return d ? d.toLocaleTimeString(undefined,
    { hour: '2-digit', minute: '2-digit' }) : '—'
}

export function dateOnly(value) {
  const d = toDate(value)
  return d ? d.toLocaleDateString(undefined,
    { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
}

export function money(amount, currency = '') {
  const n = Number(amount ?? 0)
  return `${currency ? currency + ' ' : ''}${n.toLocaleString(undefined, {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
  })}`
}

export const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: c.bg },
  content: { padding: 16, paddingBottom: 40 },

  h1: { fontSize: 24, fontWeight: '700', color: c.ink, letterSpacing: -0.4 },
  h2: { fontSize: 17, fontWeight: '700', color: c.ink },
  body: { fontSize: 14.5, color: c.body, lineHeight: 21 },
  muted: { fontSize: 13, color: c.muted, marginTop: 6 },
  mono: { fontFamily: 'monospace', fontSize: 12.5, color: c.muted },

  // A document number — INV-000093, the MRN, a prescription or lab-order
  // number. This is what a patient reads out when they phone the clinic, so it
  // is identity, not small print: same monospace as `mono`, but sized and
  // coloured to be found at a glance. Quantities, dates and reference ranges
  // stay on `mono`, where being quiet is correct.
  docNo: {
    fontFamily: 'monospace', fontSize: 14, fontWeight: '700',
    color: c.ink, letterSpacing: 0.3,
  },

  // A section heading is a filled blue band, the same blue as the screen
  // header at the top — so a long scrolling page reads as a stack of labelled
  // parts rather than one run of cards.
  sectionRow: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
    backgroundColor: c.accentDark, borderRadius: 10,
    paddingVertical: 9, paddingHorizontal: 13,
    marginTop: 20, marginBottom: 10,
  },
  // Section headings ("Invoices", "Payment history", "Prescriptions"…) are the
  // signposts on a long scrolling screen. In muted grey they sank into the
  // page; in the accent blue they carry the eye down it. Changed here rather
  // than on one screen so Bills does not end up with one blue heading and two
  // grey ones sitting under it.
  section: {
    fontSize: 13.5, fontWeight: '800', color: '#fff',
    textTransform: 'uppercase', letterSpacing: 0.8,
  },

  // The name of the thing a card is about — the condition, the insurer, the
  // allergen, the doctor. It is what the eye should land on first inside the
  // card, so it outweighs the detail lines under it.
  itemName: { fontSize: 15.5, fontWeight: '700', color: c.ink },

  // A heading INSIDE a card ("Diagnosis", "Procedures" within one visit).
  // It cannot use `section`: that text is white because it sits on the blue
  // band, and white on a white card is an invisible heading.
  subSection: {
    fontSize: 12, fontWeight: '800', color: c.accentDark,
    textTransform: 'uppercase', letterSpacing: 0.7, marginBottom: 2,
  },

  card: {
    backgroundColor: c.surface, borderRadius: 12, padding: 15,
    borderWidth: 1, borderColor: c.border, marginBottom: 10,
  },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  spread: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },

  badge: { paddingHorizontal: 9, paddingVertical: 3, borderRadius: 20, alignSelf: 'flex-start' },
  badgeText: { fontSize: 11.5, fontWeight: '700' },

  btn: {
    backgroundColor: c.accent, borderRadius: 10, paddingVertical: 14,
    alignItems: 'center', justifyContent: 'center',
  },
  btnText: { color: '#fff', fontWeight: '700', fontSize: 15 },
  btnGhost: {
    backgroundColor: c.surface, borderWidth: 1, borderColor: c.border,
    borderRadius: 10, paddingVertical: 11, paddingHorizontal: 14, alignItems: 'center',
  },
  btnGhostText: { color: c.body, fontWeight: '600', fontSize: 13.5 },
  btnDisabled: { opacity: 0.5 },

  input: {
    backgroundColor: c.surface, borderWidth: 1, borderColor: c.border,
    borderRadius: 10, paddingHorizontal: 13, paddingVertical: 12,
    fontSize: 15, color: c.ink,
  },
  // Form labels match the read-only Field labels — the same question should
  // not change weight just because the screen went into edit mode.
  label: { fontSize: 13.5, fontWeight: '700', color: c.ink, marginBottom: 6, marginTop: 12 },

  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 40 },
  empty: { alignItems: 'center', padding: 34 },
  emptyIcon: { fontSize: 30, opacity: 0.4, marginBottom: 8 },
  emptyTitle: { fontSize: 15, fontWeight: '600', color: c.body },

  errorBox: {
    backgroundColor: c.dangerSoft, borderWidth: 1, borderColor: 'rgba(193,54,47,0.2)',
    borderRadius: 10, padding: 12, marginBottom: 14,
  },
  errorText: { color: c.danger, fontSize: 13.5 },

  // The allergy banner is the loudest thing in the app on purpose.
  alertBanner: {
    backgroundColor: c.dangerSoft, borderLeftWidth: 4, borderLeftColor: c.danger,
    borderRadius: 10, padding: 13, marginBottom: 12,
  },
  alertTitle: {
    color: c.danger, fontWeight: '700', fontSize: 11.5,
    textTransform: 'uppercase', letterSpacing: 0.7, marginBottom: 5,
  },
  alertItem: { color: c.ink, fontSize: 14, marginTop: 2 },
})
