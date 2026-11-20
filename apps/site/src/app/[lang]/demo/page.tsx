import type { Metadata } from 'next'
import { SiteShell } from '@/components/Shell'
import { DemoBooking } from '@/components/Forms'
import { fetchCatalog } from '@/lib/catalog'
import { content, isLocale, type Locale } from '@/lib/i18n'
import { pageMetadata } from '@/lib/seo'

/** WEB-6 · Agenda de demo con los horarios que ofrece la plataforma. */
export const dynamic = 'force-dynamic'

export async function generateMetadata({ params }: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const { lang } = await params
  const t = content(lang)
  return pageMetadata(lang, '/demo', t.meta.demo.title, t.meta.demo.description)
}

export default async function Page({ params, searchParams }: { params: Promise<{ lang: string }>; searchParams: Promise<Record<string, string | undefined>> }) {
  const { lang: raw } = await params
  const lang: Locale = isLocale(raw) ? raw : 'es'
  const t = content(lang)
  const catalog = await fetchCatalog()
  const query = await searchParams
  const product = catalog?.products.find((p) => p.code === query.producto)?.code

  return (
    <SiteShell lang={lang} catalog={catalog} path="/demo">
      <div className="phero">
        <div className="wrap">
          <h1>{t.demo.title}</h1>
          <p className="lead">{t.demo.text}</p>
        </div>
      </div>
      <section className="sec-sm">
        <div className="wrap narrow">
          <DemoBooking lang={lang} t={t} productCode={product} />
        </div>
      </section>
    </SiteShell>
  )
}
