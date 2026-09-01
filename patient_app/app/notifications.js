import { useCallback, useState } from 'react'
import { Modal, Pressable, ScrollView, Text, View } from 'react-native'
import { Stack, useFocusEffect } from 'expo-router'
import { api } from '../src/api'
import { Card, EmptyState, ErrorBox, Loading, c, s, when } from '../src/ui'

/** A small visual cue per §20 event type. */
const ICON = {
  'appointment.booked': '📅',
  'appointment.reminder': '⏰',
  'appointment.cancelled': '❌',
  'prescription.issued': '💊',
  'invoice.issued': '🧾',
  'payment.received': '✅',
  'invoice.overdue': '⚠️',
  'lab.result_ready': '🧪',
  'claim.updated': '🛡️',
}

/**
 * The inbox (§20).
 *
 * "Delete" here means "clear from my inbox". The row itself is kept: whether a
 * patient was told about an appointment is a question the clinic has to be
 * able to answer, and this list is the record of it.
 */
export default function Notifications() {
  const [state, setState] = useState({ loading: true })
  const [busy, setBusy] = useState(false)
  const [menu, setMenu] = useState(false)
  // null = normal browsing; a Set = selection mode, even when nothing is ticked
  const [selected, setSelected] = useState(null)

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: !s.rows }))
    try {
      const res = await api.notifications()
      setState({ loading: false, rows: res.data.notifications, unread: res.data.unread })
    } catch (error) {
      setState({ loading: false, error })
    }
  }, [])

  useFocusEffect(useCallback(() => { load() }, [load]))

  async function run(fn) {
    setBusy(true)
    try {
      await fn()
      await load()
    } catch {
      /* the list simply stays as it was */
    } finally {
      setBusy(false)
    }
  }

  const rows = state.rows || []
  const selecting = selected !== null
  const count = selecting ? selected.size : 0

  function toggle(id) {
    const next = new Set(selected)
    next.has(id) ? next.delete(id) : next.add(id)
    setSelected(next)
  }

  if (state.loading) return <Loading />
  if (state.error) {
    return (
      <ScrollView
        style={s.screen}
        contentContainerStyle={[s.content, selecting && { paddingBottom: 110 }]}
      >
        <ErrorBox error={state.error} />
      </ScrollView>
    )
  }

  return (
    <>
      {/* The ⋮ in the top-right corner, where Android puts an overflow menu —
          and it keeps the list itself free of controls. */}
      <Stack.Screen
        options={{
          // Pinned left, and pulled tight against the back arrow: the default
          // gap between the two is wider than this short title needs.
          headerTitleAlign: 'left',
          headerTitle: () => (
            <Text style={{
              color: '#fff', fontSize: 18, fontWeight: '700', marginLeft: -18,
            }}>
              Notifications
            </Text>
          ),
          headerRight: () => (
            <Pressable onPress={() => setMenu(true)} hitSlop={12}
                       style={{ paddingHorizontal: 8, paddingVertical: 2 }}>
              <Text style={{ color: '#fff', fontSize: 22, fontWeight: '700' }}>⋮</Text>
            </Pressable>
          ),
        }}
      />

      <ScrollView
        style={s.screen}
        contentContainerStyle={[s.content, selecting && { paddingBottom: 110 }]}
      >
        {rows.length === 0 ? (
          <Card>
            <EmptyState icon="🔔" title="Nothing here yet"
                        hint="Appointments, prescriptions and bills will show up here." />
          </Card>
        ) : (
          rows.map((n) => {
            const ticked = selecting && selected.has(n.id)
            return (
              <Pressable
                key={n.id}
                onPress={async () => {
                  if (selecting) return toggle(n.id)
                  if (n.read_at) return
                  await api.markRead(n.id)
                  load()
                }}
                onLongPress={() => setSelected(new Set([n.id]))}
              >
                <Card style={
                  ticked
                    ? { borderColor: c.accentDark, backgroundColor: c.accentSoft }
                    : (!n.read_at && { borderColor: c.accent, backgroundColor: c.accentSoft })
                }>
                  <View style={s.row}>
                    {selecting && (
                      <View style={{
                        width: 22, height: 22, borderRadius: 11, borderWidth: 2,
                        borderColor: ticked ? c.accentDark : c.border,
                        backgroundColor: ticked ? c.accentDark : 'transparent',
                        alignItems: 'center', justifyContent: 'center',
                      }}>
                        {ticked && (
                          <Text style={{ color: '#fff', fontSize: 12, fontWeight: '800' }}>✓</Text>
                        )}
                      </View>
                    )}

                    <Text style={{ fontSize: 20 }}>{ICON[n.event] || '🔔'}</Text>

                    <View style={{ flex: 1 }}>
                      <Text style={{
                        fontWeight: n.read_at ? '600' : '700',
                        color: c.ink, fontSize: 15,
                      }}>
                        {n.title}
                      </Text>
                      <Text style={[s.body, { marginTop: 2 }]}>{n.body}</Text>
                      <Text style={s.muted}>{when(n.created_at)}</Text>
                    </View>

                    {!selecting && !n.read_at && (
                      <View style={{
                        width: 8, height: 8, borderRadius: 4, backgroundColor: c.accent,
                      }} />
                    )}

                    {!selecting && (
                      <Pressable
                        onPress={() => run(() => api.dismissNotifications(n.id))}
                        hitSlop={10}
                        style={{ paddingHorizontal: 6, paddingVertical: 2 }}
                      >
                        <Text style={{ color: c.muted, fontSize: 16 }}>✕</Text>
                      </Pressable>
                    )}
                  </View>
                </Card>
              </Pressable>
            )
          })
        )}
      </ScrollView>

      {/* The selection bar rises from the bottom, where a thumb already is —
          reaching to the top of the screen to delete something you are looking
          at halfway down it is the wrong way round. */}
      {selecting && (
        <View
          style={{
            position: 'absolute', left: 0, right: 0, bottom: 0,
            backgroundColor: c.surface,
            borderTopWidth: 1, borderTopColor: c.border,
            paddingHorizontal: 14, paddingTop: 12, paddingBottom: 22,
            shadowColor: '#0a1626', shadowOpacity: 0.18, shadowRadius: 16,
            shadowOffset: { width: 0, height: -4 }, elevation: 14,
          }}
        >
          <Text style={{ marginBottom: 10, color: c.ink, fontWeight: '700', fontSize: 14 }}>
            {count === 0 ? 'Tap the ones you want to remove' : `${count} selected`}
          </Text>

          <Pressable
            onPress={() => setSelected(new Set(rows.map((n) => n.id)))}
            style={[s.btnGhost, { marginBottom: 8, borderColor: c.accentDark, borderWidth: 1.5 }]}
          >
            <Text style={[s.btnGhostText, { color: c.accentDark, fontWeight: '700', fontSize: 15 }]}>
              Select all
            </Text>
          </Pressable>

          <Pressable
            disabled={busy || count === 0}
            onPress={() => run(async () => {
              await api.dismissNotifications([...selected])
              setSelected(null)
            })}
            style={[
              s.btnGhost,
              { marginBottom: 8,
                backgroundColor: c.accentDark, borderColor: c.accentDark },
              (busy || count === 0) && s.btnDisabled,
            ]}
          >
            <Text style={[s.btnGhostText, { color: '#fff', fontWeight: '700', fontSize: 15 }]}>
              Delete{count ? ` (${count})` : ''}
            </Text>
          </Pressable>

          <Pressable onPress={() => setSelected(null)} style={s.btnGhost}>
            <Text style={[s.btnGhostText, { color: c.ink, fontWeight: '700', fontSize: 15 }]}>
              Cancel
            </Text>
          </Pressable>
        </View>
      )}

      {/* ⋮ menu */}
      <Modal visible={menu} transparent animationType="fade" onRequestClose={() => setMenu(false)}>
        {/* Dropped from the ⋮ itself — top right, where the button is — rather
            than sliding up from the bottom of the screen. */}
        <Pressable
          style={{
            flex: 1,
            backgroundColor: 'rgba(10,22,38,0.25)',
            alignItems: 'flex-end',
            justifyContent: 'flex-start',
            paddingTop: 52,
            paddingRight: 8,
          }}
          onPress={() => setMenu(false)}
        >
          <Pressable
            style={{
              backgroundColor: c.surface, borderRadius: 10, minWidth: 150,
              paddingVertical: 4,
              shadowColor: '#0a1626', shadowOpacity: 0.22, shadowRadius: 14,
              shadowOffset: { width: 0, height: 6 }, elevation: 8,
            }}
            onPress={(e) => e.stopPropagation()}
          >
            <MenuItem
              label="Select"
              hint="Tick the ones to remove"
              onPress={() => { setMenu(false); setSelected(new Set()) }}
            />
            <MenuItem
              label="Clear all"
              hint="Empties the whole inbox"
              onPress={() => {
                setMenu(false)
                run(() => api.dismissNotifications('all'))
              }}
            />
          </Pressable>
        </Pressable>
      </Modal>
    </>
  )
}

function MenuItem({ label, hint, onPress, disabled = false }) {
  return (
    <Pressable
      onPress={disabled ? undefined : onPress}
      style={{ paddingVertical: 10, paddingHorizontal: 14, opacity: disabled ? 0.4 : 1 }}
    >
      <Text style={{ fontSize: 14, fontWeight: '600', color: c.ink }}>{label}</Text>
      {hint ? <Text style={[s.muted, { marginTop: 1 }]}>{hint}</Text> : null}
    </Pressable>
  )
}
