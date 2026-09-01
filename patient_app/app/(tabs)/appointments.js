import { useCallback, useState } from 'react'
import { Alert, Platform, Pressable, ScrollView, Text, View } from 'react-native'
import { useFocusEffect, useRouter } from 'expo-router'
import { api } from '../../src/api'
import {
  Badge, Card, EmptyState, ErrorBox, Loading, c, s, when,
} from '../../src/ui'

const CANCELLABLE = ['booked', 'confirmed']

export default function Appointments() {
  const router = useRouter()
  const [scope, setScope] = useState('upcoming')
  const [state, setState] = useState({ loading: true })
  const [busy, setBusy] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(async (which = scope) => {
    setState((s) => ({ ...s, loading: !s.rows }))
    try {
      const res = await api.appointments(which)
      setState({ loading: false, rows: res.data.appointments })
    } catch (error) {
      setState({ loading: false, error })
    }
  }, [scope])

  useFocusEffect(useCallback(() => { load() }, [load]))

  /**
   * Cancelling is the only change a patient may make. Confirm first — an
   * accidental tap here costs someone their slot.
   */
  function confirmCancel(appointment) {
    const run = async (reason) => {
      setBusy(appointment.id)
      setNotice(null)
      try {
        await api.cancelAppointment(appointment.id, reason)
        setNotice({ ok: true, message: 'Appointment cancelled. The clinic has been told.' })
        await load()
      } catch (error) {
        setNotice({ ok: false, message: error.message })
      } finally {
        setBusy(null)
      }
    }

    // Alert.alert is a no-op on web, so fall back to window.confirm there.
    if (Platform.OS === 'web') {
      if (window.confirm(`Cancel your appointment on ${when(appointment.scheduled_at)}?`)) {
        run('Cancelled from the app')
      }
      return
    }

    Alert.alert(
      'Cancel appointment?',
      `${when(appointment.scheduled_at)} with ${appointment.doctor_name}.`,
      [
        { text: 'Keep it', style: 'cancel' },
        { text: 'Cancel it', style: 'destructive', onPress: () => run('Cancelled from the app') },
      ],
    )
  }

  return (
    <ScrollView style={s.screen} contentContainerStyle={s.content}>
      {/* §3: the patient books for themselves. Top of the screen, because
          "I need to see someone" is why most people open this tab. */}
      <Pressable onPress={() => router.push('/book')} style={[s.btn, { marginBottom: 14 }]}>
        <Text style={s.btnText}>Book an appointment</Text>
      </Pressable>

      <View style={[s.row, { marginBottom: 14 }]}>
        {['upcoming', 'past'].map((which) => (
          <Pressable
            key={which}
            onPress={() => { setScope(which); load(which) }}
            style={[
              s.btnGhost,
              { flex: 1 },
              scope === which && { backgroundColor: c.accent, borderColor: c.accent },
            ]}
          >
            <Text style={[s.btnGhostText, scope === which && { color: '#fff' }]}>
              {which === 'upcoming' ? 'Upcoming' : 'Past'}
            </Text>
          </Pressable>
        ))}
      </View>

      {notice && (
        <View style={[
          s.errorBox,
          notice.ok && { backgroundColor: c.okSoft, borderColor: 'rgba(15,138,95,0.2)' },
        ]}>
          <Text style={[s.errorText, notice.ok && { color: c.ok }]}>{notice.message}</Text>
        </View>
      )}

      {state.loading ? (
        <Loading />
      ) : state.error ? (
        <ErrorBox error={state.error} />
      ) : state.rows.length === 0 ? (
        <Card>
          <EmptyState
            icon="📅"
            title={scope === 'upcoming' ? 'No upcoming visits' : 'No past visits'}
            hint={scope === 'upcoming' ? 'Tap \u0022Book an appointment\u0022 above.' : undefined}
          />
        </Card>
      ) : (
        state.rows.map((a) => (
          <Card key={a.id}>
            <View style={s.spread}>
              <Text style={s.h2}>{when(a.scheduled_at)}</Text>
              <Badge>{a.status}</Badge>
            </View>

            <Text style={[s.body, { marginTop: 6 }]}>
              <Text style={{ fontWeight: "700", color: c.ink }}>{a.doctor_name}</Text>
              {" · "}{a.specialty}
            </Text>
            {a.reason ? <Text style={s.muted}>{a.reason}</Text> : null}
            {a.room ? <Text style={s.muted}>Room {a.room}</Text> : null}
            {a.cancelled_reason ? (
              <Text style={[s.muted, { color: c.danger }]}>{a.cancelled_reason}</Text>
            ) : null}

            {CANCELLABLE.includes(a.status) && (
              <Pressable
                onPress={() => router.push({
                  pathname: '/book',
                  params: { reschedule: a.id, doctorId: a.doctor_id, doctorName: a.doctor_name },
                })}
                style={[s.btnGhost, { marginTop: 12 }]}
              >
                <Text style={[s.btnGhostText, { color: c.accentDark, fontWeight: '700' }]}>
                  Move to another time
                </Text>
              </Pressable>
            )}

            {CANCELLABLE.includes(a.status) && (
              <Pressable
                onPress={() => confirmCancel(a)}
                disabled={busy === a.id}
                style={[s.btnGhost, { marginTop: 12 }, busy === a.id && s.btnDisabled]}
              >
                <Text style={[s.btnGhostText, { color: c.danger }]}>
                  {busy === a.id ? 'Cancelling…' : 'Cancel appointment'}
                </Text>
              </Pressable>
            )}
          </Card>
        ))
      )}
    </ScrollView>
  )
}
