import { describe, expect, it } from 'vitest'
import { nextStep, packState, pendingComponents, type CountryPack, type SetupChecklist } from '@/modules/setup/setup'

const step = (code: string, status: 'pending' | 'done' | 'skipped') => ({
  code, title: code, description: '', module_code: 'core', status, link: `/${code}`, completed_at: null,
  allowed_actions: status === 'pending' ? ['skip' as const] : status === 'skipped' ? ['reopen' as const] : [],
})

const pack = (over: Partial<CountryPack> = {}): CountryPack => ({
  code: 'pe-base', name: 'Perú', country_code: 'PE', version: '1.0.0', released_on: '2026-11-24', changelog: {}, pending_validation: [],
  installed_version: null, installed_at: null, update_available: false,
  components: [
    { code: 'contract_types', name: 'Tipos', module_code: 'core', module_enabled: true, items: ['a', 'b'], total: 2, present: 0, link: '/x' },
    { code: 'vacation_policies', name: 'Políticas', module_code: 'time_off', module_enabled: false, items: ['c'], total: 1, present: 0, link: '/y' },
  ],
  ...over,
})

describe('primeros pasos (IMP-3)', () => {
  it('propone el primer paso pendiente, saltando los hechos y omitidos', () => {
    const data: SetupChecklist = { progress: 50, total: 3, done: 1, skipped: 1, completed: false, dismissed: false, visible: true,
      steps: [step('company', 'done'), step('sites', 'skipped'), step('structure', 'pending')] }
    expect(nextStep(data)?.code).toBe('structure')
    expect(nextStep({ ...data, steps: [step('company', 'done')] })).toBeNull()
  })
})

describe('plantillas del país (IMP-4)', () => {
  it('distingue aplicar, completar lo que falta y paquete al día; ignora módulos no contratados', () => {
    expect(packState(pack())).toBe('apply')
    expect(pendingComponents(pack()).map((c) => c.code)).toEqual(['contract_types'])
    const installed = pack({ installed_version: '1.0.0', installed_at: '2026-11-24T10:00:00Z' })
    expect(packState(installed)).toBe('update')
    const full = pack({ installed_version: '1.0.0', components: installed.components.map((c) => ({ ...c, present: c.total })) })
    expect(packState(full)).toBe('complete')
    expect(packState({ ...full, update_available: true })).toBe('update')
  })
})
