import { describe, expect, it } from 'vitest'
import { customCell, customColumns, customFilters, exportFilters } from '@/modules/customfields/customTable'
import { cleanPin } from '@/modules/identity/QuickUnlock'
import { subscriptionBody, vapidKeyToBytes } from '@/modules/notifications/push'
import { PORTAL_ACTIONS, portalActions, splitNav } from '@/modules/selfservice/portal'
import type { HomeCard } from '@/modules/selfservice/types'

const t = (k: string) => k

describe('CFG-5 · campos personalizados en tablas', () => {
  const fields = [
    { key: 'area', label: 'Área', type: 'select', options: ['Ventas', 'Planta'], required: false, sensitive: false, section: null },
    { key: 'remoto', label: 'Remoto', type: 'checkbox', options: null, required: false, sensitive: false, section: null },
    { key: 'meta', label: 'Meta', type: 'currency', options: null, required: false, sensitive: true, section: null },
  ]

  it('una columna por campo, oculta al inicio, con el valor formateado', () => {
    const cols = customColumns<{ custom_fields?: Record<string, unknown> }>(fields, t)
    expect(cols.map((c) => c.key)).toEqual(['custom.area', 'custom.remoto', 'custom.meta'])
    expect(cols.every((c) => c.hiddenByDefault)).toBe(true)
    expect(customCell(fields[2], { amount: '1200.50', currency: 'PEN' }, t)).toBe('PEN 1200.50')
    expect(customCell(fields[1], true, t)).toBe('cf.yes')
    expect(customCell(fields[0], null, t)).toBe('—')
    expect(customCell({ type: 'multiselect' }, ['a', 'b'], t)).toBe('a, b')
  })

  it('filtros solo para campos con opciones; la exportación lleva los custom.*', () => {
    expect(customFilters(fields, t).map((f) => f.key)).toEqual(['custom.area', 'custom.remoto'])
    expect(exportFilters({ status: 'active', 'custom.area': 'Ventas', site_id: 'x' }, ['status'])).toEqual({ status: 'active', 'custom.area': 'Ventas' })
  })
})

describe('NOT-4 · push web', () => {
  it('la clave VAPID base64url se convierte en bytes', () => {
    expect(Array.from(vapidKeyToBytes('AQID_w'))).toEqual([1, 2, 3, 255])
  })

  it('la suscripción del navegador viaja con endpoint y claves; incompleta no se envía', () => {
    expect(subscriptionBody({ endpoint: 'https://p.example/1', keys: { p256dh: 'k', auth: 'a' } }, 'Teléfono'))
      .toEqual({ subscription: { endpoint: 'https://p.example/1', keys: { p256dh: 'k', auth: 'a' } }, label: 'Teléfono' })
    expect(subscriptionBody({ endpoint: 'https://p.example/1' }, 'x')).toBeNull()
  })
})

describe('ESS-6 · portal en el móvil', () => {
  it('la barra inferior lleva hasta cinco destinos; el resto va a «Más»', () => {
    expect(splitNav([1, 2, 3, 4, 5])).toEqual({ bar: [1, 2, 3, 4, 5], more: [] })
    expect(splitNav([1, 2, 3, 4, 5, 6, 7])).toEqual({ bar: [1, 2, 3, 4], more: [5, 6, 7] })
  })

  it('las acciones a un toque salen de las tarjetas activas, con permiso y sin escribir en solo lectura', () => {
    const cards = [{ key: 'vacations' }, { key: 'profile' }] as unknown as HomeCard[]
    const can = (p: string) => p === 'time.vacation_requests.create'
    expect(portalActions(cards, can, false).map((a) => a.key)).toEqual(['portal.request_vacation', 'portal.my_profile'])
    expect(portalActions(cards, can, true).map((a) => a.key)).toEqual(['portal.my_profile'])
    expect(portalActions(cards, () => false, false).map((a) => a.key)).toEqual(['portal.my_profile'])
    // Pedir vacaciones abre directo el formulario (un toque).
    expect(PORTAL_ACTIONS.find((a) => a.card === 'vacations')?.href).toContain('?crear=vacation_request')
  })

  it('el PIN acepta solo dígitos y como máximo ocho', () => {
    expect(cleanPin('12a3 4-5678 9')).toBe('12345678')
  })
})
