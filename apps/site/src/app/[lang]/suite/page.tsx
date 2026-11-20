import type { Metadata } from 'next'
import { CatalogNotice, PlanCard, ProductCard, StatusBadge } from '@/components/Blocks'
import { SiteShell } from '@/components/Shell'
import { fetchCatalog } from '@/lib/catalog'
import { content, isLocale, type Locale } from '@/lib/i18n'
import { pageMetadata } from '@/lib/seo'

export const revalidate = 60

export async function generateMetadata({ params }: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const { lang } = await params
  const t = content(lang)
  return pageMetadata(lang, '/suite', t.meta.suite.title, t.meta.suite.description)
}

/** WEB-3 · Suite: mapa de productos con su estado y los planes completos. */
export default async function SuitePage({ params }: { params: Promise<{ lang: string }> }) {
  const { lang: raw } = await params
  const lang: Locale = isLocale(raw) ? raw : 'es'
  const t = content(lang)
  const catalog = await fetchCatalog()
  const products = (catalog?.products ?? []).filter((p) => !p.is_suite)
  const suite = catalog?.products.find((p) => p.is_suite)

  return (
    <SiteShell lang={lang} catalog={catalog} path="/suite">
      <div className="phero">
        <div className="wrap">
          <div className="kick">{t.suite.kicker}</div>
          <h1>{t.suite.title}</h1>
          <p className="lead">{t.suite.lead}</p>
        </div>
      </div>
      <CatalogNotice lang={lang} catalog={catalog} />
      <section className="sec-sm">
        <div className="wrap">
          <h2 className="h2">{t.suite.map_title}</h2>
          <div className="pgrid">{products.map((p) => <ProductCard key={p.code} lang={lang} catalog={catalog} product={p} />)}</div>
        </div>
      </section>
      {suite && (
        <section className="alt sec-sm">
          <div className="wrap">
            <h2 className="h2">{t.suite.extras_title}</h2>
            <p className="lead">{t.suite.extras_text}</p>
            <ul className="pts">
              {suite.modules.map((m) => (
                <li key={m.code}>{m.name} {' · '} <StatusBadge lang={lang} status={m.published ? 'available' : 'coming_soon'} /></li>
              ))}
            </ul>
          </div>
        </section>
      )}
      <section className="sec-sm" id="planes">
        <div className="wrap">
          <h2 className="h2">{t.suite.plans_title}</h2>
          {catalog && catalog.plans.length === 0 && <p className="note">{t.pricing.no_plans}</p>}
          <div className="plans">{(catalog?.plans ?? []).map((plan) => <PlanCard key={plan.code} lang={lang} catalog={catalog} plan={plan} />)}</div>
          <p className="note">{t.price.tax_note}</p>
        </div>
      </section>
    </SiteShell>
  )
}
