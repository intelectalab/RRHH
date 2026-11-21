'use client'

import { usePathname } from 'next/navigation'
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { HelpOpenerContext } from '@/ui/helpContext'
import { Icon } from '@/ui/components/Icon'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { SidePanel } from '@/ui/components/SidePanel'
import { StatusBadge } from '@/ui/components/Status'
import { Tabs } from '@/ui/components/Tabs'
import { helpBlocks, loadScreenHelp, tourSteps, type HelpArticle, type HelpEscalation, type HelpTour, type ScreenHelp } from './help'
import { helpScreenFor } from './screens'
import { HelpAssistantTab } from '@/modules/ai/HelpAssistantTab'
import type { AssistantAvailability } from '@/modules/ai/ai'

type HelpTab = 'screen' | 'guides' | 'assistant' | 'queries'

type HelpState = { available: boolean; screenKey: string | null; open: (tab?: HelpTab) => void; startTour: () => void }

const HelpContext = createContext<HelpState>({ available: false, screenKey: null, open: () => undefined, startTour: () => undefined })

export const useHelp = () => useContext(HelpContext)

/**
 * AYU-2 · AYU-3 · Centro de ayuda en todas las pantallas de la aplicación y del portal: se abre en «Esta
 * pantalla» con sus artículos; «Guías» reúne las guías por rol y módulo, las políticas de la organización
 * (AYU-4) y las novedades (AYU-7); «Mis consultas» deriva a RRHH lo que la ayuda no resuelve (AYU-6). Solo
 * muestra lo que la API devuelve para el usuario (RN-AYU-01) y funciona aunque el asistente de IA esté apagado.
 * AYU-7 · En el primer ingreso se muestra el recorrido del rol; se puede saltar y volver a abrir desde «Guías».
 */
