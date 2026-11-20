import Link from 'next/link'
import type { Metadata } from 'next'
import { CatalogNotice, Faq, PlanCard, ProductCard, productText } from '@/components/Blocks'
import { SiteShell } from '@/components/Shell'
import { PRODUCT_COLOR, fetchCatalog } from '@/lib/catalog'
import { content, href, isLocale, type Locale } from '@/lib/i18n'
import { pageMetadata } from '@/lib/seo'

export const revalidate = 60

export async function generateMetadata({ params }: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const { lang } = await params
  const t = content(lang)
  return pageMetadata(lang, '/', t.meta.home.title, t.meta.home.description)
}

export default async function Home({ params }: { params: Promise<{ lang: string }> }) {
  const { lang: raw } = await params
  const lang: Locale = isLocale(raw) ? raw : 'es'
  const t = content(lang)
  const catalog = await fetchCatalog()
  const products = (catalog?.products ?? []).filter((p) => !p.is_suite)
  const suite = catalog?.products.find((p) => p.is_suite)

  return (
    <SiteShell lang={lang} catalog={catalog} path="/">
      <div className="hero hero2">
        <div className="wrap">
          <div className="txt">
            <div className="kick">{t.home.kicker}</div>
            <h1>{t.home.title}</h1>
            <p className="sub">{t.home.subtitle}</p>
            <div className="ctas">
              <Link className="btn btn-primary" href={href(lang, '/precios')}>{t.home.cta_primary}</Link>
              <Link className="btn btn-line" href={href(lang, '/suite')}>{t.home.cta_secondary}</Link>
            </div>
            <p className="fine">{t.home.fine}</p>
          </div>
          <nav className="needs" aria-label={t.home.needs_title}>
            <div className="needs-h">{t.home.needs_title}</div>
            {products.map((p) => (
              <Link key={p.code} className="need" href={href(lang, `/${p.slug}`)} style={{ ['--c' as string]: PRODUCT_COLOR[p.acronym] }}>
                <span className="sig">{p.acronym}</span>
                <span><b>{productText(lang, p.slug)?.need ?? p.name}</b><small>{p.name}{p.status === 'coming_soon' ? ` · ${t.status.coming_soon}` : ''}</small></span>
              </Link>
            ))}
            {suite && (
              <Link className="need all" href={href(lang, '/suite')}>
                <span className="sig">{suite.acronym}</span>
                <span><b>{suite.name}</b><small>{t.suite.kicker}</small></span>
              </Link>
            )}
          </nav>
        </div>
      </div>

      <section id="productos" className="sec-sm">
        <div className="wrap">
          <div className="sec-head">
            <div>
              <div className="kick">{t.home.products_kicker}</div>
              <h2 className="h2">{t.home.products_title}</h2>
              <p className="lead">{t.home.products_lead}</p>
            </div>
            <Link className="btn btn-line" href={href(lang, '/precios')}>{t.home.compare_all}</Link>
          </div>
          <CatalogNotice lang={lang} catalog={catalog} />
          <div className="pgrid">
            {products.map((p) => <ProductCard key={p.code} lang={lang} catalog={catalog} product={p} />)}
          </div>
        </div>
      </section>

      <section id="planes" className="alt sec-sm">
        <div className="wrap">
          <div className="kick">{t.home.plans_kicker}</div>
          <h2 className="h2">{t.home.plans_title}</h2>
          <p className="lead">{t.home.plans_lead}</p>
          {catalog && catalog.plans.length === 0 && <p className="note">{t.pricing.no_plans}</p>}
          <div className="plans">
            {(catalog?.plans ?? []).map((plan) => <PlanCard key={plan.code} lang={lang} catalog={catalog} plan={plan} />)}
          </div>
          <p className="note">{t.price.tax_note}</p>
        </div>
      </section>

      <Faq title={t.home.faq_title} items={t.home.faq} />

      <section className="sec-sm">
        <div className="wrap">
          <h2 className="h2">{t.home.closing_title}</h2>
          <p className="lead">{t.home.closing_text}</p>
          <div className="ctas"><Link className="btn btn-primary" href={href(lang, '/precios')}>{t.home.cta_primary}</Link></div>
        </div>
      </section>
    </SiteShell>
  )
}
