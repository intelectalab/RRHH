'use client'

import Link from 'next/link'
import { useCallback, useMemo, useState, type ReactNode } from 'react'
import { addDaysIso, daysBetween } from '../chartMath'
import { Icon } from './Icon'
import { Modal } from './Modal'
import { useDragMove } from '../useDragMove'

export type CalendarView = 'month' | 'week' | 'agenda'
export type CalendarItem = { key: string; title: string; starts_on: string; ends_on: string; href?: string; tone?: string | null; dot?: string; srPrefix?: string; movable?: boolean }

const utc = (s: string) => new Date(`${s}T00:00:00Z`)

/** Lunes de la semana del primer día del mes y domingo de la semana del último (vista de 5 o 6 semanas). */
export function monthDays(month: string): string[] {
  const first = utc(month)
  const last = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0)).toISOString().slice(0, 10)
  const from = addDaysIso(month, -((first.getUTCDay() + 6) % 7))
  const to = addDaysIso(last, (7 - utc(last).getUTCDay()) % 7)
  const days: string[] = []
  for (let d = from; d <= to; d = addDaysIso(d, 1)) days.push(d)
  return days
}

/** Los siete días (lunes a domingo) de la semana de `day`. */
export function weekDays(day: string): string[] {
  const from = addDaysIso(day, -((utc(day).getUTCDay() + 6) % 7))
  return Array.from({ length: 7 }, (_, i) => addDaysIso(from, i))
}

/** Rango de fechas que pinta una vista (la agenda muestra cuatro semanas desde el día de referencia). */
export function viewRange(view: CalendarView, anchor: string): { from: string; to: string; days: string[] } {
  const days = view === 'month' ? monthDays(`${anchor.slice(0, 7)}-01`) : view === 'week' ? weekDays(anchor) : Array.from({ length: 28 }, (_, i) => addDaysIso(anchor, i))
  return { from: days[0] ?? anchor, to: days[days.length - 1] ?? anchor, days }
}

/**
 * UI-12 · Calendario reutilizable: mes, semana (periodo corto) y agenda. Los elementos `movable` se arrastran a
 * otro día (ratón o dedo) y `onMove` ejecuta la acción real; el elemento solo cambia de día cuando el servidor lo
 * confirma. Por teclado, cada elemento movible ofrece «Cambiar fecha» con un campo de fecha. En pantallas
 * angostas la vista de mes pasa a la lista del día (RN-UI-06).
 */