export function HelpProvider({ children }: { children: ReactNode }) {
  const { t } = useT()
  const { session, can } = useSession()
  const pathname = usePathname()
  const screenKey = helpScreenFor(pathname)
  const available = session.modules.includes('core') && can('help.articles.view')
  const [openTab, setOpenTab] = useState<HelpTab | null>(null)
  const [tours, setTours] = useState<HelpTour[] | null>(null)
  const [touring, setTouring] = useState<HelpTour | null>(null)
  const open = useCallback((tab?: HelpTab) => setOpenTab(tab ?? (screenKey ? 'screen' : 'guides')), [screenKey])
  useEffect(() => setOpenTab(null), [pathname])

  useEffect(() => {
    if (!available) return
    let alive = true
    api.get<{ data: HelpTour[] }>('/help/tours').then((r) => {
      if (!alive) return
      setTours(r.data)
      const first = r.data.find((tour) => tour.status === 'pending')
      if (first) setTouring(first)
    }).catch(() => undefined)
    return () => { alive = false }
  }, [available])

  const startTour = useCallback(() => {
    setOpenTab(null)
    if (tours && tours.length > 0) setTouring(tours[0])
  }, [tours])

  async function finishTour(tour: HelpTour, status: 'completed' | 'skipped') {
    setTouring(null)
    setTours((list) => list?.map((x) => (x.slug === tour.slug ? { ...x, status } : x)) ?? null)
    try { await api.post(`/help/tours/${encodeURIComponent(tour.slug)}/complete`, { status }) } catch { /* se vuelve a ofrecer en el próximo ingreso */ }
  }

  const state = useMemo(() => ({ available, screenKey, open, startTour }), [available, screenKey, open, startTour])
  const opener = useMemo(() => (available ? { open: () => open(), label: t('help.empty_link') } : null), [available, open, t])

  return (
    <HelpContext.Provider value={state}>
      <HelpOpenerContext.Provider value={opener}>
        {children}
        {available && openTab && <HelpPanel screenKey={screenKey} initialTab={openTab} hasTours={(tours?.length ?? 0) > 0} onClose={() => setOpenTab(null)} />}
        {available && touring && <TourDialog tour={touring} onDone={(status) => void finishTour(touring, status)} />}
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

function TourDialog({ tour, onDone }: { tour: HelpTour; onDone: (status: 'completed' | 'skipped') => void }) {
  const { t } = useT()
  const steps = tourSteps(tour.body)
  const [i, setI] = useState(0)
  const step = steps[i]
  const last = i >= steps.length - 1
  if (!step) return null
  return (
    <Modal
      title={tour.title}
      onClose={() => onDone('skipped')}
      footer={(
        <>
          <button type="button" className="btn btn-sec" onClick={() => onDone('skipped')}>{t('help.tour_skip')}</button>
          {i > 0 && <button type="button" className="btn btn-sec" onClick={() => setI(i - 1)}>{t('help.tour_prev')}</button>}
          <button type="button" className="btn btn-primary" onClick={() => (last ? onDone('completed') : setI(i + 1))}>{t(last ? 'help.tour_done' : 'help.tour_next')}</button>
        </>
      )}
    >
      <p className="small text-secondary">{t('help.tour_step', { n: i + 1, total: steps.length })}</p>
      {step.title && <h3 className="panel-sec">{step.title}</h3>}
      <p>{step.text}</p>
    </Modal>
  )
}

function HelpPanel({ screenKey, initialTab, hasTours, onClose }: { screenKey: string | null; initialTab: HelpTab; hasTours: boolean; onClose: () => void }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const { startTour } = useHelp()
  const [tab, setTab] = useState<HelpTab>(initialTab)
  const [screen, setScreen] = useState<ScreenHelp | null | undefined>(screenKey ? undefined : null)
  const [guides, setGuides] = useState<HelpArticle[] | null>(null)
  const [policies, setPolicies] = useState<HelpArticle[] | null>(null)
  const [news, setNews] = useState<HelpArticle[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [reading, setReading] = useState<HelpArticle | null>(null)
  const [consulted, setConsulted] = useState<string[]>([])
  const [q, setQ] = useState('')
  const [results, setResults] = useState<HelpArticle[] | null>(null)
  const canAsk = can('help.escalations.create') || can('help.escalations.view')
  const [assistant, setAssistant] = useState<AssistantAvailability | null>(null)

  // IAR-9 · IAR-10 · El asistente solo aparece si la API dice que está disponible para este usuario.
  useEffect(() => {
    if (!can('ai.help_assistant.use')) return
    let alive = true
    api.get<{ data: AssistantAvailability }>('/ai/help-assistant/availability').then((r) => { if (alive) setAssistant(r.data) }).catch(() => undefined)
    return () => { alive = false }
  }, [can])

  async function openArticle(id: string) {
    try { read((await api.get<{ data: HelpArticle }>(`/help/articles/${id}`)).data) } catch { setFailed(true) }
  }

  useEffect(() => {
    if (!screenKey) return
    let alive = true
    void loadScreenHelp(screenKey, locale).then((s) => { if (alive) setScreen(s) })
    return () => { alive = false }
  }, [screenKey, locale])

  useEffect(() => {
    if (tab !== 'guides' || guides) return
    Promise.all([
      api.get<{ data: HelpArticle[] }>('/help/articles?filter[kind]=guide'),
      api.get<{ data: HelpArticle[] }>('/help/articles?filter[kind]=policy'),
      api.get<{ data: HelpArticle[] }>('/help/whats-new'),
    ]).then(([g, p, n]) => { setGuides(g.data); setPolicies(p.data); setNews(n.data) }).catch(() => setFailed(true))
  }, [tab, guides])

  function read(a: HelpArticle) {
    setReading(a)
    setConsulted((ids) => (ids.includes(a.id) ? ids : [...ids, a.id]))
  }

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
            <button type="button" className="ayuda-item" onClick={() => read(a)}>
              <b>{a.title}</b>
              {a.source === 'organization' && <span className="sub2">{t('help.source_org')}{a.review_due ? ` · ${t('help.review_due')}` : ''}</span>}
              {a.summary && <span className="sub2">{a.summary}</span>}
            </button>
          </li>
        ))}
      </ul>
    )
  }

  const guidesContent = (
    <>
      {hasTours && <button type="button" className="btn btn-sec btn-sm mb-3" onClick={startTour}>{t('help.tour_open')}</button>}
      {list(guides, t('help.empty_guides'))}
      {policies && policies.length > 0 && (
        <section aria-label={t('help.policies')}>
          <h3 className="panel-sec">{t('help.policies')}</h3>
          {list(policies, '')}
        </section>
      )}
      {news && news.length > 0 && (
        <section aria-label={t('help.whats_new')}>
          <h3 className="panel-sec">{t('help.whats_new')}</h3>
          {list(news, '')}
        </section>
      )}
    </>
  )

  return (
    <SidePanel title={t('help.title')} onClose={onClose} closeLabel={t('ui.close')} wide>
      {reading ? (
        <HelpArticleView article={reading} screenKey={screenKey} onBack={() => setReading(null)} onAsk={canAsk ? () => { setReading(null); setResults(null); setTab('queries') } : undefined} />
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
                { id: 'guides', label: t('help.tab_guides'), content: guidesContent },
                ...(assistant?.available ? [{ id: 'assistant', label: t('ai.tab'), content: <HelpAssistantTab screenKey={screenKey} availability={assistant} onOpenArticle={(id) => void openArticle(id)} onEscalate={canAsk ? () => setTab('queries') : undefined} /> }] : []),
                ...(canAsk ? [{ id: 'queries', label: t('help.tab_queries'), content: <MyQueries screenKey={screenKey} consulted={consulted} /> }] : []),
              ]}
            />
          )}
        </>
      )}
    </SidePanel>
  )
}

