import { useCallback, useEffect, useState } from 'react'
import {
  Pressable, ScrollView, Text, TextInput, View,
} from 'react-native'
import { useLocalSearchParams, useRouter } from 'expo-router'
import { api } from '../src/api'
import { Card, EmptyState, ErrorBox, Loading, c, s, timeOnly } from '../src/ui'

/**
 * Booking, from the patient's side (§3).
 *
 * Three steps on one screen — doctor, day, time — because a phone screen full
 * of half-answered questions is worse than a short list you walk down. The
 * chosen doctor and day stay visible so you can change your mind without
 * starting again.
 *
 * The slots come from the clinic's own availability, so the app can never
 * offer a time the front desk would refuse.
 */
export default function Book() {
  const router = useRouter()
  // Rescheduling reuses this whole screen: same doctor, pick a new time.
  const { reschedule, doctorId, doctorName } = useLocalSearchParams()
  const moving = Boolean(reschedule)

  const [doctors, setDoctors] = useState({ loading: !moving, rows: [] })
  const [search, setSearch] = useState('')
  // §3: find a doctor by specialty and by location. Both lists come from the
  // clinic's own doctors, so neither can offer a choice that matches nobody.
  const [filters, setFilters] = useState({ specialties: [], locations: [] })
  const [specialty, setSpecialty] = useState(null)
  const [location, setLocation] = useState(null)
  const [doctor, setDoctor] = useState(
    moving && doctorId ? { id: Number(doctorId), doctor_name: String(doctorName || 'your doctor') } : null,
  )
  const [dayOffset, setDayOffset] = useState(1)
  const [slots, setSlots] = useState({ loading: false, rows: [] })
  const [slot, setSlot] = useState(null)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  // Today and the next 13 days — the clinic sets the hours, this just offers
  // the days. Today is first: a toothache does not wait for tomorrow, and the
  // server already refuses any slot that has passed.
  const days = Array.from({ length: 14 }, (_, i) => {
    const d = new Date()
    d.setDate(d.getDate() + i)
    return d
  })
  const chosenDay = days[dayOffset - 1] ?? days[0]
  const isoDay = chosenDay.toISOString().slice(0, 10)

  const loadDoctors = useCallback(async (q = '', spec = null, loc = null) => {
    setDoctors({ loading: true, rows: [] })
    try {
      const res = await api.bookableDoctors(q, spec, loc)
      setDoctors({ loading: false, rows: res.data.doctors })
    } catch (err) {
      setDoctors({ loading: false, rows: [], error: err })
    }
  }, [])

  useEffect(() => { if (!moving) loadDoctors() }, [loadDoctors, moving])

  // Loaded once. A clinic does not gain a specialty while you are booking, and
  // a failure here should cost you the filters, not the booking.
  useEffect(() => {
    if (moving) return
    let alive = true
    api.doctorFilters()
      .then((res) => { if (alive) setFilters({
        specialties: res.data.specialties || [],
        locations: res.data.locations || [],
      }) })
      .catch(() => {})
    return () => { alive = false }
  }, [moving])

  // Tapping the chip that is already on clears it — that is how you get back
  // to "all doctors" without a separate Clear button.
  function pickFilter(kind, value) {
    const nextSpec = kind === 'specialty' ? (specialty === value ? null : value) : specialty
    const nextLoc  = kind === 'location'  ? (location === value ? null : value)  : location
    setSpecialty(nextSpec)
    setLocation(nextLoc)
    setDoctor(null)
    setSlot(null)
    loadDoctors(search, nextSpec, nextLoc)
  }

  // Whenever the doctor or the day changes, the old slots are wrong.
  useEffect(() => {
    if (!doctor) return
    let alive = true
    setSlot(null)
    setSlots({ loading: true, rows: [] })
    api.doctorSlots(doctor.id, isoDay)
      .then((res) => { if (alive) setSlots({ loading: false, rows: res.data.slots || res.data }) })
      .catch((err) => { if (alive) setSlots({ loading: false, rows: [], error: err }) })
    return () => { alive = false }
  }, [doctor, isoDay])

  async function confirm() {
    setBusy(true)
    setError(null)
    try {
      if (moving) {
        await api.rescheduleAppointment(Number(reschedule), slot.start)
      } else {
        await api.book(doctor.id, slot.start, reason.trim() || undefined)
      }
      router.back()
    } catch (err) {
      setError(err)
      setBusy(false)
    }
  }

  return (
    <ScrollView style={s.screen} contentContainerStyle={s.content}>
      {error && <ErrorBox error={error} />}

      {/* ---- 1. Doctor ---- */}
      {!moving && (
        <>
          <Text style={s.label}>Doctor</Text>

          {doctor ? (
            <Card>
              <View style={s.spread}>
                <View style={{ flex: 1 }}>
                  <Text style={s.itemName}>{doctor.doctor_name}</Text>
                  <Text style={s.muted}>
                    {doctor.specialty}
                    {doctor.location ? ` · ${doctor.location}` : ''}
                  </Text>
                </View>
                <Pressable onPress={() => { setDoctor(null); setSlot(null) }}>
                  <Text style={{ color: c.accent, fontWeight: '700', fontSize: 13.5 }}>Change</Text>
                </Pressable>
              </View>
            </Card>
          ) : (
            <>
              <TextInput
                style={s.input}
                value={search}
                onChangeText={(v) => { setSearch(v); loadDoctors(v, specialty, location) }}
                placeholder="Search by name, specialty or location"
                autoCapitalize="none"
              />

              <FilterRow
                label="Specialty"
                options={filters.specialties}
                chosen={specialty}
                onPick={(v) => pickFilter('specialty', v)}
              />
              <FilterRow
                label="Location"
                options={filters.locations}
                chosen={location}
                onPick={(v) => pickFilter('location', v)}
              />

              {doctors.loading ? <Loading label="Finding doctors…" /> : doctors.rows.length === 0 ? (
                <Card><EmptyState icon="🔍" title="No doctors match that" /></Card>
              ) : doctors.rows.map((d) => (
                <Pressable key={d.id} onPress={() => setDoctor(d)}>
                  <Card>
                    <View style={s.spread}>
                      <Text style={[s.itemName, { flex: 1 }]}>{d.doctor_name}</Text>
                      {Number(d.is_online) === 1 && (
                        <View style={{
                          flexDirection: 'row', alignItems: 'center', gap: 5,
                          backgroundColor: '#e6f4ea', borderRadius: 999,
                          paddingVertical: 3, paddingHorizontal: 9,
                        }}>
                          <View style={{ width: 7, height: 7, borderRadius: 4, backgroundColor: '#1e7d40' }} />
                          <Text style={{ fontSize: 11.5, fontWeight: '700', color: '#1e7d40' }}>
                            Online now
                          </Text>
                        </View>
                      )}
                    </View>
                    <Text style={s.muted}>
                      {d.specialty}
                      {d.location ? ` · ${d.location}` : ''}
                      {d.experience_years ? ` · ${d.experience_years} yrs` : ''}
                      {d.qualification ? ` · ${d.qualification}` : ''}
                    </Text>
                    {d.consultation_fee ? (
                      <Text style={[s.body, { marginTop: 4, fontWeight: '600' }]}>
                        Consultation {Number(d.consultation_fee).toLocaleString()}
                      </Text>
                    ) : null}
                  </Card>
                </Pressable>
              ))}
            </>
          )}
        </>
      )}

      {/* ---- 2. Day ---- */}
      {doctor && (
        <>
          <Text style={[s.label, { marginTop: 18 }]}>Day</Text>
          <ScrollView horizontal showsHorizontalScrollIndicator={false}>
            {days.map((d, i) => {
              const picked = i + 1 === dayOffset
              return (
                <Pressable
                  key={i}
                  onPress={() => setDayOffset(i + 1)}
                  style={{
                    paddingVertical: 10, paddingHorizontal: 14, borderRadius: 10,
                    borderWidth: 1, marginRight: 8, alignItems: 'center',
                    borderColor: picked ? c.accentDark : c.border,
                    backgroundColor: picked ? c.accentDark : c.surface,
                  }}
                >
                  <Text style={{
                    fontSize: 11.5, fontWeight: '700',
                    color: picked ? 'rgba(255,255,255,0.75)' : c.muted,
                  }}>
                    {i === 0 ? 'Today' : d.toLocaleDateString(undefined, { weekday: 'short' })}
                  </Text>
                  <Text style={{
                    fontSize: 16, fontWeight: '800', marginTop: 2,
                    color: picked ? '#fff' : c.ink,
                  }}>
                    {d.getDate()}
                  </Text>
                  <Text style={{
                    fontSize: 11, color: picked ? 'rgba(255,255,255,0.75)' : c.muted,
                  }}>
                    {d.toLocaleDateString(undefined, { month: 'short' })}
                  </Text>
                </Pressable>
              )
            })}
          </ScrollView>

          {/* ---- 3. Time ---- */}
          <Text style={[s.label, { marginTop: 18 }]}>Time</Text>
          {slots.loading ? (
            <Loading label="Checking availability…" />
          ) : slots.rows.length === 0 ? (
            <Card>
              <EmptyState
                icon="🕐"
                title="Nothing free that day"
                hint="Try another day — the clinic may not sit that day."
              />
            </Card>
          ) : (
            <View style={[s.row, { flexWrap: 'wrap' }]}>
              {slots.rows.map((sl) => {
                const picked = slot?.start === sl.start
                return (
                  <Pressable
                    key={sl.start}
                    onPress={() => setSlot(sl)}
                    style={{
                      paddingVertical: 9, paddingHorizontal: 14, borderRadius: 999,
                      borderWidth: 1, marginRight: 8, marginBottom: 8,
                      borderColor: picked ? c.accentDark : c.border,
                      backgroundColor: picked ? c.accentDark : c.surface,
                    }}
                  >
                    <Text style={{
                      fontSize: 13.5, fontWeight: picked ? '700' : '500',
                      color: picked ? '#fff' : c.ink,
                    }}>
                      {timeOnly(sl.start)}
                    </Text>
                  </Pressable>
                )
              })}
            </View>
          )}

          {/* ---- 4. Why, and confirm ---- */}
          {!moving && (
            <>
              <Text style={[s.label, { marginTop: 6 }]}>What is it about? (optional)</Text>
              <TextInput
                style={s.input}
                value={reason}
                onChangeText={setReason}
                placeholder="Toothache for three days"
              />
            </>
          )}

          <Pressable
            onPress={confirm}
            disabled={busy || !slot}
            style={[s.btn, { marginTop: 18 }, (busy || !slot) && s.btnDisabled]}
          >
            <Text style={s.btnText}>
              {busy
                ? 'Booking…'
                : slot
                  ? `${moving ? 'Move to' : 'Book'} ${timeOnly(slot.start)}`
                  : 'Pick a time'}
            </Text>
          </Pressable>

          <Text style={[s.muted, { marginTop: 10, textAlign: 'center' }]}>
            The clinic sees this booking immediately.
          </Text>
        </>
      )}
    </ScrollView>
  )
}

/**
 * One row of filter chips — specialty or location.
 *
 * Renders nothing when the clinic has only one value to offer, or none: a
 * filter with a single choice is a control that cannot change anything.
 */
function FilterRow({ label, options, chosen, onPick }) {
  if (!options || options.length < 2) return null

  return (
    <>
      <Text style={[s.label, { marginTop: 4 }]}>{label}</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginBottom: 10 }}>
        {options.map((option) => {
          const picked = chosen === option
          return (
            <Pressable
              key={option}
              onPress={() => onPick(option)}
              style={{
                paddingVertical: 8, paddingHorizontal: 14, borderRadius: 999,
                borderWidth: 1, marginRight: 8,
                borderColor: picked ? c.accentDark : c.border,
                backgroundColor: picked ? c.accentDark : c.surface,
              }}
            >
              <Text style={{
                fontSize: 13, fontWeight: '600',
                color: picked ? '#fff' : c.ink,
              }}>
                {option}
              </Text>
            </Pressable>
          )
        })}
      </ScrollView>
    </>
  )
}
