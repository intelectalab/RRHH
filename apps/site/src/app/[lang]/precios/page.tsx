import type { Metadata } from 'next'
import { CatalogNotice, Faq } from '@/components/Blocks'
import { PricingClient } from '@/components/PricingClient'
import { SiteShell } from '@/components/Shell'
import { fetchCatalog } from '@/lib/catalog'
import { content, isLocale, type Locale } from '@/lib/i18n'
import { pageMetadata } from '@/lib/seo'

export const revalidate = 60

export async function generateMetadata({ params }: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const { lang } = await params
  const t = content(lang)
  return pageMetadata(lang, '/precios', t.meta.pricing.title, t.meta.pricing.description)
}

/** WEB-4 · Precios con ciclo, productos individuales, buscador, comparador y selector de productos. */
export default async function PricingPage({ params }: { params: Promise<{ lang: string }> }) {
  const { lang: raw } = await params
  const lang: Locale = isLocale(raw) ? raw : 'es'
  const t = content(lang)
  const catalog = await fetchCatalog()
  const products = (catalog?.products ?? []).filter((p) => !p.is_suite)

  return (
    <SiteShell lang={lang} catalog={catalog} path="/precios">
      <div className="phero">
        <div className="wrap">
          <div className="kick">{t.pricing.kicker}</div>
          <h1>{t.pricing.title}</h1>
          <p className="lead">{t.pricing.lead}</p>
        </div>
      </div>
      <CatalogNotice lang={lang} catalog={catalog} />
      {catalog && <PricingClient lang={lang} catalog={catalog} t={t} />}

      {catalog && catalog.plans.length > 0 && (
        <section className="sec-sm" id="comparar">
          <div className="wrap">
            <h2 className="h2">{t.pricing.compare_title}</h2>
            <div className="tablewrap">
              <table className="data">
                <thead>
                  <tr>
                    <th scope="col">{t.pricing.compare_feature}</th>
                    {catalog.plans.map((plan) => <th key={plan.code} scope="col" className="c">{plan.name}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {products.map((product) => [
                    <tr key={product.code} className="grp"><td colSpan={catalog.plans.length + 1}>{product.name} {' · '} {product.acronym}</td></tr>,
                    ...product.modules.map((m) => (
                      <tr key={`${product.code}-${m.code}`}>
                        <th scope="row">{m.name}</th>
                        {catalog.plans.map((plan) => {
                          const included = plan.modules.includes(m.code)
                          return (
                            <td key={plan.code} className="c">
                              {!included ? (
                                <span className="no-c" aria-label={t.pricing.not_included}>{'—'}</span>
                              ) : m.published ? (
                                <span className="yes" aria-label={t.pricing.included}>{'✓'}</span>
                              ) : (
                                <span className="soon">{t.status.coming_soon}</span>
                              )}
                            </td>
                          )
                        })}
                      </tr>
                    )),
                  ])}
                </tbody>
              </table>
            </div>
          </div>
        </section>
      )}

      <Faq title={t.pricing.faq_title} items={t.pricing.faq} />
    </SiteShell>
  )
}
