import type { Metadata } from 'next'
import { SiteShell } from '@/components/Shell'
import { LoginForm } from '@/components/Forms'
import { fetchCatalog } from '@/lib/catalog'
import { content, isLocale, type Locale } from '@/lib/i18n'
import { pageMetadata } from '@/lib/seo'

/** WEB-7 · «Ingresar»: lleva a la pantalla de ingreso de la organización por su dirección. */
export const dynamic = 'force-dynamic'

export async function generateMetadata({ params }: { params: Promise<{ lang: string }> }): Promise<Metadata> {
  const { lang } = await params
  const t = content(lang)
  return pageMetadata(lang, '/ingresar', t.meta.login.title, t.meta.login.description)
}

export default async function Page({ params, searchParams }: { params: Promise<{ lang: string }>; searchParams: Promise<Record<string, string | undefined>> }) {
  const { lang: raw } = await params
  const lang: Locale = isLocale(raw) ? raw : 'es'
  const t = content(lang)
  const catalog = await fetchCatalog()
  const appUrl = process.env.SITE_APP_URL ?? 'http://{slug}.localhost:3000'

  return (
    <SiteShell lang={lang} catalog={catalog} path="/ingresar">
      <div className="phero">
        <div className="wrap">
          <h1>{t.login.title}</h1>
          <p className="lead">{t.login.text}</p>
        </div>
      </div>
      <section className="sec-sm">
        <div className="wrap narrow">
          <LoginForm lang={lang} t={t} appUrl={appUrl} />
        </div>
      </section>
    </SiteShell>
  )
}
