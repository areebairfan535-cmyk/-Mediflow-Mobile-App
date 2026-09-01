import { useCallback, useState } from 'react'
import { Pressable, ScrollView, Text, View } from 'react-native'
import { useFocusEffect } from 'expo-router'
import { api } from '../../src/api'
import {
  Badge, Card, EmptyState, ErrorBox, Loading, SectionTitle, c, s, dateOnly, money,
} from '../../src/ui'

/**
 * §3 billing: invoices, outstanding balance, payment history and refunds.
 *
 * Paying inside the app needs a gateway, which §7 lists as a later
 * integration. Until one is wired up the screen says how to pay rather than
 * showing a button that cannot work.
 */
export default function Bills() {
  const [state, setState] = useState({ loading: true })
  const [open, setOpen] = useState(null)

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: !s.d }))
    try {
      const res = await api.bills()
      setState({ loading: false, d: res.data })
    } catch (error) {
      setState({ loading: false, error })
    }
  }, [])

  useFocusEffect(useCallback(() => { load() }, [load]))

  if (state.loading) return <Loading />
  if (state.error) {
    return (
      <ScrollView style={s.screen} contentContainerStyle={s.content}>
        <ErrorBox error={state.error} />
      </ScrollView>
    )
  }

  const d = state.d
  const owing = Number(d.outstanding) > 0

  return (
    <ScrollView style={s.screen} contentContainerStyle={s.content}>
      <Card style={owing
        ? { backgroundColor: c.accentSoft, borderColor: 'rgba(13,126,168,0.25)' }
        : { backgroundColor: c.okSoft, borderColor: 'rgba(15,138,95,0.2)' }}>
        <Text style={{
          color: owing ? c.accentDark : c.ok, fontWeight: '700', fontSize: 12.5,
          textTransform: 'uppercase', letterSpacing: 0.6,
        }}>
          {owing ? 'Outstanding balance' : 'All settled'}
        </Text>
        <Text style={{ fontSize: 30, fontWeight: '700', color: c.ink, marginTop: 4 }}>
          {money(d.outstanding, d.currency)}
        </Text>
        {owing ? (
          <Text style={s.muted}>Pay at the clinic reception, or call to arrange.</Text>
        ) : (
          <Text style={s.muted}>You have nothing to pay right now.</Text>
        )}
      </Card>

      <SectionTitle>Invoices</SectionTitle>
      {d.invoices.length === 0 ? (
        <Card><EmptyState icon="🧾" title="No invoices yet" /></Card>
      ) : (
        d.invoices.map((i) => (
          <Pressable key={i.id} onPress={() => setOpen(open === i.id ? null : i.id)}>
            <Card>
              <View style={s.spread}>
                <Text style={s.docNo}>{i.invoice_no}</Text>
                <View style={[s.row, { gap: 10 }]}>
                  <Pressable onPress={() => api.openInvoicePdf(i.id, i.invoice_no)}>
                    <Text style={{ color: c.accentDark, fontWeight: '700', fontSize: 13 }}>PDF</Text>
                  </Pressable>
                  <Badge>{i.status}</Badge>
                </View>
              </View>

              <View style={[s.spread, { marginTop: 8 }]}>
                <View>
                  <Text style={s.muted}>{dateOnly(i.issue_date || i.created_at)}</Text>
                  {/* A due date is a deadline, not a caption — black and bold
                      so it is read, not skimmed past. */}
                  {i.due_date ? (
                    <Text style={[s.muted, { color: c.ink, fontWeight: '700' }]}>
                      Due {dateOnly(i.due_date)}
                    </Text>
                  ) : null}
                </View>
                <View style={{ alignItems: 'flex-end' }}>
                  <Text style={{ fontSize: 19, fontWeight: '700', color: c.ink }}>
                    {money(i.grand_total, i.currency_code)}
                  </Text>
                  {Number(i.balance_due) > 0 ? (
                    <Text style={[s.muted, { color: c.warn }]}>
                      {money(i.balance_due)} still due
                    </Text>
                  ) : (
                    <Text style={[s.muted, { color: c.ok }]}>Paid in full</Text>
                  )}
                </View>
              </View>

              {open === i.id && <InvoiceLines id={i.id} />}

              {/* Blue and bold: this is the only thing on the card that DOES
                  something when tapped, and in muted grey it read as a caption
                  nobody was meant to act on. */}
              <Text
                style={[
                  s.muted,
                  {
                    textAlign: 'center',
                    marginTop: 8,
                    fontSize: 12.5,
                    fontWeight: '700',
                    color: c.accent,
                  },
                ]}
              >
                {open === i.id ? 'Tap to collapse' : 'Tap for the breakdown'}
              </Text>
            </Card>
          </Pressable>
        ))
      )}

      {d.payments.length > 0 && (
        <>
          <SectionTitle>Payment history</SectionTitle>
          {d.payments.map((p, i) => (
            <Card key={i}>
              <View style={s.spread}>
                <View style={{ flex: 1 }}>
                  <Text style={{ fontWeight: '600', color: c.ink }}>
                    {String(p.method).replace(/_/g, ' ')}
                  </Text>
                  <Text style={s.docNo}>{p.receipt_no} · {p.invoice_no}</Text>
                  <Text style={s.muted}>{dateOnly(p.paid_at || p.created_at)}</Text>
                </View>
                <Text style={{ fontSize: 17, fontWeight: '700', color: c.ok }}>
                  {money(p.amount, p.currency_code)}
                </Text>
              </View>
            </Card>
          ))}
        </>
      )}

      {d.refunds.length > 0 && (
        <>
          <SectionTitle>Refunds</SectionTitle>
          {d.refunds.map((r, i) => (
            <Card key={i}>
              <View style={s.spread}>
                <View style={{ flex: 1 }}>
                  <Text style={{ fontWeight: '600', color: c.ink }}>{r.invoice_no}</Text>
                  <Text style={s.muted}>{r.reason}</Text>
                </View>
                <View style={{ alignItems: 'flex-end' }}>
                  <Text style={{ fontWeight: '700', color: c.ink }}>
                    {money(r.amount, r.currency_code)}
                  </Text>
                  <Badge>{r.status}</Badge>
                </View>
              </View>
            </Card>
          ))}
        </>
      )}
    </ScrollView>
  )
}

