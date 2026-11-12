import { describe, expect, it, vi } from 'vitest'
import { ApiClient, readXsrfToken } from '@/lib/api'
import { translate } from '@/lib/i18n'
import { actionLabel } from '@/modules/platform/ConsoleAudit'
import { tenantTone } from '@/modules/platform/ConsoleTenants'

describe('SAS-1 · consola de la plataforma', () => {
  it('lee su propio token XSRF y no el de la organización', () => {
    const cookies = 'XSRF-TOKEN=org; CONSOLE-XSRF-TOKEN=consola'
    expect(readXsrfToken(cookies)).toBe('org')
    expect(readXsrfToken(cookies, 'CONSOLE-XSRF-TOKEN')).toBe('consola')
  })

  it('el cliente de la consola envía el token de la consola en las escrituras', async () => {
    const fetcher = vi.fn(async () => new Response(JSON.stringify({ data: {} }), { status: 200 }))
    const client = new ApiClient('/api/v1/platform', fetcher as unknown as typeof fetch, () => 'XSRF-TOKEN=org; CONSOLE-XSRF-TOKEN=c1', 'CONSOLE-XSRF-TOKEN')
    await client.post('/auth/logout', {})
    const init = (fetcher.mock.calls[0] as unknown as [string, RequestInit])[1]
    expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('c1')
  })

  it('traduce las acciones de la auditoría y muestra el código de las desconocidas', () => {
    const t = (k: string) => translate('es', k)
    expect(actionLabel(t, 'console.login')).toBe('Ingresó a la consola')
    expect(actionLabel(t, 'console.otra_cosa')).toBe('console.otra_cosa')
  })
})

describe('SAS-2 · SAS-5 · organizaciones y módulos en la consola', () => {
  it('cada estado de la organización tiene color y texto en los tres idiomas', () => {
    for (const status of ['trial', 'active', 'grace_period', 'suspended', 'blocked', 'cancelled', 'deleted']) {
      for (const lang of ['es', 'en', 'pt'] as const) {
        const key = `console.tstatus.${status}`
        expect(translate(lang, key)).not.toBe(key)
      }
    }
    expect(tenantTone('active')).toBe('ok')
    expect(tenantTone('suspended')).toBe('er')
    expect(tenantTone('blocked')).toBe('er')
    expect(tenantTone('cancelled')).toBe('gr')
  })

  it('las acciones de organizaciones y módulos de la auditoría se traducen', () => {
    const t = (k: string) => translate('es', k)
    expect(actionLabel(t, 'tenant.suspend')).toBe('Suspendió una organización')
    expect(actionLabel(t, 'tenant.module_activated')).toBe('Activó un módulo')
  })
})
