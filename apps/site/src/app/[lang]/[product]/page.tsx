import Link from 'next/link'
import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { CatalogNotice, Faq, PlanCard, StatusBadge, productText } from '@/components/Blocks'
import { LeadForm } from '@/components/Forms'
import { SiteShell } from '@/components/Shell'
import { PRODUCT_COLOR, PRODUCT_SLUGS, addonForProduct, fetchCatalog, plansIncluding, productBySlug } from '@/lib/catalog'
import { LOCALES, content, href, isLocale, type Locale } from '@/lib/i18n'
import { formatMoney } from '@/lib/money'
import { pageMetadata } from '@/lib/seo'

export const revalidate = 60
export const dynamicParams = false

export function generateStaticParams() {
  return LOCALES.flatMap((lang) => PRODUCT_SLUGS.map((product) => ({ lang, product })))
}

type Params = Promise<{ lang: string; product: string }>

export async function generateMetadata({ params }: { params: Params }): Promise<Metadata> {
  const { lang, product } = await params
  const text = productText(isLocale(lang) ? lang : 'es', product)
  const catalog = await fetchCatalog()
  const name = productBySlug(catalog, product)?.name ?? text?.headline ?? product
  return pageMetadata(lang, `/${product}`, `${name} · ${text?.headline ?? ''}`, text?.description ?? '')
}

/** WEB-3 · Página de producto: qué resuelve, funciones, planes que lo incluyen y precio individual si se vende solo. */
export default async function ProductPage({ params }: { params: Params }) {
  const { lang: raw, product: slug } = await params
  const lang: Locale = isLocale(raw) ? raw : 'es'
  const t = content(lang)
  const text = productText(lang, slug)
  if (!text) notFound()
  const catalog = await fetchCatalog()
  const product = productBySlug(catalog, slug)
  const plans = product ? plansIncluding(catalog, product) : []
  const addon = product ? addonForProduct(catalog, product) : null
  const pending = product?.modules.filter((m) => !m.published) ?? []

  return (
    <SiteShell lang={lang} catalog={catalog} path={`/${slug}`}>
      <div className="phero" style={{ ['--c' as string]: product ? PRODUCT_COLOR[product.acronym] : undefined }}>
        <div className="wrap">
          {product && <div className="kick">{product.name} {' · '} {product.acronym}</div>}
          <h1>{text.headline}</h1>
          <p className="lead">{text.description}</p>
          {product && <StatusBadge lang={lang} status={product.status} />}
        </div>
      </div>
      <CatalogNotice lang={lang} catalog={catalog} />

      <section className="sec-sm">
        <div className="wrap two">
          <div>
            <h2 className="h2">{t.product_page.features_title}</h2>
            <ul className="pts">{text.features.map((f) => <li key={f}>{f}</li>)}</ul>
          </div>
          {product && (
            <div>
              <h2 className="h2">{t.product_page.parts_title}</h2>
              <ul className="pts">
                {product.modules.map((m) => (
                  <li key={m.code}>{m.name} {' · '} <span className={m.published ? 'ok' : 'soon'}>{m.published ? t.status.available : t.status.coming_soon}</span></li>
                ))}
              </ul>
            </div>
          )}
        </div>
      </section>

      {pending.length > 0 && (
        <section className="alt sec-sm" id="avisame">
          <div className="wrap">
            <h2 className="h2">{t.product_page.waitlist_title}</h2>
            <p className="lead">{t.product_page.waitlist_text}</p>
            <div className="narrow"><LeadForm lang={lang} t={t} kind="waitlist" modules={pending.map((m) => ({ code: m.code, name: m.name }))} /></div>
            <Link className="btn btn-line" href={`${href(lang, '/precios')}#armar`}>{t.product_page.see_pricing}</Link>
          </div>
        </section>
      )}

      <section className="sec-sm" id="planes">
        <div className="wrap">
          <h2 className="h2">{t.product_page.plans_title}</h2>
          {product && !product.sold_standalone && <p className="lead">{product.slug === 'personas' ? t.price.included_in_all : t.price.not_standalone}</p>}
          {plans.length === 0 && <p className="note">{t.product_page.plans_none}</p>}
          <div className="plans">{plans.map((plan) => <PlanCard key={plan.code} lang={lang} catalog={catalog} plan={plan} />)}</div>
          {product?.sold_standalone && product.status !== 'coming_soon' && (
            <div className="box">
              <h3>{t.product_page.standalone_title}</h3>
              <p className="price">
                {addon?.price ? formatMoney(addon.price, lang) : t.price.on_request} {addon?.price && <small>{t.pricing.per_employee_month}</small>}
              </p>
            </div>
          )}
          <p className="note">{t.price.tax_note}</p>
        </div>
      </section>

      <Faq title={t.product_page.faq_title} items={text.faq} />

      <section className="sec-sm">
        <div className="wrap">
          <Link className="btn btn-line" href={href(lang, '/suite')}>{t.product_page.back_suite}</Link>
        </div>
      </section>
    </SiteShell>
  )
}
