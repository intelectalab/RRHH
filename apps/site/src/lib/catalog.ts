import type { Money } from './money'

/**
 * WEB-2 · Catálogo público de la plataforma (GET /api/v1/public/catalog). Planes, precios, complementos, módulos a
 * la venta e impuesto salen de la consola (RN-WEB-01): el sitio no tiene montos propios.
 */
export type ProductStatus = 'available' | 'partial' | 'coming_soon'
export type CatalogModule = { code: string; name: string; published: boolean; depends_on: string[] }
export type CatalogProduct = {
  code: string
  name: string
  acronym: string
  slug: string
  tagline: string | null
  is_suite: boolean
  sold_standalone: boolean
  status: ProductStatus
  modules: { code: string; name: string; published: boolean }[]
}
export type CatalogPlan = {
  code: string
  name: string
  description: string | null
  billing_cycle: 'monthly' | 'annual'
  trial_days: number | null
  price: Money | null
  modules: string[]
  limits: { code: string; unlimited: boolean; value: string | null }[]
  features: string[]
}
export type CatalogAddon = {
  code: string
  name: string
  web_product_code: string | null
  billing_cycle: string
  charge_basis: string
  modules: string[]
  price: Money | null
}
export type Catalog = {
  products: CatalogProduct[]
  plans: CatalogPlan[]
  addons: CatalogAddon[]
  modules: CatalogModule[]
  tax: { code: string; rate_pct: string | null; pending_validation: boolean }
}

export type Quote = {
  plan: { code: string; name: string; billing_cycle: string }
  cycle: string
  employees: number
  lines: { kind: 'plan' | 'addon'; code: string; name: string; quantity: string; unit_price: Money | null; amount: Money | null }[]
  waitlist: string[]
  subtotal: Money | null
  tax: { code: string; rate_pct: string | null; pending_validation: boolean; amount: Money | null }
  total: Money | null
  notices: { code: string; message: string }[]
}

export const PRODUCT_SLUGS = ['personas', 'tiempo', 'planilla', 'talento', 'formacion'] as const

export function apiBase(): string {
  return (process.env.SITE_API_URL ?? 'http://localhost:8000/api').replace(/\/$/, '')
}

/** Lee el catálogo en el servidor (se refresca cada minuto). Si la API no responde, el sitio sigue en pie sin precios. */
export async function fetchCatalog(): Promise<Catalog | null> {
  try {
    const res = await fetch(`${apiBase()}/v1/public/catalog`, { next: { revalidate: 60 }, headers: { Accept: 'application/json' } })
    if (!res.ok) return null
    const body = (await res.json()) as { data: Catalog }
    return body.data
  } catch {
    return null
  }
}

export function productBySlug(catalog: Catalog | null, slug: string): CatalogProduct | null {
  return catalog?.products.find((p) => p.slug === slug) ?? null
}

/** Planes que incluyen algún módulo del producto. */
export function plansIncluding(catalog: Catalog | null, product: CatalogProduct): CatalogPlan[] {
  if (!catalog) return []
  const codes = new Set(product.modules.map((m) => m.code))
  return catalog.plans.filter((plan) => plan.modules.some((m) => codes.has(m)))
}

/** Complemento con el que se compra el producto por separado (si se vende solo). */
export function addonForProduct(catalog: Catalog | null, product: CatalogProduct): CatalogAddon | null {
  if (!catalog || !product.sold_standalone) return null
  return catalog.addons.find((a) => a.web_product_code === product.code) ?? null
}

export function employeeLimit(plan: CatalogPlan): { unlimited: boolean; value: string | null } | null {
  return plan.limits.find((l) => l.code === 'employees') ?? null
}

/**
 * Buscador de plan (WEB-4): el primero, en el orden del catálogo, cuyo límite admite a los colaboradores y que trae
 * los módulos pedidos. No calcula montos: solo compara cantidades y listas.
 */
export function suggestPlan(catalog: Catalog | null, employees: number, needs: string[]): CatalogPlan | null {
  if (!catalog) return null
  return (
    catalog.plans.find((plan) => {
      const limit = employeeLimit(plan)
      const fits = !limit || limit.unlimited || limit.value === null || employees <= Number(limit.value)
      return fits && needs.every((m) => plan.modules.includes(m))
    }) ?? null
  )
}

export const PRODUCT_COLOR: Record<string, string> = {
  HRIS: 'var(--hris)',
  WFM: 'var(--wfm)',
  PAY: 'var(--pay)',
  TMS: 'var(--tms)',
  LMS: 'var(--lms)',
  HCM: 'var(--navy)',
}
