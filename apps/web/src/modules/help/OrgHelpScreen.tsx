'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { SkeletonRows } from '@/ui/components/Skeleton'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge, type StatusTone } from '@/ui/components/Status'
import { Tabs } from '@/ui/components/Tabs'

type OrgStatus = 'draft' | 'published' | 'in_review' | 'archived'

export type OrgArticle = {
  id: string; title: string; body: string; locale: string
  audience: { roles: string[]; legal_entity_ids: string[]; site_ids: string[] }
  owner_user_id: string | null; owner_name: string | null; review_on: string | null
  version: number; status: OrgStatus; has_unpublished_changes: boolean; review_due: boolean
  published_at: string | null; helpful_count: number; not_helpful_count: number; allowed_actions: string[]
}

type Insights = {
  days: number
  searches_without_result: Array<{ query_text: string; times: number; last_at: string }>
  feedback: Array<{ article_id: string; source: string; title: string; helpful: number; not_helpful: number }>
  escalations: { total: number; hr: number; platform_support: number }
}

type Option = { id: string; name: string }

export const ORG_STATUS_TONE: Record<OrgStatus, StatusTone> = { draft: 'gr', published: 'ok', in_review: 'wa', archived: 'gr' }

/**
 * AYU-4 · AYU-5 · Configuración → Ayuda de tu organización: artículos y políticas propias con audiencia,
 * responsable, versión y fecha de revisión, y el uso de la ayuda (búsquedas sin resultado y valoración).
 */
