import type { Metadata } from 'next'
import { SiteShell } from '@/components/Shell'
import { DemoManage } from '@/components/Forms'
import { fetchCatalog } from '@/lib/catalog'
import { content, isLocale, type Locale } from '@/lib/i18n'
import { pageMetadata } from '@/lib/seo'

/** WEB-6 · Cambiar o cancelar una demo con el enlace del correo. No se indexa. */
export const dynamic = 'force-dynamic'

export async function generateMetadata({ params }: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const { lang } = await params
  const t = content(lang)
  return { ...pageMetadata(lang, '/demo/gestionar', t.meta.demo.title, t.meta.demo.description), robots: { index: false, follow: false } }
}

export default async function Page({ params, searchParams }: { params: Promise<{ lang: string }>; searchParams: Promise<Record<string, string | undefined>> }) {
  const { lang: raw } = await params
  const lang: Locale = isLocale(raw) ? raw : 'es'
  const t = content(lang)
  const catalog = await fetchCatalog()
  const query = await searchParams
  const token = (query.t ?? '').slice(0, 200)

  return (
    <SiteShell lang={lang} catalog={catalog} path="/demo/gestionar">
      <div className="phero">
        <div className="wrap">
          <h1>{t.demo.manage_title}</h1>
          <p className="lead">{t.demo.text}</p>
        </div>
      </div>
      <section className="sec-sm">
        <div className="wrap narrow">
          <DemoManage lang={lang} t={t} token={token} />
        </div>
      </section>
    </SiteShell>
  )
}
