import { useCallback, useState } from 'react'
import { Pressable, ScrollView, Text, View } from 'react-native'
import { useFocusEffect } from 'expo-router'
import { api } from '../../src/api'
import {
  Badge, Card, EmptyState, ErrorBox, Loading, c, s, dateOnly,
} from '../../src/ui'

const TABS = [
  { key: 'visits', label: 'Visits' },
  { key: 'prescriptions', label: 'Medicines' },
  { key: 'labs', label: 'Lab results' },
  { key: 'documents', label: 'Reports' },
]

/**
 * §3 medical records: visit history, prescriptions and lab results.
 *
 * Clinical notes are deliberately absent — §5 treats them as the clinician's
 * working record. What the clinic chooses to release comes through
 * medical_documents instead.
 */
export default function Records() {
  const [tab, setTab] = useState('visits')
  const [state, setState] = useState({ loading: true })

  const load = useCallback(async (which = tab) => {
    // Returning to the same tab keeps what is on screen while it refreshes;
    // switching tabs must not show Visits under the Reports heading, so the
    // list is cleared when the tab actually changes.
    setState((s) => ({ ...s, loading: !s.rows || s.tab !== which,
                       rows: s.tab === which ? s.rows : undefined }))
    try {
      const res = which === 'visits' ? await api.records()
        : which === 'prescriptions' ? await api.prescriptions()
          : which === 'documents' ? await api.documents()
            : await api.labResults()

      setState({
        loading: false,
        tab: which,
        rows: res.data.encounters || res.data.prescriptions
          || res.data.lab_orders || res.data.documents || [],
      })
    } catch (error) {
      setState({ loading: false, error })
    }
  }, [tab])

  useFocusEffect(useCallback(() => { load() }, [load]))

  return (
    <ScrollView style={s.screen} contentContainerStyle={s.content}>
      <View style={[s.row, { marginBottom: 14 }]}>
        {TABS.map((t) => (
          <Pressable
            key={t.key}
            onPress={() => { setTab(t.key); load(t.key) }}
            style={[
              s.btnGhost,
              { flex: 1, paddingHorizontal: 6 },
              tab === t.key && { backgroundColor: c.accent, borderColor: c.accent },
            ]}
          >
            <Text style={[s.btnGhostText, { fontSize: 12.5 }, tab === t.key && { color: '#fff' }]}>
              {t.label}
            </Text>
          </Pressable>
        ))}
      </View>

      {state.loading ? (
        <Loading />
      ) : state.error ? (
        <ErrorBox error={state.error} />
      ) : state.rows.length === 0 ? (
        <Card>
          <EmptyState
            icon={tab === 'visits' ? '📋' : tab === 'prescriptions' ? '💊'
              : tab === 'documents' ? '📄' : '🧪'}
            title={`No ${tab === 'visits' ? 'visits' : tab === 'prescriptions' ? 'prescriptions'
              : tab === 'documents' ? 'reports' : 'lab results'} yet`}
            hint={tab === 'documents'
              ? 'Reports the clinic releases to you appear here.' : undefined}
          />
        </Card>
      ) : tab === 'visits' ? (
        state.rows.map((e) => <Visit key={e.id} e={e} />)
      ) : tab === 'prescriptions' ? (
        state.rows.map((rx) => <Prescription key={rx.id} rx={rx} />)
      ) : tab === 'documents' ? (
        state.rows.map((d) => <Document key={d.id} d={d} />)
      ) : (
        state.rows.map((lo) => <LabOrder key={lo.id} lo={lo} />)
      )}
    </ScrollView>
  )
}

function Visit({ e }) {
  const vitals = [
    e.bp_systolic && e.bp_diastolic ? `BP ${e.bp_systolic}/${e.bp_diastolic}` : null,
    e.pulse ? `Pulse ${e.pulse}` : null,
    e.temperature_c ? `${e.temperature_c}°C` : null,
    e.weight_kg ? `${e.weight_kg} kg` : null,
  ].filter(Boolean)

  return (
    <Card>
      <View style={s.spread}>
        <Text style={s.docNo}>{e.encounter_no}</Text>
        <Text style={s.mono}>{dateOnly(e.completed_at || e.created_at)}</Text>
      </View>

      <Text style={[s.h2, { marginTop: 6 }]}>{e.chief_complaint || 'Consultation'}</Text>
      <Text style={s.muted}>
        <Text style={{ fontWeight: "700", color: c.ink }}>{e.doctor_name}</Text>
        {" · "}{e.specialty}
      </Text>

      {e.diagnoses?.length > 0 && (
        <View style={{ marginTop: 10 }}>
          <Text style={s.subSection}>Diagnosis</Text>
          {e.diagnoses.map((d, i) => (
            <Text key={i} style={[s.body, { marginTop: 3 }]}>
              • {d.description}
              {d.icd10_code ? <Text style={s.mono}>  {d.icd10_code}</Text> : null}
            </Text>
          ))}
        </View>
      )}

      {e.procedures?.length > 0 && (
        <View style={{ marginTop: 10 }}>
          <Text style={s.subSection}>Procedures</Text>
          {e.procedures.map((p, i) => (
            <Text key={i} style={[s.body, { marginTop: 3 }]}>
              • {p.name}{p.site ? ` (${p.site})` : ''}
            </Text>
          ))}
        </View>
      )}

      {vitals.length > 0 && (
        <Text style={[s.muted, { marginTop: 10 }]}>{vitals.join('  ·  ')}</Text>
      )}

      {e.followup_on ? (
        <Text style={[s.muted, { color: c.warn }]}>
          Follow-up due {dateOnly(e.followup_on)}
        </Text>
      ) : null}
    </Card>
  )
}

