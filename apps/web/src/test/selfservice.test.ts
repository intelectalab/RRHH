import { describe, expect, it } from 'vitest'
import { CHANGE_FIELDS } from '@/modules/people/profile/types'
import { cardOf, days, isActive, portalNav, prefersPortal, proposableFields } from '@/modules/selfservice/portal'
import type { HomeCard } from '@/modules/selfservice/types'
import es from '../../messages/es.json'
import en from '../../messages/en.json'
import pt from '../../messages/pt.json'

describe('ESS-1 · portal del colaborador', () => {
  it('muestra solo destinos de módulos contratados y con permiso', () => {
    const can = (p: string) => ['time.absences.view', 'people.employments.view'].includes(p)
    const hrefs = portalNav(['core', 'base'], can).map((n) => n.href)
    expect(hrefs).toEqual(['/mi', '/mi/perfil', '/pendientes'])
    expect(portalNav(['core', 'base', 'time_off'], can).map((n) => n.href)).toContain('/tiempo/ausencias')
    expect(portalNav(['core', 'base', 'time_off'], can).map((n) => n.href)).not.toContain('/tiempo/vacaciones')
  })

  it('lleva al portal a quien solo ve su propia relación laboral', () => {
    expect(prefersPortal({ 'people.employments.view': 'own' })).toBe(true)
    expect(prefersPortal({ 'people.employments.view': 'organization' })).toBe(false)
    expect(prefersPortal({})).toBe(false)
  })

  it('marca activo el destino exacto y las subrutas', () => {
    expect(isActive('/mi', '/mi', true)).toBe(true)
    expect(isActive('/mi/perfil', '/mi', true)).toBe(false)
    expect(isActive('/tiempo/vacaciones/123', '/tiempo/vacaciones', false)).toBe(true)
  })

  it('encuentra cada tarjeta por su clave', () => {
    const cards: HomeCard[] = [
      { key: 'absences', module: 'time_off', href: '/tiempo/ausencias', pending: 1, data: { pending_requests: 1, upcoming: [] } },
    ]
    expect(cardOf(cards, 'absences')?.data.pending_requests).toBe(1)
    expect(cardOf(cards, 'vacations')).toBeUndefined()
  })

  it('formatea los días que llegan del servidor sin calcularlos', () => {
    expect(days('15.00')).toBe('15')
    expect(days('7.50')).toBe('7.5')
    expect(days('30')).toBe('30')
    expect(days(null)).toBe('0')
  })

  it('tiene las claves del portal en los tres idiomas', () => {
    const base = Object.keys(es.portal).sort()
    expect(Object.keys(en.portal).sort()).toEqual(base)
    expect(Object.keys(pt.portal).sort()).toEqual(base)
  })
})

describe('ESS-2 · mi perfil', () => {
  it('ofrece solo los datos que la organización habilita en el portal', () => {
    expect(proposableFields(CHANGE_FIELDS, ['mobile_phone', 'address', 'sex'])).toEqual(['mobile_phone', 'address'])
    expect(proposableFields(CHANGE_FIELDS, undefined)).toEqual([...CHANGE_FIELDS])
    expect(proposableFields(CHANGE_FIELDS, [])).toEqual([])
  })
})
