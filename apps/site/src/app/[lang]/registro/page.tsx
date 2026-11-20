import Link from 'next/link'
import type { Metadata } from 'next'
import { SiteShell } from '@/components/Shell'
import { fetchCatalog } from '@/lib/catalog'
import { content, href, isLocale, type Locale } from '@/lib/i18n'

/**
 * Destino del selector de precios (WEB-4). El registro en tres pasos es WEB-5: mientras tanto esta página recibe la
 * elección y no se indexa.
 */
export const dynamic = 'force-dynamic'

export async function generateMetadata({ params }: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const { lang } = await params
  const t = content(lang)
  return { title: t.meta.signup.title, description: t.meta.signup.description, robots: { index: false, follow: false } }
}

export default async function SignupPage({ params, searchParams }: { params: Promise<{ lang: string }>; searchParams: Promise<Record<string, string | undefined>> }) {
  const { lang: raw } = await params
  const query = await searchParams
  const lang: Locale = isLocale(raw) ? raw : 'es'
  const t = content(lang)
  const catalog = await fetchCatalog()
  const plan = catalog?.plans.find((p) => p.code === query.plan)
  const addons = (query.complementos ?? '').split(',').filter(Boolean).map((c) => catalog?.addons.find((a) => a.code === c)?.name ?? c)

  return (
    <SiteShell lang={lang} catalog={catalog} path="/registro">
      <div className="phero">
        <div className="wrap">
          <h1>{t.signup.title}</h1>
          <p className="lead">{t.signup.text}</p>
        </div>
      </div>
      <section className="sec-sm">
        <div className="wrap">
          {plan && (
            <div className="box">
              <h2 className="h2">{t.signup.summary}</h2>
              <ul className="pts">
                <li>{plan.name}</li>
                {addons.map((a) => <li key={a}>{a}</li>)}
              </ul>
            </div>
          )}
          <Link className="btn btn-line" href={href(lang, '/precios')}>{t.signup.back}</Link>
        </div>
      </section>
    </SiteShell>
  )
}