function Prescription({ rx }) {
  return (
    <Card>
      <View style={s.spread}>
        <Text style={s.docNo}>{rx.prescription_no}</Text>
        <View style={[s.row, { gap: 8 }]}>
          {rx.status === 'issued' && (
            <Pressable onPress={() => api.openPrescriptionPdf(rx.id, rx.prescription_no)}>
              <Text style={{ color: c.accentDark, fontWeight: '700', fontSize: 13 }}>PDF</Text>
            </Pressable>
          )}
          <Badge>{rx.status}</Badge>
        </View>
      </View>
      <Text style={s.muted}>
        <Text style={{ fontWeight: "700", color: c.ink }}>{rx.doctor_name}</Text>
        {" · "}{dateOnly(rx.issued_at || rx.created_at)}
      </Text>

      <View style={{ marginTop: 10 }}>
        {rx.items.map((it) => (
          <View key={it.id} style={{
            marginTop: 8, paddingLeft: 10,
            borderLeftWidth: 2, borderLeftColor: c.accentSoft,
          }}>
            <Text style={{ fontWeight: '700', color: c.ink, fontSize: 14.5 }}>
              {it.medication_name}
            </Text>
            <Text style={s.body}>
              {[it.dosage, it.frequency, it.duration].filter(Boolean).join(' · ') || '—'}
            </Text>
            {it.instructions ? (
              <Text style={[s.muted, { marginTop: 1 }]}>{it.instructions}</Text>
            ) : null}
          </View>
        ))}
      </View>

      {rx.general_advice ? (
        <Text style={[s.muted, { marginTop: 12 }]}>Advice: {rx.general_advice}</Text>
      ) : null}
    </Card>
  )
}

function LabOrder({ lo }) {
  return (
    <Card>
      <View style={s.spread}>
        <Text style={s.docNo}>{lo.order_no}</Text>
        <Badge>{lo.status}</Badge>
      </View>
      <Text style={s.muted}>{dateOnly(lo.completed_at || lo.created_at)}</Text>

      {lo.results?.length > 0 && (
        <View style={{ marginTop: 10 }}>
          {lo.results.map((r) => (
            <View key={r.id} style={[s.spread, { marginTop: 7 }]}>
              <View style={{ flex: 1 }}>
                <Text style={{ fontWeight: '600', color: c.ink }}>{r.test_name}</Text>
                {r.reference_range ? (
                  <Text style={s.mono}>ref {r.reference_range}</Text>
                ) : null}
              </View>
              <View style={{ alignItems: 'flex-end' }}>
                <Text style={{ fontWeight: '700', color: c.ink }}>
                  {r.value} {r.unit || ''}
                </Text>
                {r.flag && r.flag !== 'normal' ? (
                  <Badge tone={r.flag === 'critical' ? 'danger' : 'warn'}>{r.flag}</Badge>
                ) : null}
              </View>
            </View>
          ))}
        </View>
      )}
    </Card>
  )
}

/**
 * A report the clinic has released — a lab PDF, an X-ray, a discharge summary.
 *
 * Opening it goes through the API rather than a public link: the file needs
 * the patient's own token, and the download is audited like every other look
 * at a medical record (§16).
 */
function Document({ d }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function open() {
    setBusy(true)
    setError(null)
    try {
      await api.openDocument(d.id, d.title)
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <View style={s.spread}>
        <View style={{ flex: 1, paddingRight: 10 }}>
          <Text style={s.itemName}>{d.title}</Text>
          <Text style={s.muted}>
            {String(d.category || '').replace(/_/g, ' ')}
            {d.size_bytes ? ` · ${Math.max(1, Math.round(d.size_bytes / 1024))} KB` : ''}
            {d.created_at ? ` · ${dateOnly(d.created_at)}` : ''}
          </Text>
        </View>
        <Pressable onPress={open} disabled={busy}
                   style={[s.btnGhost, busy && s.btnDisabled]}>
          <Text style={[s.btnGhostText, { color: c.accentDark, fontWeight: '700' }]}>
            {busy ? 'Opening…' : 'Open'}
          </Text>
        </Pressable>
      </View>
      {error ? <Text style={[s.muted, { color: c.danger, marginTop: 6 }]}>{error}</Text> : null}
    </Card>
  )
}
