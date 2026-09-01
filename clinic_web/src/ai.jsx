import { useEffect, useState } from 'react'
import { api } from './api.js'
import { Card, Badge } from './components.jsx'

/**
 * The Phase 6 assistants (§9), as they appear to a clinician.
 *
 * Three rules shape every component here, and they are the reason this is a
 * separate module rather than a few buttons sprinkled through the pages:
 *
 *  1. **AI is optional.** With no provider configured the API answers 503 and
 *     the clinic app must lose nothing. Every panel below therefore renders
 *     only after /ai/status says a provider is live, and a mid-session failure
 *     degrades to an inline message — never a broken screen.
 *  2. **Nothing the model writes is a record.** A drafted note is a draft
 *     until a person approves it; suggested services are a shopping list until
 *     a person ticks them and presses the button. §9 forbids anything else.
 *  3. **The evidence travels with the suggestion.** A clinician who cannot see
 *     why the machine proposed something cannot sensibly approve it.
 */

/* ------------------------------------------------------------------ */
/* Provider status — asked once per session, not once per screen.      */

let statusPromise = null

export function useAiStatus() {
  const [state, setState] = useState({ loading: true, status: null })

  useEffect(() => {
    let alive = true
    statusPromise ??= api.aiStatus()
      .then((r) => r.data)
      // A failure here means "no AI", not "broken app" — the pages that use
      // this hook simply render nothing.
      .catch(() => ({ configured: false }))

    statusPromise.then((status) => { if (alive) setState({ loading: false, status }) })
    return () => { alive = false }
  }, [])

  return state
}

/** Small "who wrote this" marker, so AI output is never mistaken for a person's. */
export function AiMark({ children = 'AI draft' }) {
  return <Badge tone="accent">✦ {children}</Badge>
}

/* ------------------------------------------------------------------ */
/* 1. Documentation assistant (§9) + the notes section it lives in.    */

/**
 * Clinical notes for a visit, with the AI documentation assistant attached.
 *
 * The section works with no AI at all — type a note, save it, done. When a
 * provider is configured a second path appears: shorthand in, structured draft
 * out, and then the same approval gate every note passes through.
 */
export function ClinicalNotes({ encounter, open, session, onChanged, onError }) {
  const { status } = useAiStatus()
  const notes = encounter.notes || []
  const canWrite = session.can('encounter.update')
  const canDraft = status?.configured && session.can('ai.draft_note')

  const [shorthand, setShorthand] = useState('')
  const [manual, setManual] = useState('')
  const [busy, setBusy] = useState(null)

  async function run(key, fn, done) {
    setBusy(key)
    try {
      await fn()
      done?.()
      onChanged?.()
    } catch (error) {
      onError?.(error.message)
    } finally {
      setBusy(null)
    }
  }

  return (
    <Card
      title={`Clinical notes (${notes.length})`}
      action={canDraft && <AiMark>documentation assistant</AiMark>}
    >
      {notes.length === 0 && <p className="hint">No note written for this visit yet.</p>}

      {notes.map((note) => (
        <NoteRow
          key={note.id}
          note={note}
          editable={open && canWrite}
          busy={busy}
          onApprove={(body) => run(`approve-${note.id}`, () => api.approveNote(note.id, body))}
          onDiscard={() => run(`discard-${note.id}`, () => api.discardNote(note.id))}
        />
      ))}

      {open && canWrite && (
        <>
          {canDraft && (
            <div className="field" style={{ marginTop: notes.length ? 18 : 6 }}>
              <label>Draft from shorthand</label>
              <textarea
                value={shorthand}
                onChange={(ev) => setShorthand(ev.target.value)}
                placeholder="sob 3/7, wheeze bilat, afeb, sats 96%, salbutamol prn, r/v 1/52"
              />
              <p className="hint" style={{ marginTop: 6 }}>
                Your shorthand plus what is already recorded on this visit goes to the
                assistant. What comes back is a draft you edit and approve — it is not
                in the chart until you do.
              </p>
              <button
                className="btn btn-sm"
                style={{ marginTop: 8 }}
                disabled={busy === 'draft' || shorthand.trim().length < 3}
                onClick={() => run('draft', () => api.aiDraftNote(encounter.id, shorthand),
                                   () => setShorthand(''))}
              >
                {busy === 'draft' ? 'Drafting…' : '✦ Draft note with AI'}
              </button>
            </div>
          )}

          <div className="field" style={{ marginTop: 16 }}>
            <label>{canDraft ? 'Or write it yourself' : 'Write a note'}</label>
            <textarea
              value={manual}
              onChange={(ev) => setManual(ev.target.value)}
              placeholder="Subjective / objective / assessment / plan"
            />
            <button
              className="btn btn-sm btn-secondary"
              style={{ marginTop: 8 }}
              disabled={busy === 'manual' || manual.trim() === ''}
              onClick={() => run('manual',
                                 () => api.addNote(encounter.id, { body: manual, type: 'soap' }),
                                 () => setManual(''))}
            >
              {busy === 'manual' ? 'Saving…' : 'Save note'}
            </button>
          </div>
        </>
      )}
    </Card>
  )
}