export function Calendar({ view, anchor, items, today, locale, onMove, labels, empty }: {
  view: CalendarView
  anchor: string
  items: CalendarItem[]
  today: string
  locale: string
  onMove?: (item: CalendarItem, toDay: string) => Promise<string>
  labels: { move: string; moveTitle: string; moveField: string; save: string; cancel: string }
  empty?: ReactNode
}) {
  const range = useMemo(() => viewRange(view, anchor), [view, anchor])
  const [status, setStatus] = useState('')
  const [editing, setEditing] = useState<{ item: CalendarItem; day: string } | null>(null)

  const byDay = useMemo(() => {
    const map = new Map<string, CalendarItem[]>()
    for (const item of items) {
      for (let d = item.starts_on < range.from ? range.from : item.starts_on; d <= item.ends_on && d <= range.to; d = addDaysIso(d, 1)) {
        map.set(d, [...(map.get(d) ?? []), item])
      }
    }
    return map
  }, [items, range.from, range.to])

  const move = useCallback(async (item: CalendarItem, day: string) => {
    if (!onMove || !item.movable || day === item.starts_on) return
    try {
      setStatus(await onMove(item, day))
    } catch (e) {
      setStatus(e instanceof Error ? e.message : String(e))
    }
  }, [onMove])
  const drop = useCallback((item: CalendarItem, day: string) => void move(item, day), [move])
  const { dragging, over, onPointerDown } = useDragMove<CalendarItem>(drop)

  const dayLabel = (d: string) => new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(utc(d))
  const weekdays = range.days.slice(0, 7).map((d) => new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' }).format(utc(d)))
  const inMonth = (d: string) => view !== 'month' || d.slice(0, 7) === anchor.slice(0, 7)
  const shown = range.days.filter((d) => inMonth(d) && (byDay.get(d)?.length ?? 0) > 0)

  const entry = (item: CalendarItem, day: string) => {
    const content = <span><span className="vh">{item.srPrefix ? `${item.srPrefix}: ` : ''}</span>{item.title}</span>
    const cls = `cal-item${item.tone === 'overdue' ? ' overdue' : ''}${item.movable ? ' movible' : ''}${dragging?.key === item.key ? ' arrastrando' : ''}`
    return (
      <span key={`${item.key}-${day}`} className="cal-item-wrap" onPointerDown={item.movable && onMove ? onPointerDown(item) : undefined}>
        {item.href
          ? <Link href={item.href} className={cls} title={item.title} draggable={false}><span className={`cal-punto ${item.dot ?? ''}`} aria-hidden="true" />{content}</Link>
          : <span className={cls} title={item.title}><span className={`cal-punto ${item.dot ?? ''}`} aria-hidden="true" />{content}</span>}
        {item.movable && onMove && (
          <button type="button" className="cal-mover" onClick={() => setEditing({ item, day: item.starts_on })} aria-label={`${labels.move}: ${item.title}`}><Icon name="more" /></button>
        )}
      </span>
    )
  }

  const canDropOn = (d: string) => dragging !== null && d !== dragging.starts_on

  return (
    <div className="cal">
      <p className="vh" role="status" aria-live="polite">{status}</p>
      {view !== 'agenda' && (
        <>
          <div className="cal-grid cal-dow-row" aria-hidden="true">
            {weekdays.map((w, i) => <div key={i} className="cal-dow">{w}</div>)}
          </div>
          <div className={`cal-grid${view === 'week' ? ' cal-semana' : ''}`} role="list">
            {range.days.map((d) => (
              <div
                key={d}
                role="listitem"
                data-drop={canDropOn(d) ? d : undefined}
                className={`cal-dia${inMonth(d) ? '' : ' fuera'}${d === today ? ' hoy' : ''}${canDropOn(d) && over === d ? ' encima' : ''}`}
                aria-label={dayLabel(d)}
              >
                <span className="cal-num" aria-current={d === today ? 'date' : undefined}>{Number(d.slice(8))}</span>
                {(byDay.get(d) ?? []).map((i) => entry(i, d))}
              </div>
            ))}
          </div>
        </>
      )}
      <ul className={`cal-lista${view === 'agenda' ? ' cal-agenda' : ''}`}>
        {shown.map((d) => (
          <li key={d} data-drop={canDropOn(d) ? d : undefined}>
            <h3>{dayLabel(d)}{d === today ? ' ·' : ''}</h3>
            {(byDay.get(d) ?? []).map((i) => entry(i, d))}
          </li>
        ))}
      </ul>
      {shown.length === 0 && empty}
      {editing && (
        <Modal
          title={labels.moveTitle}
          onClose={() => setEditing(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setEditing(null)}>{labels.cancel}</button>
              <button type="button" className="btn btn-primary" onClick={() => { const e = editing; setEditing(null); void move(e.item, e.day) }}>{labels.save}</button>
            </>
          )}
        >
          <p><b>{editing.item.title}</b></p>
          <div className="mb-3">
            <label className="form-label" htmlFor="cal-mover-dia">{labels.moveField}</label>
            <input id="cal-mover-dia" type="date" className="form-control" value={editing.day} onChange={(e) => setEditing({ ...editing, day: e.target.value })} />
          </div>
          {daysBetween(editing.item.starts_on, editing.day) !== 0 && <p className="small">{dayLabel(editing.day)}</p>}
        </Modal>
      )}
    </div>
  )
}