export function OrgHelpScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const [tab, setTab] = useState('articles')
  const [rows, setRows] = useState<OrgArticle[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [editing, setEditing] = useState<OrgArticle | 'new' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [insights, setInsights] = useState<Insights | null>(null)

  const load = useCallback(() => {
    setFailed(false)
    api.get<{ data: OrgArticle[] }>('/help/org-articles').then((r) => setRows(r.data)).catch(() => setFailed(true))
  }, [])
  useEffect(load, [load])

  useEffect(() => {
    if (tab === 'usage' && !insights && can('help.insights.view')) api.get<{ data: Insights }>('/help/insights').then((r) => setInsights(r.data)).catch(() => undefined)
  }, [tab, insights, can])

  async function act(a: OrgArticle, action: 'publish' | 'archive') {
    setError(null)
    try {
      await api.post(`/help/org-articles/${a.id}/${action}`, {})
      load()
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.message : t('help.error'))
    }
  }

  const articles = rows === null ? (failed ? <ErrorState text={t('help.error')} retryLabel={t('common.retry')} onRetry={load} /> : <SkeletonRows />) : rows.length === 0 ? (
    <EmptyState title={t('orghelp.empty_title')} text={t('orghelp.empty')} />
  ) : (
    <div className="tabla-wrap">
      <table className="tabla">
        <thead>
          <tr>
            <th scope="col">{t('orghelp.article')}</th>
            <th scope="col">{t('orghelp.status')}</th>
            <th scope="col">{t('orghelp.owner')}</th>
            <th scope="col">{t('orghelp.review_on')}</th>
            <th scope="col">{t('orghelp.rating')}</th>
            <th scope="col"><span className="vh">{t('orghelp.actions')}</span></th>
          </tr>
        </thead>
        <tbody>
          {rows.map((a) => (
            <tr key={a.id}>
              <td data-label={t('orghelp.article')}>
                <b>{a.title}</b>
                <span className="sub2">{a.version > 0 ? t('orghelp.version_n', { n: a.version }) : t('orghelp.never_published')}{a.has_unpublished_changes && a.version > 0 ? ` · ${t('orghelp.unpublished')}` : ''}</span>
              </td>
              <td data-label={t('orghelp.status')}>
                <StatusBadge tone={ORG_STATUS_TONE[a.status]}>{t(`orghelp.status_${a.status}`)}</StatusBadge>
                {a.review_due && a.status === 'published' && <span className="sub2">{t('help.review_due')}</span>}
              </td>
              <td data-label={t('orghelp.owner')}>{a.owner_name ?? '—'}</td>
              <td data-label={t('orghelp.review_on')}>{a.review_on ? formatDate(locale, a.review_on) : '—'}</td>
              <td data-label={t('orghelp.rating')}>{t('orghelp.votes', { yes: a.helpful_count, no: a.not_helpful_count })}</td>
              <td className="acciones">
                {can('help.org_articles.update') && <button type="button" className="btn btn-sec btn-sm" onClick={() => setEditing(a)}>{t('orghelp.edit')}</button>}
                {can('help.org_articles.publish') && a.allowed_actions.includes('publish') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void act(a, 'publish')}>{t('orghelp.publish')}</button>}
                {can('help.org_articles.archive') && a.allowed_actions.includes('archive') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void act(a, 'archive')}>{t('orghelp.archive')}</button>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )

  const usage = !insights ? <SkeletonRows /> : (
    <>
      <p className="text-secondary">{t('orghelp.usage_intro', { days: insights.days, total: insights.escalations.total, hr: insights.escalations.hr })}</p>
      <h3 className="panel-sec">{t('orghelp.no_results')}</h3>
      {insights.searches_without_result.length === 0 ? <p className="text-secondary">{t('orghelp.no_results_empty')}</p> : (
        <ul className="ayuda-lista">
          {insights.searches_without_result.map((s) => <li key={s.query_text} className="ayuda-item"><b>{s.query_text}</b><span className="sub2">{t('orghelp.times', { n: s.times })} · {formatDate(locale, s.last_at)}</span></li>)}
        </ul>
      )}
      <h3 className="panel-sec">{t('orghelp.rating')}</h3>
      {insights.feedback.length === 0 ? <p className="text-secondary">{t('orghelp.rating_empty')}</p> : (
        <ul className="ayuda-lista">
          {insights.feedback.map((f) => <li key={f.article_id} className="ayuda-item"><b>{f.title}</b><span className="sub2">{t(f.source === 'organization' ? 'help.source_org' : 'help.source_product')} · {t('orghelp.votes', { yes: f.helpful, no: f.not_helpful })}</span></li>)}
        </ul>
      )}
    </>
  )

  return (
    <>
      <PageHeader
        title={t('orghelp.title')}
        sub={t('orghelp.sub')}
        actions={can('help.org_articles.create') ? <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('orghelp.new')}</button> : undefined}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error" onDismiss={() => setError(null)}>{error}</Notice>}
        <Tabs
          label={t('orghelp.title')}
          value={tab}
          onChange={setTab}
          items={[
            { id: 'articles', label: t('orghelp.tab_articles'), content: articles },
            ...(can('help.insights.view') ? [{ id: 'usage', label: t('orghelp.tab_usage'), content: usage }] : []),
          ]}
        />
      </main>
      {editing && <OrgArticleEditor article={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load() }} />}
    </>
  )
}

