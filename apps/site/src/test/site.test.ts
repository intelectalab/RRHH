import { readFileSync, readdirSync, statSync } from 'fs'
import { join } from 'path'
import { describe, expect, it } from 'vitest'
import es from '@/content/es.json'
import en from '@/content/en.json'
import pt from '@/content/pt.json'
import { plansIncluding, suggestPlan, type Catalog } from '@/lib/catalog'
import { fill, href, localeOfPath } from '@/lib/i18n'
import { formatMoney } from '@/lib/money'

const catalog: Catalog = {
  products: [
    { code: 'personas', name: 'Gestia Personas', acronym: 'HRIS', slug: 'personas', tagline: null, is_suite: false, sold_standalone: false, status: 'available', modules: [{ code: 'core', name: 'Core', published: true }] },
    { code: 'planilla', name: 'Gestia Planilla', acronym: 'PAY', slug: 'planilla', tagline: null, is_suite: false, sold_standalone: false, status: 'partial', modules: [{ code: 'payroll', name: 'Nómina', published: true }] },
  ],
  plans: [
    { code: 'esencial', name: 'Esencial', description: null, billing_cycle: 'monthly', trial_days: null, price: { amount: '100.00', currency: 'PEN' }, modules: ['base', 'core', 'time_off'], limits: [{ code: 'employees', unlimited: false, value: '50' }], features: [] },
    { code: 'profesional', name: 'Profesional', description: null, billing_cycle: 'monthly', trial_days: null, price: null, modules: ['base', 'core', 'time_off', 'payroll'], limits: [{ code: 'employees', unlimited: false, value: '200' }], features: [] },
  ],
  addons: [],
  modules: [],
  tax: { code: 'IGV', rate_pct: null, pending_validation: true },
}

function keys(value: unknown, prefix = ''): string[] {
  if (Array.isArray(value)) return [`${prefix}[]`]
  if (value !== null && typeof value === 'object') return Object.entries(value).flatMap(([k, v]) => keys(v, prefix ? `${prefix}.${k}` : k))
  return [prefix]
}

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? files(path) : [path]
  })
}

describe('WEB-1 · idiomas y rutas', () => {
  it('el español va sin prefijo y los demás idiomas con el suyo', () => {
    expect(href('es', '/planilla')).toBe('/planilla')
    expect(href('es', '/')).toBe('/')
    expect(href('en', '/planilla')).toBe('/en/planilla')
    expect(href('pt', '/')).toBe('/pt')
    expect(localeOfPath('/pt/precios')).toBe('pt')
    expect(localeOfPath('/precios')).toBe('es')
    expect(fill('Hasta {n} colaboradores', { n: 50 })).toBe('Hasta 50 colaboradores')
  })

  it('el contenido tiene las mismas claves en español, inglés y portugués', () => {
    expect(keys(en)).toEqual(keys(es))
    expect(keys(pt)).toEqual(keys(es))
  })
})

describe('RN-WEB-01 · sin montos escritos en el sitio', () => {
  it('ni el contenido ni el código traen precios', () => {
    const money = /(S\/|US\$|€)\s*\d/
    const sources = files(join(__dirname, '..')).filter((f) => /\.(tsx?|json)$/.test(f) && !f.includes(`${join('src', 'test')}`))
    for (const file of sources) {
      expect(readFileSync(file, 'utf8'), file).not.toMatch(money)
    }
  })

  it('formatea el monto exacto que llega de la API sin redondear en el navegador', () => {
    expect(formatMoney({ amount: '1990.00', currency: 'PEN' }, 'es')).toBe('S/ 1 990')
    expect(formatMoney({ amount: '24.50', currency: 'PEN' }, 'en')).toBe('S/ 24.50')
    expect(formatMoney({ amount: '1234567.05', currency: 'USD' }, 'pt')).toBe('US$ 1.234.567,05')
  })
})

describe('WEB-4 · buscador de plan', () => {
  it('elige el primer plan que admite a los colaboradores y trae lo pedido', () => {
    expect(suggestPlan(catalog, 30, [])?.code).toBe('esencial')
    expect(suggestPlan(catalog, 30, ['payroll'])?.code).toBe('profesional')
    expect(suggestPlan(catalog, 120, [])?.code).toBe('profesional')
    expect(suggestPlan(catalog, 500, [])).toBeNull()
    expect(suggestPlan(null, 10, [])).toBeNull()
  })

  it('un producto lo incluyen los planes que traen alguno de sus módulos', () => {
    expect(plansIncluding(catalog, catalog.products[1]).map((p) => p.code)).toEqual(['profesional'])
    expect(plansIncluding(catalog, catalog.products[0]).map((p) => p.code)).toEqual(['esencial', 'profesional'])
  })
})
