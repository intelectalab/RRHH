import type { Metadata } from 'next'
import { SiteShell } from '@/components/Shell'
import { SignupFlow } from '@/components/SignupFlow'
import { fetchCatalog } from '@/lib/catalog'
import { content, isLocale, type Locale } from '@/lib/i18n'

/**
 * WEB-5 · Registro de prueba en tres pasos. Recibe la elección hecha en /precios (plan, ciclo, complementos y lo que
 * quedó en «Próximamente») y no se indexa.
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
  const plan = catalog?.plans.find((p) => p.code === query.plan) ?? null
  const codes = (query.complementos ?? '').split(',').filter(Boolean)
  const addons = codes.flatMap((c) => {
    const addon = catalog?.addons.find((a) => a.code === c)
    return addon ? [{ code: addon.code, name: addon.name }] : []
  })
  const modules = new Set((catalog?.modules ?? []).filter((m) => !m.published).map((m) => m.code))
  const waitlist = (query.aviso ?? '').split(',').filter((c) => modules.has(c))
  const cycle = query.ciclo === 'annual' ? 'annual' : plan?.billing_cycle ?? 'monthly'

  return (
    <SiteShell lang={lang} catalog={catalog} path="/registro">
      <div className="phero">
        <div className="wrap">
          <h1>{t.signup.title}</h1>
          <p className="lead">{t.signup.text}</p>
        </div>
      </div>
      <section className="sec-sm">
        <div className="wrap narrow">
          <SignupFlow lang={lang} t={t} choice={{ plan: plan?.code ?? null, planName: plan?.name ?? null, cycle, addons, waitlist }} />
        </div>
      </section>
    </SiteShell>
  )
}
