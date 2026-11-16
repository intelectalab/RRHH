'use client'

import { usePathname } from 'next/navigation'
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { HelpOpenerContext } from '@/ui/helpContext'
import { Icon } from '@/ui/components/Icon'
import { Notice } from '@/ui/components/Notice'
import { SidePanel } from '@/ui/components/SidePanel'
import { Tabs } from '@/ui/components/Tabs'
import { helpBlocks, loadScreenHelp, type HelpArticle, type ScreenHelp } from './help'
import { helpScreenFor } from './screens'

type HelpTab = 'screen' | 'guides'

type HelpState = { available: boolean; screenKey: string | null; open: (tab?: HelpTab) => void }

const HelpContext = createContext<HelpState>({ available: false, screenKey: null, open: () => undefined })

export const useHelp = () => useContext(HelpContext)

/**
 * AYU-2 · AYU-3 · Centro de ayuda en todas las pantallas de la aplicación y del portal: se abre en «Esta
 * pantalla» con sus artículos; «Guías» reúne las guías por rol y módulo. Solo muestra lo que la API devuelve
 * para el usuario (RN-AYU-01) y funciona aunque el asistente de IA esté apagado.
 */
export function HelpProvider({ children }: { children: ReactNode }) {
  const { t } = useT()
  const { session, can } = useSession()
  const pathname = usePathname()
  const screenKey = helpScreenFor(pathname)
  const available = session.modules.includes('core') && can('help.articles.view')
  const [openTab, setOpenTab] = useState<HelpTab | null>(null)
  const open = useCallback((tab?: HelpTab) => setOpenTab(tab ?? (screenKey ? 'screen' : 'guides')), [screenKey])
  useEffect(() => setOpenTab(null), [pathname])

  const state = useMemo(() => ({ available, screenKey, open }), [available, screenKey, open])
  const opener = useMemo(() => (available ? { open: () => open(), label: t('help.empty_link') } : null), [available, open, t])

  return (
    <HelpContext.Provider value={state}>
      <HelpOpenerContext.Provider value={opener}>
        {children}
        {available && openTab && <HelpPanel screenKey={screenKey} initialTab={openTab} onClose={() => setOpenTab(null)} />}
      </HelpOpenerContext.Provider>
    </HelpContext.Provider>
  )
}

/** Botón de ayuda de la barra superior. */
export function HelpButton() {
  const { t } = useT()
  const { available, open } = useHelp()
  if (!available) return null
  return (
    <button type="button" className="ibtn" onClick={() => open()} aria-label={t('help.open')} title={t('help.open')}>
      <Icon name="help" />
    </button>
  )
}

