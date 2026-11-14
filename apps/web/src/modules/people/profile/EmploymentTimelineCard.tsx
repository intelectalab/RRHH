'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Card } from '@/ui/components/Card'
import { ErrorState } from '@/ui/components/States'
import { Timeline } from '@/ui/components/Timeline'
import { timelineChangeLines, timelineTitle, timelineTone, type TimelineEvent, type TimelineType } from './timeline'
import type { ProfileHeader } from './types'

/**
 * HIS-5 · Línea de tiempo laboral de la relación: ingreso o reingreso, movimientos con su antes y después,
 * cambios de sueldo (solo si el servidor los manda: permiso de campo), contratos y adendas, suspensiones y
 * cese, lo más reciente primero. El servidor decide qué tipos ve el usuario (`meta.types`); aquí se filtran.
 */
export function EmploymentTimelineCard({ header }: { header: ProfileHeader }) {
  const { t, locale } = useT()
  const [events, setEvents] = useState<TimelineEvent[] | null>(null)
  const [types, setTypes] = useState<TimelineType[]>([])
  const [selected, setSelected] = useState<TimelineType[] | null>(null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(async () => {
    try {
      const query = selected === null ? '' : `?types=${selected.join(',')}`
      const res = await api.get<{ data: TimelineEvent[]; meta: { types: TimelineType[] } }>(`/employments/${header.id}/timeline${query}`)
      setEvents(res.data)
      if (selected === null) setTypes(res.meta.types)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [header.id, selected])

  useEffect(() => { void load() }, [load])

  function toggle(type: TimelineType) {
    const current = selected ?? types
    const next = current.includes(type) ? current.filter((x) => x !== type) : [...current, type]
    // Sin ninguno elegido se vuelve a mostrar todo.
    setSelected(next.length === 0 || next.length === types.length ? null : types.filter((x) => next.includes(x)))
  }

  const money = (m: { amount: string; currency: string } | null) => (m ? `${m.currency} ${formatNumber(locale, m.amount)}` : '—')
  const active = selected ?? types

  return (
    <Card title={t('tl.title')} context={t('tl.help')}>
      {types.length > 1 && (
        <div className="seg mb-3" role="group" aria-label={t('tl.filter')}>
          {types.map((type) => (
            <button key={type} type="button" className={active.includes(type) ? 'on' : undefined} aria-pressed={active.includes(type)} onClick={() => toggle(type)}>
              {t(`tl.type_${type}`)}
            </button>
          ))}
        </div>
      )}
      {failed && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
      {!failed && events === null && <span className="esq" style={{ width: 240 }} />}
      {!failed && events !== null && events.length === 0 && <p className="text-secondary">{t('tl.empty')}</p>}
      {!failed && events !== null && events.length > 0 && (
        <Timeline
          label={t('tl.title')}
          items={events.map((e) => ({
            id: e.id,
            tone: timelineTone(e),
            title: timelineTitle(e, t),
            when: (
              <>
                {formatDate(locale, e.date)}
                {e.status !== 'applied' && e.status !== 'active' && ` · ${t(`tl.status_${e.status}`)}`}
              </>
            ),
            text: (
              <>
                {e.type === 'hire' && typeof e.args.legal_entity_name === 'string' && <div>{e.args.legal_entity_name}</div>}
                {e.type === 'contract' && typeof e.args.contract_type_name === 'string' && (
                  <div>{e.args.contract_type_name}{typeof e.args.ends_on === 'string' && ` · ${t('tl.until', { date: formatDate(locale, e.args.ends_on) })}`}</div>
                )}
                {e.type === 'termination' && typeof e.args.reason_name === 'string' && <div>{e.args.reason_name}</div>}
                {timelineChangeLines(e, t).map((line) => <div key={line}>{line}</div>)}
                {e.salary && <div>{t('tl.salary', { before: money(e.salary.before), after: money(e.salary.after) })}</div>}
                {e.reason && <div>{t('mov.reason', { reason: e.reason })}</div>}
              </>
            ),
          }))}
        />
      )}
    </Card>
  )
}
