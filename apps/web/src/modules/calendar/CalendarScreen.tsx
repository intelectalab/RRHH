'use client'

import Link from 'next/link'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useTimeZone } from '@/modules/session/useDateTime'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'

type Item = { source: string; id: string; title: string; subtitle: string | null; starts_on: string; ends_on: string; link: string; tone: string | null }
type CalendarResponse = { data: Item[]; meta: { from: string; to: string; sources: string[] } }

/** Días como texto AAAA-MM-DD, con aritmética en UTC para no depender de la zona del navegador. */
const iso = (d: Date) => d.toISOString().slice(0, 10)
const utc = (s: string) => new Date(`${s}T00:00:00Z`)
const addDays = (s: string, n: number) => {
  const d = utc(s)
  d.setUTCDate(d.getUTCDate() + n)
  return iso(d)
}

/** Lunes de la semana del primer día del mes y domingo de la semana del último (vista de 5 o 6 semanas). */
export function monthRange(month: string): { from: string; to: string; days: string[] } {
  const first = utc(month)
  const last = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0))
  const from = addDays(month, -((first.getUTCDay() + 6) % 7))
  const to = addDays(iso(last), (7 - last.getUTCDay()) % 7)
  const days: string[] = []
  for (let d = from; d <= to; d = addDays(d, 1)) days.push(d)
  return { from, to, days }
}

function todayIn(timeZone: string | undefined): string {
  try {
    return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
  } catch {
    return iso(new Date())
  }
}

/** Calendario central de RRHH (CFG-8): lo que publican los módulos contratados que el usuario puede ver. */
export function CalendarScreen() {
  const { t, locale } = useT()
  const zone = useTimeZone()
  const today = todayIn(zone)
  const [month, setMonth] = useState(`${today.slice(0, 7)}-01`)
  const [res, setRes] = useState<CalendarResponse | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [hidden, setHidden] = useState<Set<string>>(new Set())
  const range = useMemo(() => monthRange(month), [month])

  const load = useCallback(async () => {
    setRes(null)
    try {
      setRes(await api.get<CalendarResponse>(`/calendar?from=${range.from}&to=${range.to}`))
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [range.from, range.to])

  useEffect(() => {
    void load()
  }, [load])

  const shift = (n: number) => {
    const d = utc(month)
    setMonth(iso(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + n, 1))))
  }
  const toggle = (source: string) => setHidden((h) => {
    const next = new Set(h)
    if (next.has(source)) next.delete(source)
    else next.add(source)
    return next
  })

  const byDay = useMemo(() => {
    const map = new Map<string, Item[]>()
    for (const item of res?.data ?? []) {
      if (hidden.has(item.source)) continue
      for (let d = item.starts_on; d <= item.ends_on; d = addDays(d, 1)) {
        if (d < range.from || d > range.to) continue
        map.set(d, [...(map.get(d) ?? []), item])
      }
    }
    return map
  }, [res, hidden, range.from, range.to])

  const title = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(utc(month))
  const weekdays = range.days.slice(0, 7).map((d) => new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' }).format(utc(d)))
  const dayLabel = (d: string) => new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(utc(d))
  const inMonth = (d: string) => d.slice(0, 7) === month.slice(0, 7)
  const monthDays = range.days.filter((d) => inMonth(d) && (byDay.get(d)?.length ?? 0) > 0)

  const entry = (item: Item) => (
    <Link key={`${item.source}-${item.id}`} href={item.link} className={`cal-item${item.tone === 'overdue' ? ' overdue' : ''}`} title={[item.title, item.subtitle].filter(Boolean).join(' · ')}>
      <span className={`cal-punto ${item.source}`} aria-hidden="true" />
      <span><span className="vh">{t(`cal.source_${item.source}`)}: </span>{item.title}</span>
    </Link>
  )

  return (
    <>
      <PageHeader title={t('cal.title')} sub={t('cal.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="cal-barra">
            <div className="cal-nav">
              <button type="button" className="ibtn" onClick={() => shift(-1)} aria-label={t('cal.prev')}><Icon name="chevronLeft" /></button>
              <button type="button" className="btn btn-sec btn-sm" onClick={() => setMonth(`${today.slice(0, 7)}-01`)}>{t('cal.today')}</button>
              <button type="button" className="ibtn" onClick={() => shift(1)} aria-label={t('cal.next')}><Icon name="chevronRight" /></button>
              <h2 aria-live="polite">{title}</h2>
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
            <>
              <div className="cal-grid cal-dow-row" aria-hidden="true">
                {weekdays.map((w, i) => <div key={i} className="cal-dow">{w}</div>)}
              </div>
              <div className="cal-grid" role="list" aria-label={title}>
                {range.days.map((d) => {
                  const items = byDay.get(d) ?? []
                  return (
                    <div key={d} role="listitem" className={`cal-dia${inMonth(d) ? '' : ' fuera'}${d === today ? ' hoy' : ''}`} aria-label={dayLabel(d)}>
                      <span className="cal-num" aria-current={d === today ? 'date' : undefined}>{Number(d.slice(8))}</span>
                      {items.map(entry)}
                    </div>
                  )
                })}
              </div>
              <ul className="cal-lista">
                {monthDays.map((d) => (
                  <li key={d}>
                    <h3>{dayLabel(d)}</h3>
                    {(byDay.get(d) ?? []).map(entry)}
                  </li>
                ))}
              </ul>
              {monthDays.length === 0 && <EmptyState title={t('cal.empty_title')} text={t('cal.empty_text')} />}
            </>
          )}
        </section>
      </main>
    </>
  )
}