/** Lines are fetched on demand — the list endpoint deliberately omits them. */
function InvoiceLines({ id }) {
  const [state, setState] = useState({ loading: true })

  useFocusEffect(useCallback(() => {
    let alive = true
    api.invoice(id)
      .then((r) => { if (alive) setState({ loading: false, inv: r.data.invoice }) })
      .catch((error) => { if (alive) setState({ loading: false, error }) })
    return () => { alive = false }
  }, [id]))

  if (state.loading) return <Text style={s.muted}>Loading…</Text>
  if (state.error) return <Text style={[s.muted, { color: c.danger }]}>{state.error.message}</Text>

  const inv = state.inv

  return (
    <View style={{ marginTop: 12, borderTopWidth: 1, borderTopColor: c.border, paddingTop: 10 }}>
      {inv.items.map((it) => (
        <View key={it.id} style={[s.spread, { marginTop: 6 }]}>
          <View style={{ flex: 1, paddingRight: 10 }}>
            <Text style={{ color: c.ink, fontSize: 14 }}>{it.description}</Text>
            <Text style={s.mono}>
              {Number(it.quantity)} x {money(it.unit_price)}
              {Number(it.discount_amount) > 0 ? `  -${money(it.discount_amount)}` : ''}
            </Text>
          </View>
          <Text style={{ color: c.ink, fontWeight: '600' }}>{money(it.line_total)}</Text>
        </View>
      ))}

      <View style={{ marginTop: 10, borderTopWidth: 1, borderTopColor: c.border, paddingTop: 8 }}>
        <Row label="Subtotal" value={money(inv.subtotal)} />
        {Number(inv.discount_total) > 0 && (
          <Row label="Discount" value={`-${money(inv.discount_total)}`} />
        )}
        <Row label="Tax" value={money(inv.tax_total)} />
        <Row label="Total" value={money(inv.grand_total, inv.currency_code)} bold />
        <Row label="Paid" value={`-${money(inv.paid_total)}`} />
        <Row label="Balance" value={money(inv.balance_due, inv.currency_code)} bold />
      </View>
    </View>
  )
}

function Row({ label, value, bold }) {
  return (
    <View style={[s.spread, { marginTop: 3 }]}>
      <Text style={[s.body, bold && { fontWeight: '700', color: c.ink }]}>{label}</Text>
      <Text style={[s.body, bold && { fontWeight: '700', color: c.ink }]}>{value}</Text>
    </View>
  )
}
