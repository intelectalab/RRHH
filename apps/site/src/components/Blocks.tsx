import Link from 'next/link'
import { PRODUCT_COLOR, addonForProduct, employeeLimit, plansIncluding, type Catalog, type CatalogPlan, type CatalogProduct } from '@/lib/catalog'
import { content, fill, href, type Locale } from '@/lib/i18n'
import { formatMoney } from '@/lib/money'

type ProductKey = keyof ReturnType<typeof content>['products']

export function productText(lang: Locale, slug: string) {
  const products = content(lang).products
  return slug in products ? products[slug as ProductKey] : null
}

export function StatusBadge({ lang, status }: { lang: Locale; status: CatalogProduct['status'] }) {
  const t = content(lang)
  return <span className={status === 'coming_soon' ? 'soon' : 'ok'}>{t.status[status]}</span>
}

/** Cuadro de producto del inicio y de la Suite: abre su página en la misma ventana (WEB-3). */
export function ProductCard({ lang, catalog, product }: { lang: Locale; catalog: Catalog | null; product: CatalogProduct }) {
  const t = content(lang)
  const text = productText(lang, product.slug)
  const plans = plansIncluding(catalog, product)
  const addon = addonForProduct(catalog, product)
  return (
    <article className="pcard" style={{ ['--c' as string]: PRODUCT_COLOR[product.acronym] }}>
      <Link className="pc-link" href={href(lang, `/${product.slug}`)} aria-label={product.name} />
      <div className="pc-top"><StatusBadge lang={lang} status={product.status} /></div>
      <h3>{product.name} <span className="sig">{product.acronym}</span></h3>
      {text && <p className="pc-b">{text.headline}</p>}
      {text && <p className="pc-d">{text.description}</p>}
      <div className="pc-price">
        <small>{t.price.label}</small>
        {addon?.price ? (
          <b>{formatMoney(addon.price, lang)} <span>{t.price.per_employee}</span></b>
        ) : (
          <b>{product.sold_standalone ? t.price.on_request : t.price.not_standalone}</b>
        )}
      </div>
      {plans.length > 0 && (
        <div className="pc-plans">
          <small>{t.price.included_in}</small>
          {plans.map((p) => <span key={p.code} className="pchip on">{p.name}</span>)}
        </div>
      )}
      <div className="pc-acts">
        <Link className="btn btn-primary" href={href(lang, `/${product.slug}`)}>{content(lang).home.see_more}</Link>
      </div>
    </article>
  )
}

export function planPrice(lang: Locale, plan: CatalogPlan, cycle?: string): string {
  const t = content(lang)
  if (!plan.price || (cycle !== undefined && cycle !== plan.billing_cycle)) return t.price.on_request
  return formatMoney(plan.price, lang)
}

/** Tarjeta de plan: precio y límite salen del catálogo; sin precio cargado se muestra «Consultar». */
export function PlanCard({ lang, catalog, plan }: { lang: Locale; catalog: Catalog | null; plan: CatalogPlan }) {
  const t = content(lang)
  const limit = employeeLimit(plan)
  const products = (catalog?.products ?? []).filter((p) => !p.is_suite && p.modules.some((m) => plan.modules.includes(m.code)))
  return (
    <div className="plan" id={`plan-${plan.code}`}>
      <h3>{plan.name}</h3>
      {plan.description && <div className="for">{plan.description}</div>}
      <div className="price">
        {planPrice(lang, plan)} {plan.price && <small>{plan.billing_cycle === 'annual' ? t.price.per_year : t.price.per_month}</small>}
      </div>
      {limit && (
        <div className="lim">{limit.unlimited || limit.value === null ? t.pricing.limit_unlimited : fill(t.pricing.limit_up_to, { n: limit.value })}</div>
      )}
      <div className="chips-s">
        {products.map((p) => (
          <Link key={p.code} className="sigchip" href={href(lang, `/${p.slug}`)} style={{ ['--c' as string]: PRODUCT_COLOR[p.acronym] }}>{p.acronym}</Link>
        ))}
      </div>
      <Link className="btn btn-line" href={`${href(lang, '/precios')}?plan=${encodeURIComponent(plan.code)}#armar`}>{fill(t.pricing.choose_plan, { plan: plan.name })}</Link>
    </div>
  )
}

export function Faq({ title, items }: { title: string; items: { q: string; a: string }[] }) {
  if (items.length === 0) return null
  return (
    <section className="sec-sm" id="preguntas">
      <div className="wrap faq-wrap">
        <h2 className="h2">{title}</h2>
        <div>
          {items.map((item) => (
            <details key={item.q} className="faq">
              <summary>{item.q}</summary>
              <p>{item.a}</p>
            </details>
          ))}
        </div>
      </div>
    </section>
  )
}

export function CatalogNotice({ lang, catalog }: { lang: Locale; catalog: Catalog | null }) {
  if (catalog) return null
  return <p className="note" role="status">{content(lang).price.catalog_unavailable}</p>
}