function HelpPanel({ screenKey, initialTab, onClose }: { screenKey: string | null; initialTab: HelpTab; onClose: () => void }) {
  const { t, locale } = useT()
  const [tab, setTab] = useState<HelpTab>(initialTab)
  const [screen, setScreen] = useState<ScreenHelp | null | undefined>(screenKey ? undefined : null)
  const [guides, setGuides] = useState<HelpArticle[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [reading, setReading] = useState<HelpArticle | null>(null)
  const [q, setQ] = useState('')
  const [results, setResults] = useState<HelpArticle[] | null>(null)

  useEffect(() => {
    if (!screenKey) return
    let alive = true
    void loadScreenHelp(screenKey, locale).then((s) => { if (alive) setScreen(s) })
    return () => { alive = false }
  }, [screenKey, locale])

  useEffect(() => {
    if (tab !== 'guides' || guides) return
    api.get<{ data: HelpArticle[] }>('/help/articles?filter[kind]=guide').then((r) => setGuides(r.data)).catch(() => setFailed(true))
  }, [tab, guides])

  async function search(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const text = q.trim()
    if (text.length < 2) { setResults(null); return }
    setFailed(false)
    try {
      const params = new URLSearchParams({ q: text, ...(screenKey ? { screen_key: screenKey } : {}) })
      setResults((await api.get<{ data: HelpArticle[] }>(`/help/search?${params.toString()}`)).data)
    } catch {
      setFailed(true)
    }
  }

  const list = (items: HelpArticle[] | null | undefined, empty: string) => {
    if (items === undefined || (items === null && !failed)) return <span className="esq" style={{ width: 220 }} />
    if (!items || items.length === 0) return <p className="text-secondary">{empty}</p>
    return (
      <ul className="ayuda-lista">
        {items.map((a) => (
          <li key={a.id}>
            <button type="button" className="ayuda-item" onClick={() => setReading(a)}>
              <b>{a.title}</b>
              {a.summary && <span className="sub2">{a.summary}</span>}
            </button>
          </li>
        ))}
      </ul>
    )
  }

  return (
    <SidePanel title={t('help.title')} onClose={onClose} closeLabel={t('ui.close')} wide>
      {reading ? (
        <HelpArticleView article={reading} onBack={() => setReading(null)} />
      ) : (
        <>
          <form className="ayuda-buscar mb-3" role="search" onSubmit={search}>
            <label className="vh" htmlFor="ayuda-q">{t('help.search_label')}</label>
            <input id="ayuda-q" className="form-control" type="search" value={q} placeholder={t('help.search_placeholder')} onChange={(e) => { setQ(e.target.value); if (e.target.value === '') setResults(null) }} />
            <button type="submit" className="btn btn-sec">{t('help.search_button')}</button>
          </form>
          {failed && <Notice tone="error">{t('help.error')}</Notice>}
          {results !== null ? (
            <section aria-label={t('help.results')}>
              <h3 className="panel-sec">{t('help.results')}</h3>
              {list(results, t('help.no_results'))}
            </section>
          ) : (
            <Tabs
              label={t('help.title')}
              value={tab}
              onChange={(id) => setTab(id as HelpTab)}
              items={[
                { id: 'screen', label: t('help.tab_screen'), disabled: !screenKey, content: list(screen?.articles ?? (screen === null ? [] : undefined), t('help.empty_screen')) },
                { id: 'guides', label: t('help.tab_guides'), content: list(guides, t('help.empty_guides')) },
              ]}
            />
          )}
        </>
      )}
    </SidePanel>
  )
}

function HelpArticleView({ article, onBack }: { article: HelpArticle; onBack: () => void }) {
  const { t, locale } = useT()
  return (
    <article className="ayuda-articulo" lang={article.locale}>
      <button type="button" className="btn btn-sec btn-sm mb-3" onClick={onBack}><Icon name="chevronLeft" /> {t('help.back')}</button>
      <h3>{article.title}</h3>
      {article.translation_missing && <Notice tone="info">{t('help.translation_missing')}</Notice>}
      <HelpBody body={article.body} />
      <p className="small text-secondary">{t('help.version', { version: article.version_number, product: article.product_version, date: formatDate(locale, article.published_at) })}</p>
    </article>
  )
}

function HelpBody({ body }: { body: string }) {
  return (
    <>
      {helpBlocks(body).map((b, i) => (b.kind === 'ul'
        ? <ul key={i}>{b.items.map((item, j) => <li key={j}>{item}</li>)}</ul>
        : <p key={i}>{b.text}</p>))}
    </>
  )
}

/**
 * AYU-3 · Ayuda breve junto a un campo. El texto sale del mismo contenido versionado de la ayuda (artículo
 * «campo.<pantalla>.<campo>»), no de textos sueltos en el código; sin artículo no se muestra nada.
 */
export function FieldHelp({ field }: { field: string }) {
  const { t, locale } = useT()
  const { available, screenKey } = useHelp()
  const [article, setArticle] = useState<HelpArticle | null>(null)
  const [shown, setShown] = useState(false)

  useEffect(() => {
    if (!available || !screenKey) return
    let alive = true
    void loadScreenHelp(screenKey, locale).then((s) => { if (alive) setArticle(s?.fields[field] ?? null) })
    return () => { alive = false }
  }, [available, screenKey, locale, field])

  if (!article) return null
  return (
    <span className="ayuda-campo">
      <button type="button" className="ayuda-campo-btn" aria-expanded={shown} aria-label={t('help.field', { title: article.title })} onClick={() => setShown((v) => !v)}>
        <Icon name="help" size={16} />
      </button>
      {shown && (
        <span className="ayuda-campo-texto" role="note">
          {helpBlocks(article.body).map((b, i) => <span key={i}>{b.kind === 'p' ? b.text : b.items.join(' · ')}</span>)}
        </span>
      )}
    </span>
  )
}
