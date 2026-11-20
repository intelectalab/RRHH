import type { Metadata } from 'next'
import { SiteShell } from '@/components/Shell'
import { LeadForm } from '@/components/Forms'
import { fetchCatalog } from '@/lib/catalog'
import { content, isLocale, type Locale } from '@/lib/i18n'
import { pageMetadata } from '@/lib/seo'

/** WEB-6 · Contacto: el lead queda en la consola con su origen y consentimiento. */
export const revalidate = 60

export async function generateMetadata({ params }: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const { lang } = await params
  const t = content(lang)
  return pageMetadata(lang, '/contacto', t.meta.contact.title, t.meta.contact.description)
}

export default async function Page({ params }: { params: Promise<{ lang: string }> }) {
  const { lang: raw } = await params
  const lang: Locale = isLocale(raw) ? raw : 'es'
  const t = content(lang)
  const catalog = await fetchCatalog()

  return (
    <SiteShell lang={lang} catalog={catalog} path="/contacto">
      <div className="phero">
        <div className="wrap">
          <h1>{t.contact.title}</h1>
          <p className="lead">{t.contact.text}</p>
        </div>
      </div>
      <section className="sec-sm">
        <div className="wrap narrow">
          <LeadForm lang={lang} t={t} kind="contact" />
        </div>
      </section>
    </SiteShell>
  )
}