function OrgArticleEditor({ article, onClose, onSaved }: { article: OrgArticle | null; onClose: () => void; onSaved: () => void }) {
  const { t } = useT()
  const { session } = useSession()
  const [title, setTitle] = useState(article?.title ?? '')
  const [body, setBody] = useState(article?.body ?? '')
  const [owner, setOwner] = useState(article?.owner_user_id ?? session.user.id)
  const [reviewOn, setReviewOn] = useState(article?.review_on ?? '')
  const [roles, setRoles] = useState<string[]>(article?.audience.roles ?? [])
  const [entities, setEntities] = useState<string[]>(article?.audience.legal_entity_ids ?? [])
  const [sites, setSites] = useState<string[]>(article?.audience.site_ids ?? [])
  const [options, setOptions] = useState<{ roles: Option[]; entities: Option[]; sites: Option[]; users: Option[] } | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    Promise.all([
      api.get<{ data: Array<{ id: string; name: string }> }>('/roles?per_page=100').catch(() => ({ data: [] })),
      api.get<{ data: Array<{ id: string; legal_name: string; trade_name?: string | null }> }>('/legal-entities?status=active&per_page=100').catch(() => ({ data: [] })),
      api.get<{ data: Array<{ id: string; name: string }> }>('/sites?per_page=100').catch(() => ({ data: [] })),
      api.get<{ data: Array<{ id: string; display_name: string }> }>('/users?per_page=100&status=active').catch(() => ({ data: [] })),
    ]).then(([r, e, s, u]) => setOptions({
      roles: r.data.map((x) => ({ id: x.id, name: x.name })),
      entities: e.data.map((x) => ({ id: x.id, name: x.trade_name || x.legal_name })),
      sites: s.data.map((x) => ({ id: x.id, name: x.name })),
      users: u.data.map((x) => ({ id: x.id, name: x.display_name })),
    }))
  }, [])

  const toggle = (list: string[], set: (v: string[]) => void, id: string) => set(list.includes(id) ? list.filter((x) => x !== id) : [...list, id])

  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    const payload = { title, body, owner_user_id: owner, review_on: reviewOn || null, audience: { roles, legal_entity_ids: entities, site_ids: sites } }
    try {
      if (article) await api.request(`/help/org-articles/${article.id}`, 'PATCH', payload)
      else await api.post('/help/org-articles', payload)
      onSaved()
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.message : t('help.error'))
      setBusy(false)
    }
  }

  const checks = (prefix: string, items: Option[], list: string[], set: (v: string[]) => void) => (
    items.length === 0 ? <p className="small text-secondary">—</p> : items.map((o) => (
      <div key={o.id} className="form-check">
        <input id={`${prefix}-${o.id}`} type="checkbox" className="form-check-input" checked={list.includes(o.id)} onChange={() => toggle(list, set, o.id)} />
        <label className="form-check-label" htmlFor={`${prefix}-${o.id}`}>{o.name}</label>
      </div>
    ))
  )

  return (
    <SidePanel
      title={article ? t('orghelp.edit') : t('orghelp.new')}
      onClose={onClose}
      closeLabel={t('ui.close')}
      wide
      footer={(
        <>
          <button type="button" className="btn btn-sec" onClick={onClose}>{t('orghelp.cancel')}</button>
          <button type="submit" form="org-article" className="btn btn-primary" disabled={busy}>{t('orghelp.save')}</button>
        </>
      )}
    >
      <form id="org-article" onSubmit={save}>
        {error && <Notice tone="error">{error}</Notice>}
        <p className="small text-secondary">{t('orghelp.publish_note')}</p>
        <label className="form-label" htmlFor="oa-title">{t('orghelp.f_title')}</label>
        <input id="oa-title" className="form-control mb-3" required minLength={2} maxLength={200} value={title} onChange={(e) => setTitle(e.target.value)} />
        <label className="form-label" htmlFor="oa-body">{t('orghelp.f_body')}</label>
        <textarea id="oa-body" className="form-control mb-1" rows={10} required value={body} onChange={(e) => setBody(e.target.value)} />
        <p className="small text-secondary mb-3">{t('orghelp.f_body_help')}</p>
        <label className="form-label" htmlFor="oa-owner">{t('orghelp.owner')}</label>
        <select id="oa-owner" className="form-select mb-3" required value={owner} onChange={(e) => setOwner(e.target.value)}>
          {!options?.users.some((u) => u.id === owner) && <option value={owner}>{article?.owner_name ?? session.user.display_name}</option>}
          {options?.users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
        </select>
        <label className="form-label" htmlFor="oa-review">{t('orghelp.review_on')}</label>
        <input id="oa-review" type="date" className="form-control mb-1" value={reviewOn} onChange={(e) => setReviewOn(e.target.value)} />
        <p className="small text-secondary mb-3">{t('orghelp.review_help')}</p>
        <fieldset className="mb-3">
          <legend className="form-label">{t('orghelp.audience')}</legend>
          <p className="small text-secondary">{t('orghelp.audience_help')}</p>
          <h4 className="panel-sec">{t('orghelp.aud_roles')}</h4>
          {checks('oa-r', options?.roles ?? [], roles, setRoles)}
          <h4 className="panel-sec">{t('orghelp.aud_entities')}</h4>
          {checks('oa-e', options?.entities ?? [], entities, setEntities)}
          <h4 className="panel-sec">{t('orghelp.aud_sites')}</h4>
          {checks('oa-s', options?.sites ?? [], sites, setSites)}
        </fieldset>
      </form>
    </SidePanel>
  )
}
