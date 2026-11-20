'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { useTimeZone } from '@/modules/session/useDateTime'
import { Calendar, monthDays, viewRange, type CalendarItem, type CalendarView } from '@/ui/components/Calendar'
import { addDaysIso } from '@/ui/chartMath'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type Item = { source: string; id: string; title: string; subtitle: string | null; starts_on: string; ends_on: string; link: string; tone: string | null }
type CalendarResponse = { data: Item[]; meta: { from: string; to: string; sources: string[] } }

const VIEWS: CalendarView[] = ['month', 'week', 'agenda']

/** Lunes de la semana del primer día del mes y domingo de la semana del último (vista de 5 o 6 semanas). */
export function monthRange(month: string): { from: string; to: string; days: string[] } {
  const days = monthDays(month)
  return { from: days[0] ?? month, to: days[days.length - 1] ?? month, days }
}

function todayIn(timeZone: string | undefined): string {
  try {
    return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
  } catch {
    return new Date().toISOString().slice(0, 10)
  }
}

/**
 * Calendario central de RRHH (CFG-8): lo que publican los módulos contratados que el usuario puede ver. UI-12: vista
 * de mes, semana y agenda; una tarea se arrastra a otro día (o «Cambiar fecha» por teclado) y cambia su vencimiento.
 */
export function CalendarScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const zone = useTimeZone()
  const today = todayIn(zone)
  const { toast, node } = useToast()
  const [view, setView] = useState<CalendarView>('month')
  const [anchor, setAnchor] = useState(today)
  const [res, setRes] = useState<CalendarResponse | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [hidden, setHidden] = useState<Set<string>>(new Set())
  const range = useMemo(() => viewRange(view, anchor), [view, anchor])

  const load = useCallback(async () => {
    try {
      setRes(await api.get<CalendarResponse>(`/calendar?from=${range.from}&to=${range.to}`))
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [range.from, range.to])

  useEffect(() => {
    setRes(null)
    void load()
  }, [load])

  const shift = (n: number) => {
    if (view === 'month') {
      const d = new Date(`${anchor.slice(0, 7)}-01T00:00:00Z`)
      setAnchor(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + n, 1)).toISOString().slice(0, 10))
    } else {
      setAnchor(addDaysIso(anchor, n * (view === 'week' ? 7 : 28)))
    }
  }
  const toggle = (source: string) => setHidden((h) => {
    const next = new Set(h)
    if (next.has(source)) next.delete(source)
    else next.add(source)
    return next
  })

  const canMoveTasks = can('workflow.tasks.update')
  const items: CalendarItem[] = useMemo(() => (res?.data ?? []).filter((i) => !hidden.has(i.source)).map((i) => ({
    key: `${i.source}-${i.id}`,
    title: [i.title, i.subtitle].filter(Boolean).join(' · '),
    starts_on: i.starts_on,
    ends_on: i.ends_on,
    href: i.link,
    tone: i.tone,
    dot: i.source,
    srPrefix: t(`cal.source_${i.source}`),
    movable: canMoveTasks && i.source === 'tasks',
  })), [res, hidden, canMoveTasks, t])

  const onMove = useCallback(async (item: CalendarItem, day: string): Promise<string> => {
    const id = item.key.replace(/^tasks-/, '')
    try {
      await api.request(`/tasks/${id}`, 'PATCH', { due_on: day })
      const text = t('cal.moved', { title: item.title })
      toast(text)
      await load()
      return text
    } catch (e) {
      const text = e instanceof ApiRequestError ? e.message : t('cal.move_error')
      toast(text, 'error')
      throw new Error(text)
    }
  }, [load, t, toast])

  const title = view === 'month'
    ? new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${anchor.slice(0, 7)}-01T00:00:00Z`))
    : `${new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(`${range.from}T00:00:00Z`))} – ${new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${range.to}T00:00:00Z`))}`

  return (
    <>
      <PageHeader title={t('cal.title')} sub={t('cal.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {node}
        <section className="card">
          <div className="cal-barra">
            <div className="cal-nav">
              <button type="button" className="ibtn" onClick={() => shift(-1)} aria-label={t('cal.prev')}><Icon name="chevronLeft" /></button>
              <button type="button" className="btn btn-sec btn-sm" onClick={() => setAnchor(today)}>{t('cal.today')}</button>
              <button type="button" className="ibtn" onClick={() => shift(1)} aria-label={t('cal.next')}><Icon name="chevronRight" /></button>
              <h2 aria-live="polite">{title}</h2>
            </div>
            <div className="seg" role="group" aria-label={t('cal.view')}>
              {VIEWS.map((v) => <button key={v} type="button" aria-pressed={view === v} onClick={() => setView(v)}>{t(`cal.view_${v}`)}</button>)}
            </div>
            {res && res.meta.sources.length > 0 && (
              <div className="cal-fuentes" role="group" aria-label={t('cal.sources')}>
                {res.meta.sources.map((s) => (
                  <button key={s} type="button" aria-pressed={!hidden.has(s)} onClick={() => toggle(s)}>
                    <span className={`cal-punto ${s}`} aria-hidden="true" />{t(`cal.source_${s}`)}
                  </button>
                ))}
              </div>
            )}
          </div>

          {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
          {!loadError && res === null && <div style={{ padding: 16 }} aria-busy="true"><span className="esq" style={{ width: '60%' }} /></div>}
          {res && (
            <Calendar
              view={view}
              anchor={anchor}
              items={items}
              today={today}
              locale={locale}
              onMove={canMoveTasks ? onMove : undefined}
              labels={{ move: t('cal.move'), moveTitle: t('cal.move_title'), moveField: t('cal.move_field'), save: t('common.save'), cancel: t('common.cancel') }}
              empty={<EmptyState title={t('cal.empty_title')} text={t('cal.empty_text')} />}
            />
          )}
        </section>
      </main>
    </>
  )
}
