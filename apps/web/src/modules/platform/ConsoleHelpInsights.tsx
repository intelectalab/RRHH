'use client'

import { useEffect, useState } from 'react'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Card } from '@/ui/components/Card'
import { consoleApi } from './consoleApi'

type Insights = {
  days: number
  searches_without_result: Array<{ query_text: string; times: number; organizations: number; last_at: string }>
  feedback: Array<{ article_id: string; slug: string; locale: string; title: string; helpful: number; not_helpful: number }>
}

type SupportQuery = { id: string; question: string; status: string; tenant_name: string; created_at: string }

/**
 * AYU-5 · AYU-6 · Consola → Ayuda del producto: búsquedas sin resultado y valoración de los artículos en todas
 * las organizaciones (sin datos de las personas) y consultas de soporte enviadas por sus administradores.
 */
export function ConsoleHelpInsights() {
  const { t, locale } = useT()
  const [insights, setInsights] = useState<Insights | null>(null)
  const [support, setSupport] = useState<SupportQuery[] | null>(null)

  useEffect(() => {
    consoleApi.get<{ data: Insights }>('/help-insights').then((r) => setInsights(r.data)).catch(() => undefined)
    consoleApi.get<{ data: SupportQuery[] }>('/help-escalations').then((r) => setSupport(r.data)).catch(() => setSupport([]))
  }, [])

  return (
    <div className="mt-3">
      <Card title={t('console.help_no_results')}>
        {!insights || insights.searches_without_result.length === 0 ? <p className="text-secondary">{t('console.help_no_results_empty')}</p> : (
          <ul className="ayuda-lista">
            {insights.searches_without_result.map((s) => <li key={s.query_text} className="ayuda-item"><b>{s.query_text}</b><span className="sub2">{t('console.help_times', { n: s.times, orgs: s.organizations })}</span></li>)}
          </ul>
        )}
      </Card>
      <Card title={t('console.help_rating')}>
        {!insights || insights.feedback.length === 0 ? <p className="text-secondary">{t('console.help_rating_empty')}</p> : (
          <ul className="ayuda-lista">
            {insights.feedback.map((f) => <li key={f.article_id} className="ayuda-item"><b>{f.title}</b><span className="sub2">{f.locale} · {t('console.help_votes', { yes: f.helpful, no: f.not_helpful })}</span></li>)}
          </ul>
        )}
      </Card>
      <Card title={t('console.help_support')}>
        {!support || support.length === 0 ? <p className="text-secondary">{t('console.help_support_empty')}</p> : (
          <ul className="ayuda-lista">
            {support.map((s) => <li key={s.id} className="ayuda-item"><b>{s.question}</b><span className="sub2">{s.tenant_name} · {t(`console.help_support_status.${s.status}`)} · {formatDate(locale, s.created_at)}</span></li>)}
          </ul>
        )}
      </Card>
    </div>
  )
}
