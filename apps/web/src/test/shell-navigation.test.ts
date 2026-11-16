import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { QUICK_CREATE, quickCreateHref, quickCreateOptions } from '@/modules/session/quickCreateRegistry'
import { registerServiceWorker } from '@/modules/pwa/pwa'
import es from '../../messages/es.json'
import en from '../../messages/en.json'
import pt from '../../messages/pt.json'

const allow = (perms: string[]) => (p: string) => perms.includes(p)

describe('UI-8 · creación rápida', () => {
  it('solo ofrece lo que el rol puede crear en los módulos contratados', () => {
    const all = QUICK_CREATE.map((o) => o.permission)
    expect(quickCreateOptions(['base', 'core', 'time_off'], allow(all)).map((o) => o.id)).toEqual(QUICK_CREATE.map((o) => o.id))
    // Sin el módulo de tiempo no aparecen vacaciones ni ausencias aunque tenga el permiso.
    expect(quickCreateOptions(['base', 'core'], allow(all)).map((o) => o.id)).not.toContain('vacation_request')
    // Un colaborador sin permisos de creación de RRHH no ve opciones de RRHH.
    expect(quickCreateOptions(['base', 'core', 'time_off'], allow(['time.vacation_requests.create'])).map((o) => o.id)).toEqual(['vacation_request'])
    expect(quickCreateOptions(['base'], allow([]))).toEqual([])
  })

  it('cada opción abre el formulario real con una dirección que se puede compartir', () => {
    const hire = QUICK_CREATE.find((o) => o.id === 'employee')!
    expect(quickCreateHref(hire)).toBe('/personas/colaboradores/nuevo')
    const task = QUICK_CREATE.find((o) => o.id === 'task')!
    expect(quickCreateHref(task)).toBe('/tareas?crear=task')
  })

  it('cada opción y cada grupo del menú tienen su texto en los tres idiomas', () => {
    for (const msgs of [es, en, pt] as unknown as Array<Record<string, Record<string, string>>>) {
      for (const o of QUICK_CREATE) expect(msgs.quick?.[o.key.split('.')[1]!]).toBeTruthy()
      for (const g of ['home', 'people', 'time']) expect(msgs.shell?.[`group_${g}`]).toBeTruthy()
    }
  })
})

describe('UI-9 · aplicación instalable', () => {
  it('el service worker no se registra fuera de producción', () => {
    expect(registerServiceWorker('development')).toBe(false)
    expect(registerServiceWorker('test')).toBe(false)
  })

  it('el service worker nunca guarda respuestas de la API', () => {
    const sw = readFileSync(join(__dirname, '../../public/sw.js'), 'utf8')
    expect(sw).toContain("url.pathname.startsWith('/api/')")
    expect(sw).toContain("'/sin-conexion'")
  })

  it('el manifiesto abre la aplicación en pantalla propia', () => {
    const manifest = JSON.parse(readFileSync(join(__dirname, '../../public/manifest.webmanifest'), 'utf8')) as { display: string; start_url: string; icons: unknown[] }
    expect(manifest.display).toBe('standalone')
    expect(manifest.start_url).toBe('/')
    expect(manifest.icons.length).toBeGreaterThanOrEqual(3)
  })
})
