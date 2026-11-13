import { describe, expect, it } from 'vitest'
import es from '../../messages/es.json'
import { peopleTabs } from '@/modules/people/peopleTabs'
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
    expect(visibleTabs(PROFILE_TABS, ['core'], all).map((x) => x.id)).toEqual(['summary', 'info', 'history', 'compensation', 'dependents', 'social_security', 'payment'])
    expect(visibleTabs(PROFILE_TABS, [], all)).toEqual([])
    // Sin permiso de ver la historia de asignaciones, esa pestaña no aparece.
    expect(visibleTabs(PROFILE_TABS, ['core'], (p) => p !== 'people.assignments.view').map((x) => x.id)).toEqual(['summary', 'info', 'compensation', 'dependents', 'social_security', 'payment'])
    // HIS-3: sin el permiso de campo de sueldo, la pestaña de compensación no aparece.
    expect(visibleTabs(PROFILE_TABS, ['core'], (p) => p !== 'people.employments.salary.view').map((x) => x.id)).not.toContain('compensation')
    // COL-6: sin permiso de derechohabientes, esa pestaña tampoco aparece.
    expect(visibleTabs(PROFILE_TABS, ['core'], (p) => p !== 'people.dependents.view').map((x) => x.id)).not.toContain('dependents')
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
