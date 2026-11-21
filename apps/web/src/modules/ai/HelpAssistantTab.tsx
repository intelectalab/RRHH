'use client'

import { useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { useT } from '@/lib/useT'
import { Notice } from '@/ui/components/Notice'
import type { AssistantAnswer, AssistantAvailability } from './ai'

/**
 * IAR-9 · Pestaña «Asistente» del panel de ayuda. La respuesta llega de la API armada solo con artículos que el
 * usuario puede abrir, con sus citas y la explicación; aquí solo se muestra. Sin fuente, ofrece derivar a RRHH.
 * La IA asiste, no decide: no hay acciones, solo preguntar, abrir la fuente y valorar.
 */
export function HelpAssistantTab({ screenKey, availability, onOpenArticle, onEscalate }: {
  screenKey: string | null
  availability: AssistantAvailability
  onOpenArticle: (id: string) => void
  onEscalate?: () => void
}) {
  const { t } = useT()
  const [question, setQuestion] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [answer, setAnswer] = useState<AssistantAnswer | null>(null)
  const [rated, setRated] = useState<'up' | 'down' | null>(null)
  const [remaining, setRemaining] = useState<number | null>(availability.remaining)

  async function ask(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    setRated(null)
    try {
      const r = await api.post<{ data: AssistantAnswer }>('/ai/help-assistant/ask', { question: question.trim(), screen_key: screenKey })
      setAnswer(r.data)
      setRemaining(r.data.remaining)
    } catch (err) {
      setError(err instanceof ApiRequestError && err.code === 'ai_question_limit_reached' ? t('ai.limit_reached') : t('help.error'))
    } finally {
      setBusy(false)
    }
  }

  async function rate(rating: 'up' | 'down') {
    if (!answer) return
    setRated(rating)
    try { await api.post(`/ai/help-assistant/answers/${answer.id}/feedback`, { rating }) } catch { setRated(null) }
  }

  return (
    <div aria-live="polite">
      <p className="text-secondary small">{availability.notice ?? t('ai.intro')}</p>
      <form className="mb-3" onSubmit={ask}>
        <label className="form-label" htmlFor="ia-pregunta">{t('ai.question_label')}</label>
        <textarea id="ia-pregunta" className="form-control mb-2" rows={2} minLength={3} maxLength={500} required value={question} onChange={(e) => setQuestion(e.target.value)} />
        <div className="d-flex gap-2 align-items-center">
          <button type="submit" className="btn btn-primary btn-sm" disabled={busy || question.trim().length < 3 || remaining === 0}>{t('ai.ask')}</button>
          {remaining !== null && <span className="small text-secondary">{t('ai.remaining', { n: remaining })}</span>}
        </div>
      </form>
      {error && <Notice tone="error">{error}</Notice>}
      {answer && (
        <section aria-label={t('ai.answer')}>
          {answer.answered ? (
            <>
              <h3 className="panel-sec">{t('ai.answer')}</h3>
              <p style={{ whiteSpace: 'pre-line' }}>{answer.answer}</p>
              <h3 className="panel-sec">{t('ai.sources')}</h3>
              <ul className="ayuda-lista">
                {answer.citations.map((c) => (
                  <li key={c.id}>
                    <button type="button" className="ayuda-item" onClick={() => onOpenArticle(c.id)}>
                      <b>{c.title}</b>
                      {c.source === 'organization' && <span className="sub2">{t('help.source_org')}</span>}
                    </button>
                  </li>
                ))}
              </ul>
              <p className="small text-secondary">{answer.explanation}</p>
              <div className="d-flex gap-2 align-items-center">
                <span className="small">{t('ai.helpful_q')}</span>
                <button type="button" className="btn btn-sec btn-sm" aria-pressed={rated === 'up'} onClick={() => void rate('up')}>{t('help.helpful_yes')}</button>
                <button type="button" className="btn btn-sec btn-sm" aria-pressed={rated === 'down'} onClick={() => void rate('down')}>{t('help.helpful_no')}</button>
                {rated && <span className="small text-secondary">{t('help.helpful_thanks')}</span>}
              </div>
            </>
          ) : (
            <Notice tone="info" action={onEscalate && answer.escalation.available ? <button type="button" className="btn btn-sec btn-sm" onClick={onEscalate}>{t('help.ask_hr')}</button> : undefined}>
              {answer.explanation}
            </Notice>
          )}
          {answer.provider_is_fake && <p className="small text-secondary mt-2">{t('ai.fake_provider')}</p>}
        </section>
      )}
    </div>
  )
}
