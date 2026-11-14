import { describe, expect, it } from 'vitest'
import en from '../../messages/en.json'
import es from '../../messages/es.json'
import pt from '../../messages/pt.json'
import { isoDay } from '@/modules/time/TeamCalendarView'
import { cellTone, itemOn, VACATION_REQUEST_STATUSES, type TeamItem } from '@/modules/time/vacationRequests'

const item = (over: Partial<TeamItem>): TeamItem => ({
  id: 'x', source: 'vacation', label: 'Vacaciones', status: 'approved', pending: false, starts_on: '2026-12-01', ends_on: '2026-12-04', link: null, ...over,
})

describe('vacaciones (VAC-3 · VAC-4)', () => {
  it('la celda del calendario toma el elemento que cubre el día y su tono', () => {
    const items = [item({}), item({ id: 'y', source: 'absence', starts_on: '2026-12-07', ends_on: '2026-12-07' })]
    expect(itemOn(items, '2026-12-01')?.id).toBe('x')
    expect(itemOn(items, '2026-12-04')?.id).toBe('x')
    expect(itemOn(items, '2026-12-05')).toBeNull()
    expect(cellTone(itemOn(items, '2026-12-07'))).toBe('abs')
    expect(cellTone(item({ pending: true }))).toBe('pend')
    expect(cellTone(item({ source: 'other', id: null }))).toBe('other')
    expect(cellTone(null)).toBe('')
  })

  it('el rango inicial de la vista usa fechas locales sin hora', () => {
    expect(isoDay(new Date(2026, 10, 30), 1)).toBe('2026-12-01')
    expect(isoDay(new Date(2026, 0, 5))).toBe('2026-01-05')
  })

  it('cada estado de la solicitud tiene su texto en los tres idiomas', () => {
    for (const messages of [es, en, pt] as Array<{ vreq: Record<string, string>; shell: Record<string, string> }>) {
      for (const s of VACATION_REQUEST_STATUSES) expect(messages.vreq[`status_${s}`]).toBeTruthy()
      expect(messages.shell.modules_vacations).toBeTruthy()
      expect(Object.keys(messages.vreq).sort()).toEqual(Object.keys(es.vreq).sort())
    }
  })
})
