import { describe, expect, it } from 'vitest'
import { daysLeftTone } from '@/modules/people/contractsExpiring'
import { peopleTabs } from '@/modules/people/peopleTabs'
import { timelineChangeLines, timelineTitle, timelineTone, type TimelineEvent } from '@/modules/people/profile/timeline'

const t = (k: string, v?: Record<string, string | number>) => (v ? `${k}(${Object.entries(v).map(([a, b]) => `${a}=${b}`).join(',')})` : k)

const base: TimelineEvent = { id: 'x', date: '2026-01-01', type: 'hire', kind: 'hire', status: 'applied', args: {}, changes: [], reason: null, ref_type: 'employment', ref_id: 'e' }

describe('HIS-5 · línea de tiempo laboral', () => {
  it('titula cada tipo con los nombres de su módulo', () => {
    expect(timelineTitle(base, t)).toBe('tl.kind_hire')
    expect(timelineTitle({ ...base, type: 'movement', kind: 'transfer' }, t)).toBe('ficha.kind_transfer')
    expect(timelineTitle({ ...base, type: 'compensation', kind: 'merit' }, t)).toBe('tl.compensation(reason=comp.reason_merit)')
    expect(timelineTitle({ ...base, type: 'contract', kind: 'addendum_salary' }, t)).toBe('ctr.kind_salary')
    expect(timelineTitle({ ...base, type: 'contract', kind: 'contract_renewal' }, t)).toBe('tl.kind_contract_renewal')
  })

  it('muestra el antes y el después con los nombres del servidor y traduce la modalidad', () => {
    const e: TimelineEvent = { ...base, type: 'movement', kind: 'transfer', changes: [
      { field: 'org_unit_id', before: { value: 'u1', label: 'Operaciones' }, after: { value: 'u2', label: 'Logística' } },
      { field: 'work_modality', before: null, after: { value: 'remote', label: null } },
    ] }
    expect(timelineChangeLines(e, t)).toEqual([
      'mov.line(field=emp.f_unit,before=Operaciones,after=Logística)',
      'mov.line(field=emp.f_modality,before=ficha.value_empty,after=emp.modality_remote)',
    ])
  })

  it('marca lo programado y el cese', () => {
    expect(timelineTone({ ...base, status: 'scheduled', type: 'termination' })).toBe('wa')
    expect(timelineTone({ ...base, type: 'termination', kind: 'termination' })).toBe('er')
    expect(timelineTone({ ...base, status: 'replaced' })).toBe('gr')
    expect(timelineTone(base)).toBe('ok')
  })
})

describe('CON-4 · contratos por vencer', () => {
  it('colorea los días restantes según los umbrales de la empresa', () => {
    expect(daysLeftTone(-3, [60, 30, 15])).toBe('er')
    expect(daysLeftTone(10, [60, 30, 15])).toBe('wa')
    expect(daysLeftTone(45, [60, 30, 15])).toBe('in')
  })

  it('la pestaña aparece solo con permiso de ver contratos y la ficha', () => {
    const tabs = (perms: string[]) => peopleTabs((k) => k, 'contracts', (p) => perms.includes(p)).map((x) => x.href)
    expect(tabs(['people.employments.view', 'contracts.contracts.view'])).toContain('/personas/contratos')
    expect(tabs(['people.employments.view'])).not.toContain('/personas/contratos')
  })
})