/**
 * One note. An unapproved one is editable in place, because the point of the
 * assistant is that the clinician corrects it before taking responsibility.
 */
function NoteRow({ note, editable, busy, onApprove, onDiscard }) {
  const approved = Boolean(note.approved_at)
  const isAi = Number(note.is_ai_drafted) === 1
  const [body, setBody] = useState(note.body || '')

  // A reload after approval brings a fresh row; keep the box in step with it.
  useEffect(() => { setBody(note.body || '') }, [note.id, note.body])

  const pending = editable && !approved

  return (
    <div
      style={{
        border: '1px solid var(--border)',
        borderRadius: 'var(--radius)',
        padding: 12,
        marginBottom: 10,
        background: pending ? 'var(--warn-soft)' : 'var(--surface)',
      }}
    >
      <div className="row" style={{ alignItems: 'center', marginBottom: 8, gap: 8 }}>
        <Badge>{note.type}</Badge>
        {isAi && <AiMark />}
        <Badge tone={approved ? 'ok' : 'warn'}>
          {approved ? 'approved' : 'awaiting approval'}
        </Badge>
      </div>

      {pending ? (
        <>
          <textarea value={body} onChange={(ev) => setBody(ev.target.value)} rows={10} />
          <div className="row" style={{ marginTop: 8, gap: 8 }}>
            <button
              className="btn btn-sm btn-ok"
              disabled={busy != null || body.trim() === ''}
              onClick={() => onApprove(body === note.body ? null : body)}
            >
              {busy === `approve-${note.id}` ? 'Approving…' : 'Approve into the record'}
            </button>
            <button
              className="btn btn-sm btn-danger"
              disabled={busy != null}
              onClick={() => onDiscard()}
            >
              {busy === `discard-${note.id}` ? 'Discarding…' : 'Discard'}
            </button>
          </div>
        </>
      ) : (
        <div style={{ whiteSpace: 'pre-wrap', color: 'var(--body)', fontSize: 14 }}>
          {note.body}
        </div>
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* 2. Billing assistant (§9, §28)                                      */

/**
 * Suggests billable services from what the visit actually records.
 *
 * The prices shown are the clinic's own — the service returns catalogue
 * prices, never a number the model produced — and nothing is billed until the
 * user ticks lines and presses the button. That is §9's "human confirmation
 * before final billing", expressed as two separate clicks.
 */
export function AiBillingSuggestions({ encounter, session, onInvoiced, onError }) {
  const { status } = useAiStatus()
  const [result, setResult] = useState(null)
  const [chosen, setChosen] = useState({})
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(null)

  if (!status?.configured || !session.can('ai.suggest_billing')) return null

  async function suggest() {
    setBusy(true)
    setFailed(null)
    try {
      const res = await api.aiBillingSuggestions(encounter.id)
      setResult(res.data)
      setChosen(Object.fromEntries(
        (res.data.suggestions || []).map((s, i) => [i, s.has_price !== false]),
      ))
    } catch (error) {
      setFailed(error.message)
    } finally {
      setBusy(false)
    }
  }

  async function bill() {
    const items = (result.suggestions || [])
      .filter((_, i) => chosen[i])
      .map((s) => ({ service_id: s.service_id, quantity: s.quantity }))

    if (items.length === 0) return

    setBusy(true)
    try {
      const res = await api.createInvoice({
        patient_id: encounter.patient_id,
        encounter_id: encounter.id,
        items,
      })
      onInvoiced(res.data.invoice.id)
    } catch (error) {
      onError?.(error.message)
      setBusy(false)
    }
  }

  const picked = Object.values(chosen).filter(Boolean).length

  return (
    <div style={{ marginTop: 14, borderTop: '1px solid var(--border)', paddingTop: 14 }}>
      {!result ? (
        <>
          <button className="btn btn-secondary btn-sm" disabled={busy} onClick={suggest}>
            {busy ? 'Reading the visit…' : '✦ Suggest services with AI'}
          </button>
          {failed && (
            <p className="hint" style={{ marginTop: 8, color: 'var(--danger)' }}>{failed}</p>
          )}
        </>
      ) : (
        <>
          <div className="row" style={{ alignItems: 'center', gap: 8, marginBottom: 10 }}>
            <AiMark>suggested, not billed</AiMark>
            <span className="hint">
              Tick what belongs on the invoice. Prices come from your catalogue.
            </span>
          </div>

          {result.already_invoiced && (
            <div className="alert">
              This visit is already invoiced as{' '}
              <strong className="mono">{result.already_invoiced}</strong>.
            </div>
          )}

          {(result.suggestions || []).length === 0 && (
            <p className="hint">The assistant found nothing billable in this visit.</p>
          )}

          {(result.suggestions || []).map((s, i) => (
            <label className="line-item" key={`${s.service_id}-${i}`} style={{ cursor: 'pointer' }}>
              <input
                type="checkbox"
                checked={Boolean(chosen[i])}
                disabled={s.has_price === false}
                onChange={(ev) => setChosen({ ...chosen, [i]: ev.target.checked })}
                style={{ width: 'auto', marginRight: 10 }}
              />
              <div className="body">
                <div className="title">
                  <span className="mono">{s.code}</span> · {s.name}
                  {s.quantity > 1 ? ` × ${s.quantity}` : ''}
                </div>
                <div className="sub">
                  {s.evidence || 'No supporting detail given.'}
                  {s.has_price === false && ' · no price in the catalogue — set one first'}
                </div>
              </div>
              <Badge tone={s.confidence === 'high' ? 'ok' : s.confidence === 'medium' ? 'warn' : 'neutral'}>
                {s.confidence}
              </Badge>
              <span className="mono">{s.line_total ?? '—'}</span>
            </label>
          ))}

          {(result.notes || []).length > 0 && (
            <div className="alert" style={{ background: 'var(--warn-soft)', color: 'var(--warn)',
                                            borderColor: 'rgba(180,106,0,0.2)', marginTop: 10 }}>
              <strong>Check by hand:</strong>
              <ul style={{ margin: '6px 0 0 16px', padding: 0 }}>
                {result.notes.map((n, i) => <li key={i}>{n}</li>)}
              </ul>
            </div>
          )}

          {(result.suggestions || []).length > 0 && (
            <div className="row" style={{ marginTop: 12, gap: 8, alignItems: 'center' }}>
              <button className="btn" disabled={busy || picked === 0} onClick={bill}>
                {busy ? 'Creating…' : `Create draft invoice from ${picked} line(s)`}
              </button>
              <button className="btn btn-sm btn-secondary" disabled={busy}
                      onClick={() => setResult(null)}>
                Discard suggestions
              </button>
            </div>
          )}
        </>
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* 3. Claim assistant (§9, §28)                                        */

/**
 * Pre-submission review of a claim: what is missing, what an insurer is
 * likely to reject, and how risky the submission looks.
 *
 * Advisory only — it never blocks Submit. A biller who disagrees with the
 * machine is usually the one who is right, and a soft gate that hardens over
 * time is how a "suggestion" quietly becomes a rule nobody chose.
 */
export function AiClaimReview({ claim, session }) {
  const { status } = useAiStatus()
  const [review, setReview] = useState(null)
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(null)

  if (!status?.configured || !session.can('ai.check_claim')) return null

  const TONE = { low: 'ok', medium: 'warn', high: 'danger' }

  return (
    <Card title="AI claim review" action={<AiMark>advisory</AiMark>}>
      {!review ? (
        <>
          <p style={{ margin: '0 0 12px', color: 'var(--body)' }}>
            Check this claim against the visit record before it goes to{' '}
            {claim.provider_name} — missing documentation, and the codes an insurer
            most often sends back.
          </p>
          <button className="btn btn-secondary btn-sm" disabled={busy}
                  onClick={async () => {
                    setBusy(true)
                    setFailed(null)
                    try {
                      const res = await api.aiClaimReview(claim.id)
                      setReview(res.data)
                    } catch (error) {
                      setFailed(error.message)
                    } finally {
                      setBusy(false)
                    }
                  }}>
            {busy ? 'Reviewing…' : '✦ Review before submission'}
          </button>
          {failed && (
            <p className="hint" style={{ marginTop: 8, color: 'var(--danger)' }}>{failed}</p>
          )}
        </>
      ) : (
        <>
          <div className="row" style={{ alignItems: 'center', gap: 10, marginBottom: 12 }}>
            <Badge tone={TONE[review.risk_level] || 'neutral'}>
              {review.risk_level} risk · {Number(review.risk_score).toFixed(0)}/100
            </Badge>
            <Badge tone={review.ready_to_submit ? 'ok' : 'warn'}>
              {review.ready_to_submit ? 'looks ready' : 'needs attention'}
            </Badge>
          </div>

          {review.summary && (
            <p style={{ margin: '0 0 12px', color: 'var(--body)' }}>{review.summary}</p>
          )}

          {(review.missing || []).length > 0 && (
            <>
              <div className="hint" style={{ marginBottom: 6 }}>Missing</div>
              {review.missing.map((m, i) => (
                <div className="line-item" key={i}>
                  <div className="body">
                    <div className="title">{m.item || m}</div>
                    {m.why && <div className="sub">{m.why}</div>}
                  </div>
                  {m.severity && (
                    <Badge tone={m.severity === 'blocking' ? 'danger' : 'warn'}>
                      {m.severity}
                    </Badge>
                  )}
                </div>
              ))}
            </>
          )}

          {(review.likely_rejections || []).length > 0 && (
            <>
              <div className="hint" style={{ margin: '12px 0 6px' }}>Likely rejection reasons</div>
              {review.likely_rejections.map((r, i) => (
                <div className="line-item" key={i}>
                  <div className="body">
                    <div className="title">{r.reason || r}</div>
                    {r.fix && <div className="sub">Fix: {r.fix}</div>}
                  </div>
                </div>
              ))}
            </>
          )}

          <p className="hint" style={{ marginTop: 12 }}>
            Advisory only — it does not gate submission. Re-run it after fixing
            anything above.
          </p>
          <button className="btn btn-sm btn-secondary" onClick={() => setReview(null)}>
            Run again
          </button>
        </>
      )}
    </Card>
  )
}

/* ------------------------------------------------------------------ */
/* 4. Patient summary (§25)                                            */

/**
 * What matters in this chart, ordered by how much it matters.
 *
 * The record is already on the screen; what a clinician opening an unfamiliar
 * chart is short of is not information but precedence. Advisory only — never
 * written into the record — and asked for on demand rather than on load,
 * because every look costs the clinic an AI call.
 */
export function AiPatientSummary({ patientId, session }) {
  const { status } = useAiStatus()
  const [summary, setSummary] = useState(null)
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(null)

  if (!status?.configured || !session.can('ai.draft_note')) return null

  return (
    <Card title="Summary" action={<AiMark>advisory</AiMark>}>
      {!summary ? (
        <>
          <p style={{ margin: '0 0 12px', color: 'var(--body)' }}>
            Allergies, conditions, recent visits and current medicines — read back
            in the order that matters, before you see them.
          </p>
          <button
            className="btn btn-secondary btn-sm"
            disabled={busy}
            onClick={async () => {
              setBusy(true)
              setFailed(null)
              try {
                const res = await api.aiPatientSummary(patientId)
                setSummary(res.data)
              } catch (error) {
                setFailed(error.message)
              } finally {
                setBusy(false)
              }
            }}
          >
            {busy ? 'Reading the chart…' : '✦ Summarise this patient'}
          </button>
          {failed && (
            <p className="hint" style={{ marginTop: 8, color: 'var(--danger)' }}>{failed}</p>
          )}
        </>
      ) : (
        <>
          <p style={{ margin: '0 0 12px', color: 'var(--ink)', fontSize: 14.5 }}>
            {summary.summary}
          </p>

          {summary.key_points?.length > 0 && (
            <>
              <div className="hint" style={{ marginBottom: 6 }}>Key points</div>
              {summary.key_points.map((point, i) => (
                <div className="line-item" key={i}><div className="body">{point}</div></div>
              ))}
            </>
          )}

          {summary.watch_for?.length > 0 && (
            <div className="allergy-banner" style={{ marginTop: 12 }}>
              <div className="title">Watch for</div>
              {summary.watch_for.map((w, i) => <div className="item" key={i}>{w}</div>)}
            </div>
          )}

          <p className="hint" style={{ marginTop: 12 }}>
            Advisory — none of this is part of the record. Read the chart itself
            before prescribing.
          </p>
          <button className="btn btn-sm btn-secondary" onClick={() => setSummary(null)}>
            Ask again
          </button>
        </>
      )}
    </Card>
  )
}

/* ------------------------------------------------------------------ */

/**
 * One line telling a clinic the assistants are on, and on what terms.
 *
 * Renders nothing while the status is unknown and nothing when no provider is
 * configured — a spinner or an "AI: off" notice would both be noise on a
 * screen whose job is today's patients.
 */
export function AiStatusLine() {
  const { loading, status } = useAiStatus()
  if (loading || !status?.configured) return null
  return (
    <p className="hint">
      AI assistants are on ({status.provider} · {status.model}). {status.policy}
    </p>
  )
}