/** AYU-6 · Mis consultas: derivar la duda a RRHH (o al soporte de GestiaHR, solo el administrador) y seguirla. */
function MyQueries({ screenKey, consulted }: { screenKey: string | null; consulted: string[] }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const [rows, setRows] = useState<HelpEscalation[] | null>(null)
  const [question, setQuestion] = useState('')
  const [target, setTarget] = useState<'hr' | 'platform_support'>('hr')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [sent, setSent] = useState(false)

  const load = useCallback(() => {
    if (!can('help.escalations.view')) { setRows([]); return }
    api.get<{ data: HelpEscalation[] }>('/help/escalations').then((r) => setRows(r.data)).catch(() => setRows([]))
  }, [can])
  useEffect(load, [load])

  async function send(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.post('/help/escalations', { question: question.trim(), target, screen_key: screenKey, consulted_articles: consulted })
      setQuestion('')
      setSent(true)
      load()
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.message : t('help.error'))
    } finally {
      setBusy(false)
    }
  }

  const tone = (s: HelpEscalation['status']) => (s === 'answered' ? 'ok' : s === 'closed' ? 'gr' : 'in')

  return (
    <>
      {can('help.escalations.create') && (
        <form className="mb-3" onSubmit={send}>
          <p className="text-secondary small">{t('help.ask_intro')}</p>
          {sent && <Notice tone="ok">{t('help.ask_sent')}</Notice>}
          {error && <Notice tone="error">{error}</Notice>}
          <label className="form-label" htmlFor="ayuda-pregunta">{t('help.ask_label')}</label>
          <textarea id="ayuda-pregunta" className="form-control mb-2" rows={3} minLength={5} maxLength={2000} required value={question} onChange={(e) => { setQuestion(e.target.value); setSent(false) }} />
          {can('help.escalations.support') && (
            <>
              <label className="form-label" htmlFor="ayuda-destino">{t('help.ask_target')}</label>
              <select id="ayuda-destino" className="form-select mb-2" value={target} onChange={(e) => setTarget(e.target.value as 'hr' | 'platform_support')}>
                <option value="hr">{t('help.target_hr')}</option>
                <option value="platform_support">{t('help.target_support')}</option>
              </select>
            </>
          )}
          {consulted.length > 0 && <p className="small text-secondary">{t('help.ask_consulted', { n: consulted.length })}</p>}
          <button type="submit" className="btn btn-primary" disabled={busy || question.trim().length < 5}>{t('help.ask_send')}</button>
        </form>
      )}
      <h3 className="panel-sec">{t('help.my_queries')}</h3>
      {rows === null ? <span className="esq" style={{ width: 220 }} /> : rows.length === 0 ? <p className="text-secondary">{t('help.no_queries')}</p> : (
        <ul className="ayuda-lista">
          {rows.map((r) => (
            <li key={r.id} className="ayuda-item">
              <b>{r.question}</b>
              <span className="sub2">
                <StatusBadge tone={tone(r.status)}>{t(`help.query_status.${r.status}`)}</StatusBadge>
                {' '}{t(r.target === 'hr' ? 'help.target_hr' : 'help.target_support')}{r.request_number ? ` · ${r.request_number}` : ''} · {formatDate(locale, r.created_at)}
              </span>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

function HelpArticleView({ article, screenKey, onBack, onAsk }: { article: HelpArticle; screenKey: string | null; onBack: () => void; onAsk?: () => void }) {
  const { t, locale } = useT()
  const [voted, setVoted] = useState<boolean | null>(null)

  async function vote(helpful: boolean) {
    setVoted(helpful)
    try { await api.post(`/help/articles/${article.id}/feedback`, { is_helpful: helpful, screen_key: screenKey }) } catch { setVoted(null) }
  }

  return (
    <article className="ayuda-articulo" lang={article.locale}>
      <button type="button" className="btn btn-sec btn-sm mb-3" onClick={onBack}><Icon name="chevronLeft" /> {t('help.back')}</button>
      <h3>{article.title}</h3>
      {article.translation_missing && <Notice tone="info">{t('help.translation_missing')}</Notice>}
      {article.review_due && <Notice tone="info">{t('help.review_due_notice')}</Notice>}
      <HelpBody body={article.body} />
      <p className="small text-secondary">
        {article.source === 'organization'
          ? t('help.version_org', { version: article.version_number, date: formatDate(locale, article.published_at) })
          : t('help.version', { version: article.version_number, product: article.product_version, date: formatDate(locale, article.published_at) })}
      </p>
      <div className="ayuda-voto" role="group" aria-label={t('help.helpful_q')}>
        {voted === null ? (
          <>
            <span>{t('help.helpful_q')}</span>
            <button type="button" className="btn btn-sec btn-sm" onClick={() => void vote(true)}>{t('help.helpful_yes')}</button>
            <button type="button" className="btn btn-sec btn-sm" onClick={() => void vote(false)}>{t('help.helpful_no')}</button>
          </>
        ) : <span>{t('help.helpful_thanks')}</span>}
      </div>
      {onAsk && voted === false && <button type="button" className="btn btn-primary btn-sm mt-2" onClick={onAsk}>{t('help.ask_hr')}</button>}
      {onAsk && voted !== false && <button type="button" className="btn btn-link btn-sm mt-2" onClick={onAsk}>{t('help.ask_hr')}</button>}
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
