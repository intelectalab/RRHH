import { describe, expect, it } from 'vitest'
import { lineActions, sortAlerts, type AccumulationAlert, type ScheduleLine } from '@/modules/time/vacationPlanning'

const line = (actions: string[]): ScheduleLine => ({
  id: 'l1', employment_id: 'e1', person_name: 'Ana', starts_on: '2027-02-01', ends_on: '2027-02-15', days: '15.00',
  status: 'proposed', response_note: null, vacation_request_id: null, allowed_actions: actions,
})

const alert = (id: string, overdue: number, expiring: number, days: string): AccumulationAlert => ({
  employment_id: id, person_name: id, periods_with_balance: 1, available_days: days, overdue_periods: overdue, expiring_periods: expiring, since: '2025-12-31', explanation: 'x',
})

describe('VAC-5 · rol anual', () => {
  it('el titular confirma o declina; solo quien programa retira', () => {
    const l = line(['confirm', 'decline', 'withdraw'])
    expect(lineActions(l, { canSchedule: false, isOwn: true })).toEqual(['confirm', 'decline'])
    expect(lineActions(l, { canSchedule: true, isOwn: false })).toEqual(['confirm', 'decline', 'withdraw'])
    expect(lineActions(l, { canSchedule: false, isOwn: false })).toEqual([])
  })

  it('las alertas vencidas van primero, luego las que vencen y luego las de más días', () => {
    const sorted = sortAlerts([alert('a', 0, 0, '40'), alert('b', 0, 1, '10'), alert('c', 1, 0, '5'), alert('d', 0, 0, '50')])
    expect(sorted.map((a) => a.employment_id)).toEqual(['c', 'b', 'd', 'a'])
  })
})
