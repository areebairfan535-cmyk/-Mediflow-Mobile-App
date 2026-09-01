import { useCallback, useState } from 'react'
import {
  Pressable, RefreshControl, ScrollView, Text, View,
} from 'react-native'
import { useFocusEffect, useRouter } from 'expo-router'
import { api } from '../../src/api'
import {
  Badge, Card, EmptyState, ErrorBox, Loading, SectionTitle,
  c, s, when, dateOnly, money,
} from '../../src/ui'

/**
 * §3 dashboard: upcoming appointments, outstanding bills, recent
 * prescriptions, medical alerts and treatment reminders.
 *
 * One API call fills the whole screen — a phone on a weak connection should
 * not make six round trips to draw a home page.
 */
export default function Home() {
  const router = useRouter()
  const [state, setState] = useState({ loading: true })
  const [refreshing, setRefreshing] = useState(false)

  const load = useCallback(async (isRefresh = false) => {
    if (!isRefresh) setState((s) => ({ ...s, loading: !s.d }))
    try {
      const res = await api.dashboard()
      setState({ loading: false, d: res.data })
    } catch (error) {
      setState({ loading: false, error })
    } finally {
      setRefreshing(false)
    }
  }, [])

  useFocusEffect(useCallback(() => { load() }, [load]))

  if (state.loading) return <Loading />

  if (state.error) {
    return (
      <ScrollView style={s.screen} contentContainerStyle={s.content}>
        <ErrorBox error={state.error} />
        <Pressable style={s.btn} onPress={() => load()}>
          <Text style={s.btnText}>Try again</Text>
        </Pressable>
      </ScrollView>
    )
  }

  const d = state.d
  const allergies = d.alerts.allergies || []

  return (
    <ScrollView
      style={s.screen}
      contentContainerStyle={s.content}
      refreshControl={
        <RefreshControl refreshing={refreshing}
                        onRefresh={() => { setRefreshing(true); load(true) }} />
      }
    >
      <View style={s.spread}>
        <View style={{ flex: 1 }}>
          <Text style={s.h1}>Hello, {d.patient.name.split(' ')[0]}</Text>
          <Text style={s.muted}>
            {d.patient.mrn}
            {d.patient.age != null ? ` · ${d.patient.age} yrs` : ''}
            {d.patient.blood_group ? ` · ${d.patient.blood_group}` : ''}
          </Text>
        </View>
        <Pressable onPress={() => router.push('/notifications')} style={{ padding: 8 }}>
          <Text style={{ fontSize: 22 }}>🔔</Text>
          {d.unread_notifications > 0 && (
            <View style={{
              position: 'absolute', top: 2, right: 0, minWidth: 18, height: 18,
              borderRadius: 9, backgroundColor: c.danger,
              alignItems: 'center', justifyContent: 'center', paddingHorizontal: 4,
            }}>
              <Text style={{ color: '#fff', fontSize: 10.5, fontWeight: '700' }}>
                {d.unread_notifications}
              </Text>
            </View>
          )}
        </Pressable>
      </View>

      {/* Medical alerts come first — in an emergency this is the screen
          someone hands to a clinician. */}
      {allergies.length > 0 && (
        <View style={[s.alertBanner, { marginTop: 16 }]}>
          <Text style={s.alertTitle}>⚠ Allergies</Text>
          {allergies.map((a) => (
            <Text key={a.id} style={s.alertItem}>
              <Text style={{ fontWeight: '700' }}>{a.substance}</Text>
              {' — '}{String(a.severity).replace(/_/g, ' ')}
              {a.reaction ? ` · ${a.reaction}` : ''}
            </Text>
          ))}
        </View>
      )}

      {Number(d.outstanding.total) > 0 && (
        <Pressable onPress={() => router.push('/(tabs)/bills')}>
          <Card style={{ backgroundColor: c.accentSoft, borderColor: 'rgba(13,126,168,0.25)' }}>
            <Text style={{ color: c.accentDark, fontWeight: '700', fontSize: 12.5,
                           textTransform: 'uppercase', letterSpacing: 0.6 }}>
              Outstanding balance
            </Text>
            <Text style={{ fontSize: 26, fontWeight: '700', color: c.ink, marginTop: 4 }}>
              {money(d.outstanding.total, d.outstanding.currency)}
            </Text>
            <Text style={s.muted}>
              {d.outstanding.invoices.length} unpaid invoice
              {d.outstanding.invoices.length === 1 ? '' : 's'} · tap to view
            </Text>
          </Card>
        </Pressable>
      )}

      {/* §3 "health summary": where this patient stands with the clinic, in
          the few numbers that answer it. Counts only — the detail already has
          its own screens, and repeating it here would be a fifth place for it
          to disagree. */}
      {d.health_summary && (
        <>
          <SectionTitle>Health summary</SectionTitle>
          <Card>
            <View style={[s.row, { flexWrap: 'wrap' }]}>
              <Metric label="Visits" value={d.health_summary.visits} />
              <Metric label="Prescriptions" value={d.health_summary.prescriptions} />
              <Metric label="Lab orders" value={d.health_summary.lab_orders} />
              <Metric label="Allergies" value={d.health_summary.allergies}
                      tone={d.health_summary.allergies > 0 ? c.danger : undefined} />
              <Metric label="Conditions" value={d.health_summary.active_conditions} />
              <Metric label="Unpaid bills" value={d.health_summary.unpaid_invoices}
                      tone={d.health_summary.unpaid_invoices > 0 ? c.accentDark : undefined} />
            </View>
            {d.health_summary.last_visit ? (
              <Text style={[s.muted, { marginTop: 10 }]}>
                Last visit {dateOnly(d.health_summary.last_visit)}
              </Text>
            ) : null}
          </Card>
        </>
      )}

      <SectionTitle>Upcoming appointments</SectionTitle>
      {d.upcoming_appointments.length === 0 ? (
        <Card><EmptyState icon="📅" title="Nothing booked"
                          hint="Call the clinic to arrange a visit." /></Card>
      ) : (
        d.upcoming_appointments.map((a) => (
          <Card key={a.id}>
            <View style={s.spread}>
              <Text style={s.h2}>{when(a.scheduled_at)}</Text>
              <Badge>{a.status}</Badge>
            </View>
            <Text style={[s.body, { marginTop: 5 }]}>
              <Text style={{ fontWeight: "700", color: c.ink }}>{a.doctor_name}</Text>
              {" · "}{a.specialty}
            </Text>
            {a.reason ? <Text style={s.muted}>{a.reason}</Text> : null}
            {a.room ? <Text style={s.muted}>Room {a.room}</Text> : null}
          </Card>
        ))
      )}

      {d.follow_ups.length > 0 && (
        <>
          <SectionTitle>Treatment reminders</SectionTitle>
          {d.follow_ups.map((f) => (
            <Card key={f.id}>
              <Text style={s.h2}>Follow-up due {dateOnly(f.followup_on)}</Text>
              <Text style={s.muted}>
                Asked for by {f.doctor_name} at visit {f.encounter_no}
              </Text>
            </Card>
          ))}
        </>
      )}

      <SectionTitle
        action={
          <Pressable onPress={() => router.push('/(tabs)/records')}>
            <Text style={{ color: '#fff', fontWeight: '700', fontSize: 13 }}>See all</Text>
          </Pressable>
        }
      >
        Recent prescriptions
      </SectionTitle>
      {d.recent_prescriptions.length === 0 ? (
        <Card><EmptyState icon="💊" title="No prescriptions yet" /></Card>
      ) : (
        d.recent_prescriptions.map((rx) => (
          <Card key={rx.id}>
            <View style={s.spread}>
              <Text style={s.docNo}>{rx.prescription_no}</Text>
              <Badge>{rx.status}</Badge>
            </View>
            <Text style={[s.body, { marginTop: 5 }]}>
              {rx.item_count} medicine{rx.item_count === 1 ? '' : 's'} · {rx.doctor_name}
            </Text>
            <Text style={s.muted}>{dateOnly(rx.issued_at || rx.created_at)}</Text>
          </Card>
        ))
      )}
    </ScrollView>
  )
}

/** One number from the health summary, sized so six fit on a phone. */
function Metric({ label, value, tone }) {
  return (
    <View style={{ width: '33%', paddingVertical: 6 }}>
      <Text style={{ fontSize: 22, fontWeight: '800', color: tone || c.ink }}>{value}</Text>
      <Text style={[s.muted, { marginTop: -2 }]}>{label}</Text>
    </View>
  )
}
