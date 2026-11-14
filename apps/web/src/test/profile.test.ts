import { describe, expect, it } from 'vitest'
import es from '../../messages/es.json'
import { peopleTabs } from '@/modules/people/peopleTabs'
import { ADDENDUM_KINDS, CONTRACT_STATUSES, contractButtons } from '@/modules/people/profile/ContractsTab'
import { PROFILE_TABS, visibleTabs } from '@/modules/people/profile/profileTabs'
import { seniorityText } from '@/modules/people/profile/seniority'
import { CHANGE_FIELDS, MOVEMENT_KINDS, WORK_FIELDS } from '@/modules/people/profile/types'

const ficha = (es as { ficha: Record<string, string> }).ficha
const t = (k: string, v: Record<string, string | number> = {}) => {
  const raw = k.startsWith('ficha.') ? ficha[k.slice(6)] ?? k : k
  return raw.replace(/\{(\w+)\}/g, (_, name: string) => String(v[name] ?? ''))
}

describe('ficha 360 (COL-3)', () => {
  it('las pestañas aparecen solo con el módulo contratado y el permiso', () => {
    const all = () => true
    expect(visibleTabs(PROFILE_TABS, ['core'], all).map((x) => x.id)).toEqual(['summary', 'info', 'history', 'contracts', 'compensation', 'dependents', 'social_security', 'payment', 'documents'])
    expect(visibleTabs(PROFILE_TABS, [], all)).toEqual([])
    // HIS-5: el Historial (línea de tiempo) basta con ver la ficha; sin ella, no aparece.
    expect(visibleTabs(PROFILE_TABS, ['core'], (p) => p !== 'people.assignments.view').map((x) => x.id)).toContain('history')
    expect(visibleTabs(PROFILE_TABS, ['core'], (p) => p !== 'people.employments.view').map((x) => x.id)).not.toContain('history')
    // HIS-3: sin el permiso de campo de sueldo, la pestaña de compensación no aparece.
    expect(visibleTabs(PROFILE_TABS, ['core'], (p) => p !== 'people.employments.salary.view').map((x) => x.id)).not.toContain('compensation')
    // CON-2: sin permiso de ver contratos, esa pestaña no aparece.
    expect(visibleTabs(PROFILE_TABS, ['core'], (p) => p !== 'contracts.contracts.view').map((x) => x.id)).not.toContain('contracts')
    // COL-6: sin permiso de derechohabientes, esa pestaña tampoco aparece.
    expect(visibleTabs(PROFILE_TABS, ['core'], (p) => p !== 'people.dependents.view').map((x) => x.id)).not.toContain('dependents')
    // VAC-2: la pestaña Vacaciones exige el módulo time_off y el permiso de ver saldos.
    expect(visibleTabs(PROFILE_TABS, ['core', 'time_off'], all).map((x) => x.id).slice(-2)).toEqual(['vacations', 'absences'])
    expect(visibleTabs(PROFILE_TABS, ['core', 'time_off'], (p) => p !== 'time.vacation_balances.view').map((x) => x.id)).not.toContain('vacations')
  })

  it('AUS-2 · la pestaña Ausencias aparece con el módulo time_off y el permiso de ver ausencias', () => {
    expect(visibleTabs(PROFILE_TABS, ['core', 'time_off'], () => true).map((x) => x.id)).toContain('absences')
    expect(visibleTabs(PROFILE_TABS, ['core', 'time_off'], (p) => p !== 'time.absences.view').map((x) => x.id)).not.toContain('absences')
    expect(ficha.tab_absences).toBeTruthy()
  })

  it('CON-2 · la pestaña Contratos ofrece solo las acciones permitidas por el estado y el permiso', () => {
    expect(visibleTabs(PROFILE_TABS, ['core'], (p) => p !== 'contracts.contracts.view').map((x) => x.id)).not.toContain('contracts')
    const draft = { status: 'draft' as const, allowed_actions: ['submit', 'register-signature', 'void'] }
    expect(contractButtons(draft, () => true)).toEqual(['edit', 'submit', 'register-signature', 'void'])
    expect(contractButtons(draft, (p) => p === 'contracts.contracts.issue')).toEqual(['register-signature'])
    // Las acciones de sistema (inicio, vencimiento) no son botones.
    expect(contractButtons({ status: 'signed', allowed_actions: ['start', 'void'] }, () => true)).toEqual(['void'])
  })

  it('cada estado, acción y tipo de adenda del contrato tiene su texto', () => {
    const ctr = (es as unknown as { ctr: Record<string, string> }).ctr
    for (const s of CONTRACT_STATUSES) expect(ctr[`status_${s}`], s).toBeTruthy()
    for (const k of [...ADDENDUM_KINDS, 'position']) expect(ctr[`kind_${k}`], k).toBeTruthy()
    for (const a of ['edit', 'submit', 'approve', 'return', 'register-signature', 'decline-signature', 'void', 'terminate', 'renew']) expect(ctr[`act_${a}`], a).toBeTruthy()
    expect(ficha.tab_contracts).toBeTruthy()
  })

  it('la antigüedad que calcula el servidor se lee en palabras, omitiendo las partes en cero', () => {
    expect(seniorityText(t, { from: '2020-01-01', to: '2022-04-06', years: 2, months: 3, days: 5, total_days: 826 })).toBe('2 años, 3 meses y 5 días')
    expect(seniorityText(t, { from: '2025-01-01', to: '2026-01-01', years: 1, months: 0, days: 0, total_days: 365 })).toBe('1 año')
    expect(seniorityText(t, { from: '2026-01-01', to: '2026-01-01', years: 0, months: 0, days: 0, total_days: 0 })).toBe('0 días')
  })

  it('cada dato laboral, tipo de movimiento y campo de cambio tiene su texto', () => {
    for (const f of WORK_FIELDS) expect(ficha[`f_${f}`], f).toBeTruthy()
    for (const k of [...MOVEMENT_KINDS, 'hire']) expect(ficha[`kind_${k}`], k).toBeTruthy()
    for (const c of CHANGE_FIELDS) expect(ficha[`change_${c}`], c).toBeTruthy()
  })

  it('RRHH ve la pestaña Cambios de datos solo con permiso de aprobar', () => {
    expect(peopleTabs(t, 'requests', (p) => p === 'people.change_requests.approve').map((x) => x.href)).toContain('/personas/cambios-de-datos')
    expect(peopleTabs(t, 'directory', () => false).map((x) => x.href)).not.toContain('/personas/cambios-de-datos')
  })
})
