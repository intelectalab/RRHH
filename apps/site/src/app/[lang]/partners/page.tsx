import type { Metadata } from 'next'
import { SiteShell } from '@/components/Shell'
import { LeadForm } from '@/components/Forms'
import { fetchCatalog } from '@/lib/catalog'
import { content, isLocale, type Locale } from '@/lib/i18n'
import { pageMetadata } from '@/lib/seo'

/** WEB-6 · Programa de partners: solicitud como lead de tipo partner. */
export const revalidate = 60

export async function generateMetadata({ params }: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const { lang } = await params
  const t = content(lang)
  return pageMetadata(lang, '/partners', t.meta.partners.title, t.meta.partners.description)
}

export default async function Page({ params }: { params: Promise<{ lang: string }> }) {
  const { lang: raw } = await params
  const lang: Locale = isLocale(raw) ? raw : 'es'
  const t = content(lang)
  const catalog = await fetchCatalog()

  return (
    <SiteShell lang={lang} catalog={catalog} path="/partners">
      <div className="phero">
        <div className="wrap">
          <h1>{t.partners.title}</h1>
          <p className="lead">{t.partners.text}</p>
        </div>
      </div>
      <section className="sec-sm">
        <div className="wrap narrow">
          <LeadForm lang={lang} t={t} kind="partner" />
        </div>
      </section>
    </SiteShell>
  )
}
