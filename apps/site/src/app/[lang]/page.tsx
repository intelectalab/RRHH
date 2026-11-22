import Link from 'next/link'
import type { Metadata } from 'next'
import { CatalogNotice, Faq, PlanCard } from '@/components/Blocks'
import { SiteShell } from '@/components/Shell'
import { fetchCatalog } from '@/lib/catalog'
import { content, href, isLocale, type Locale } from '@/lib/i18n'
import { pageMetadata } from '@/lib/seo'
import '../home.css'

export const revalidate = 60

export async function generateMetadata({ params }: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const { lang } = await params
  const t = content(lang)
  return pageMetadata(lang, '/', t.meta.home.title, t.meta.home.description)
}

/**
 * Portada del sitio comercial. La pieza central es una boleta de pago de ejemplo (el documento que todo empleador
 * peruano reconoce); los montos son ilustrativos y van sin símbolo de moneda (RN-WEB-01: el sitio no escribe precios).
 * Solo se ofrecen los productos que ya están a la venta según el catálogo de la consola.
 */
export default async function Home({ params }: { params: Promise<{ lang: string }> }) {
  const { lang: raw } = await params
  const lang: Locale = isLocale(raw) ? raw : 'es'
  const t = content(lang)
  const h = t.home
  const s = h.slip
  const catalog = await fetchCatalog()
  const onSale = new Set((catalog?.products ?? []).filter((p) => !p.is_suite && p.status !== 'coming_soon').map((p) => p.slug))
  const modules = catalog ? h.modules.filter((m) => onSale.has(m.slug)) : h.modules

  return (
    <SiteShell lang={lang} catalog={catalog} path="/">
      <section className="hx">
        <div className="wrap hx-grid">
          <div className="hx-txt">
            <h1>{h.title}</h1>
            <p className="hx-sub">{h.subtitle}</p>
            <div className="hx-ctas">
              <Link className="btn btn-primary btn-lg" href={href(lang, '/demo')}>{h.cta_demo}</Link>
              <Link className="btn btn-line btn-lg" href={href(lang, '/precios')}>{h.cta_pricing}</Link>
            </div>
            <p className="hx-fine">{h.fine}</p>
          </div>

          <figure className="slip" aria-label={s.example}>
            <figcaption className="slip-tag">{s.example}</figcaption>
            <div className="slip-head">
              <div>
                <b>{s.company}</b>
                <span>{s.doc}</span>
              </div>
              <span className="slip-period">{s.period}</span>
            </div>
            <div className="slip-who">
              <b>{s.worker}</b>
              <span>{s.position}</span>
            </div>
            <table className="slip-t">
              <caption>{s.currency}</caption>
              <tbody>
                <tr className="grp"><th colSpan={2}>{s.income}</th></tr>
                <tr><td>{s.basic}</td><td>{s.amounts.basic}</td></tr>
                <tr><td>{s.overtime}</td><td>{s.amounts.overtime}</td></tr>
                <tr className="sub"><td>{s.total_income}</td><td>{s.amounts.total_income}</td></tr>
                <tr className="grp"><th colSpan={2}>{s.deductions}</th></tr>
                <tr><td>{s.pension}</td><td>{s.amounts.pension}</td></tr>
                <tr className="net"><td>{s.net}</td><td>{s.amounts.net}</td></tr>
                <tr className="grp"><th colSpan={2}>{s.employer}</th></tr>
                <tr><td>{s.health}</td><td>{s.amounts.health}</td></tr>
              </tbody>
            </table>
            <p className="slip-sign"><span aria-hidden="true" className="tick" />{s.signed}</p>
          </figure>
        </div>
      </section>

      <section className="hm" id="como-funciona">
        <div className="wrap">
          <h2 className="hs-h">{h.month_title}</h2>
          <p className="hs-lead">{h.month_lead}</p>
          <ol className="hsteps">
            {h.steps.map((step, i) => (
              <li key={step.t}>
                <span className="n" aria-hidden="true">{i + 1}</span>
                <h3>{step.t}</h3>
                <p>{step.d}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="hmod" id="incluye">
        <div className="wrap">
          <h2 className="hs-h">{h.modules_title}</h2>
          <p className="hs-lead">{h.modules_lead}</p>
          <div className="mods">
            {modules.map((m) => (
              <article key={m.slug} className="mod">
                <h3>{m.name}</h3>
                <p>{m.d}</p>
                <ul>
                  {m.points.map((pt) => <li key={pt}>{pt}</li>)}
                </ul>
                <Link className="mod-more" href={href(lang, `/${m.slug}`)}>{h.see_more}</Link>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section className="hpor">
        <div className="wrap hpor-grid">
          <div>
            <h2 className="hs-h">{h.portal_title}</h2>
            <p className="hs-lead">{h.portal_text}</p>
          </div>
          <div>
            <h2 className="hs-h hs-h-sm">{h.trust_title}</h2>
            <dl className="hguard">
              {h.trust.map((item) => (
                <div key={item.t}>
                  <dt>{item.t}</dt>
                  <dd>{item.d}</dd>
                </div>
              ))}
            </dl>
          </div>
        </div>
      </section>

      <section className="hplans" id="planes">
        <div className="wrap">
          <div className="hplans-head">
            <div>
              <h2 className="hs-h">{h.plans_title}</h2>
              <p className="hs-lead">{h.plans_lead}</p>
            </div>
            <Link className="btn btn-line" href={href(lang, '/precios')}>{h.compare_all}</Link>
          </div>
          <CatalogNotice lang={lang} catalog={catalog} />
          {(catalog?.plans.length ?? 0) > 0 && (
            <>
              <div className="plans">
                {(catalog?.plans ?? []).map((plan) => <PlanCard key={plan.code} lang={lang} catalog={catalog} plan={plan} />)}
              </div>
              <p className="note">{t.price.tax_note}</p>
            </>
          )}
        </div>
      </section>

      <Faq title={h.faq_title} items={h.faq} />

      <section className="hclose">
        <div className="wrap hclose-in">
          <div>
            <h2>{h.closing_title}</h2>
            <p>{h.closing_text}</p>
          </div>
          <Link className="btn btn-white btn-lg" href={href(lang, '/demo')}>{h.cta_demo}</Link>
        </div>
      </section>
    </SiteShell>
  )
}
