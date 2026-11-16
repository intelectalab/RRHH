'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { formErrors } from '@/lib/errors'
import { formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { HELP_SCREENS } from '@/modules/help/screens'
import { Icon } from '@/ui/components/Icon'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { useConsole, useConsoleTabs } from './ConsoleShell'
import { consoleApi } from './consoleApi'

type HelpKind = 'guide' | 'screen' | 'field' | 'tour' | 'release_note' | 'faq'
type HelpVersion = { version_number: number; product_version: string; title: string; body: string; published_at: string }
type ConsoleHelpArticle = {
  id: string; slug: string; locale: string; title: string; summary: string | null; body: string; kind: HelpKind
  module_code: string; roles: string[]; screen_keys: string[]; product_version: string
  status: 'draft' | 'published' | 'archived'; version_number: number | null; has_unpublished_changes: boolean
  published_at: string | null; versions?: HelpVersion[]
}

const KINDS: HelpKind[] = ['guide', 'screen', 'field', 'tour', 'release_note', 'faq']
const LOCALES = ['es', 'en', 'pt']
const MODULES = ['base', 'core', 'time_off']
const ROLES = ['admin_empresa', 'rrhh', 'payroll', 'recruiter', 'manager', 'empleado', 'auditor', 'sst', 'investigador_denuncias', 'contador']
const SCREEN_KEYS = Array.from(new Set(HELP_SCREENS.map((s) => s.key)))

/**
 * AYU-1 · Consola → Ayuda del producto: artículos por módulo, rol y pantalla en cada idioma. Publicar crea
 * una versión nueva y conserva las anteriores (RN-AYU-02); las organizaciones ven la última publicada.
 */
export function ConsoleHelpArticles() {
  const { t, locale } = useT()
  const me = useConsole()
  const tabs = useConsoleTabs()
  const [rows, setRows] = useState<ConsoleHelpArticle[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [status, setStatus] = useState('')
  const [lang, setLang] = useState('')
  const [q, setQ] = useState('')
  const [editing, setEditing] = useState<ConsoleHelpArticle | 'new' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const can = (p: string) => me.permissions.includes(p)

  const load = useCallback(() => {
    setFailed(false)
    const params = new URLSearchParams()
    if (status) params.set('filter[status]', status)
    if (lang) params.set('filter[locale]', lang)
    if (q.trim()) params.set('q', q.trim())
    consoleApi.get<{ data: ConsoleHelpArticle[] }>(`/help-articles?${params.toString()}`).then((r) => setRows(r.data)).catch(() => setFailed(true))
  }, [status, lang, q])
  useEffect(load, [load])

  async function act(a: ConsoleHelpArticle, action: 'publish' | 'archive') {
    setError(null)
    try {
      await consoleApi.post(`/help-articles/${a.id}/${action}`, {})
      load()
    } catch (err) {
      setError(formErrors(err, t).general ?? t('console.load_error'))
    }
  }

  return (
    <>
      <PageHeader
        title={t('console.help_title')}
        sub={t('console.help_sub')}
        tabs={tabs}
        actions={can('platform.help_articles.create') ? <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('console.help_new')}</button> : undefined}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error" onDismiss={() => setError(null)}>{error}</Notice>}
        <div className="filtros mb-3">
          <label className="vh" htmlFor="ha-q">{t('console.help_search')}</label>
          <input id="ha-q" className="form-control" type="search" value={q} placeholder={t('console.help_search')} onChange={(e) => setQ(e.target.value)} />
          <label className="vh" htmlFor="ha-status">{t('console.tenant_status')}</label>
          <select id="ha-status" className="form-select" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t('console.help_all_status')}</option>
            {(['draft', 'published', 'archived'] as const).map((s) => <option key={s} value={s}>{t(`console.help_status.${s}`)}</option>)}
          </select>
          <label className="vh" htmlFor="ha-locale">{t('console.help_locale')}</label>
          <select id="ha-locale" className="form-select" value={lang} onChange={(e) => setLang(e.target.value)}>
            <option value="">{t('console.help_all_locales')}</option>
            {LOCALES.map((l) => <option key={l} value={l}>{t(`shell.lang_${l}`)}</option>)}
          </select>
        </div>
        {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
        {!failed && !rows && <span className="esq" style={{ width: 320 }} />}
        {rows && rows.length === 0 && <EmptyState title={t('console.help_empty_title')} text={t('console.help_empty')} />}
        {rows && rows.length > 0 && (
          <section className="card">
            <div className="tabla-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">{t('console.help_article')}</th>
                    <th scope="col">{t('console.help_kind')}</th>
                    <th scope="col">{t('console.help_module')}</th>
                    <th scope="col">{t('console.tenant_status')}</th>
                    <th scope="col">{t('console.help_version')}</th>
                    <th scope="col"><span className="vh">{t('console.module_action')}</span></th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((a) => (
                    <tr key={a.id}>
                      <td>
                        <b>{a.title}</b> <Tag tone="gr">{a.locale}</Tag>
                        <span className="sub2">{a.slug}</span>
                      </td>
                      <td>{t(`console.help_kind_label.${a.kind}`)}</td>
                      <td>{a.module_code}</td>
                      <td>
                        <StatusBadge tone={a.status === 'published' ? 'ok' : a.status === 'draft' ? 'in' : 'gr'}>{t(`console.help_status.${a.status}`)}</StatusBadge>
                        {a.status === 'published' && a.has_unpublished_changes && <span className="sub2">{t('console.help_unpublished')}</span>}
                      </td>
                      <td className="nowrap">
                        {a.version_number === null ? '—' : t('console.help_version_n', { n: a.version_number, product: a.product_version })}
                        {a.published_at && <span className="sub2">{formatDateTime(locale, a.published_at)}</span>}
                      </td>
                      <td className="text-end nowrap">
                        {can('platform.help_articles.update') && <><button type="button" className="btn btn-sec btn-sm" onClick={() => setEditing(a)}>{t('console.edit')}</button>{' '}</>}
                        {can('platform.help_articles.publish') && (a.status !== 'published' || a.has_unpublished_changes) && <><button type="button" className="btn btn-sec btn-sm" onClick={() => void act(a, 'publish')}>{t('console.help_action.publish')}</button>{' '}</>}
                        {can('platform.help_articles.archive') && a.status !== 'archived' && <button type="button" className="btn btn-sec btn-sm" onClick={() => void act(a, 'archive')}>{t('console.help_action.archive')}</button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}
      </main>
      {editing && <HelpEditor article={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); load() }} />}
    </>
  )
}

function HelpEditor({ article, onClose, onDone }: { article: ConsoleHelpArticle | null; onClose: () => void; onDone: () => void }) {
  const { t, locale } = useT()
  const [form, setForm] = useState({
    slug: article?.slug ?? '', locale: article?.locale ?? 'es', kind: article?.kind ?? 'guide', module_code: article?.module_code ?? 'base',
    product_version: article?.product_version ?? '', title: article?.title ?? '', summary: article?.summary ?? '', body: article?.body ?? '',
  })
  const [roles, setRoles] = useState<string[]>(article?.roles ?? [])
  const [screens, setScreens] = useState<string[]>(article?.screen_keys ?? [])
  const [versions, setVersions] = useState<HelpVersion[] | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  useEffect(() => {
    if (!article) return
    consoleApi.get<{ data: ConsoleHelpArticle }>(`/help-articles/${article.id}`).then((r) => setVersions(r.data.versions ?? [])).catch(() => setVersions([]))
  }, [article])

  const toggle = (list: string[], set: (v: string[]) => void, value: string) => set(list.includes(value) ? list.filter((v) => v !== value) : [...list, value])
  const field = (key: keyof typeof form, value: string) => setForm((f) => ({ ...f, [key]: value }))

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setErrors({})
    setGeneral(null)
    const body = {
      title: form.title, summary: form.summary.trim() === '' ? null : form.summary, body: form.body, kind: form.kind,
      module_code: form.module_code, roles, screen_keys: screens, ...(form.product_version.trim() ? { product_version: form.product_version.trim() } : {}),
    }
    try {
      if (article) await consoleApi.request(`/help-articles/${article.id}`, 'PATCH', body)
      else await consoleApi.post('/help-articles', { ...body, slug: form.slug, locale: form.locale })
      onDone()
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.fields)
      setGeneral(r.general)
    }
  }

  const input = (key: keyof typeof form, label: string, disabled = false) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`ha-${key}`}>{label}</label>
      <input id={`ha-${key}`} className={`form-control${errors[key] ? ' is-invalid' : ''}`} value={form[key]} disabled={disabled} onChange={(e) => field(key, e.target.value)} />
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )

  return (
    <SidePanel
      title={article ? t('console.help_edit') : t('console.help_new')}
      onClose={onClose}
      closeLabel={t('ui.close')}
      wide
      footer={(
        <>
          <button type="button" className="btn btn-sec" onClick={onClose}>{t('console.cancel')}</button>
          <button type="submit" form="ha-form" className="btn btn-primary">{t('console.save')}</button>
        </>
      )}
    >
      <form id="ha-form" onSubmit={submit} noValidate>
        {general && <Notice tone="error">{general}</Notice>}
        {article && <p className="small text-secondary">{t('console.help_publish_note')}</p>}
        {input('slug', t('console.help_slug'), article !== null)}
        <div className="mb-3">
          <label className="form-label" htmlFor="ha-locale-f">{t('console.help_locale')}</label>
          <select id="ha-locale-f" className="form-select" value={form.locale} disabled={article !== null} onChange={(e) => field('locale', e.target.value)}>
            {LOCALES.map((l) => <option key={l} value={l}>{t(`shell.lang_${l}`)}</option>)}
          </select>
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="ha-kind">{t('console.help_kind')}</label>
          <select id="ha-kind" className="form-select" value={form.kind} onChange={(e) => field('kind', e.target.value)}>
            {KINDS.map((k) => <option key={k} value={k}>{t(`console.help_kind_label.${k}`)}</option>)}
          </select>
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="ha-module">{t('console.help_module')}</label>
          <select id="ha-module" className={`form-select${errors.module_code ? ' is-invalid' : ''}`} value={form.module_code} onChange={(e) => field('module_code', e.target.value)}>
            {MODULES.map((m) => <option key={m} value={m}>{m}</option>)}
          </select>
          {errors.module_code && <div className="invalid-feedback" role="alert">{errors.module_code}</div>}
        </div>
        {input('product_version', t('console.help_product_version'))}
        {input('title', t('console.help_article'))}
        {input('summary', t('console.help_summary'))}
        <div className="mb-3">
          <label className="form-label" htmlFor="ha-body">{t('console.help_body')}</label>
          <textarea id="ha-body" className={`form-control${errors.body ? ' is-invalid' : ''}`} rows={8} value={form.body} onChange={(e) => field('body', e.target.value)} aria-describedby="ha-body-help" />
          <div id="ha-body-help" className="form-text">{t('console.help_body_help')}</div>
          {errors.body && <div className="invalid-feedback" role="alert">{errors.body}</div>}
        </div>
        <fieldset className="mb-3">
          <legend className="form-label">{t('console.help_roles')}</legend>
          <p className="small text-secondary">{t('console.help_roles_help')}</p>
          {ROLES.map((r) => (
            <div key={r} className="form-check">
              <input id={`ha-r-${r}`} type="checkbox" className="form-check-input" checked={roles.includes(r)} onChange={() => toggle(roles, setRoles, r)} />
              <label className="form-check-label" htmlFor={`ha-r-${r}`}>{r}</label>
            </div>
          ))}
          {errors.roles && <div className="text-danger small" role="alert">{errors.roles}</div>}
        </fieldset>
        <fieldset className="mb-3">
          <legend className="form-label">{t('console.help_screens')}</legend>
          {SCREEN_KEYS.map((s) => (
            <div key={s} className="form-check">
              <input id={`ha-s-${s}`} type="checkbox" className="form-check-input" checked={screens.includes(s)} onChange={() => toggle(screens, setScreens, s)} />
              <label className="form-check-label" htmlFor={`ha-s-${s}`}>{s}</label>
            </div>
          ))}
          {errors.screen_keys && <div className="text-danger small" role="alert">{errors.screen_keys}</div>}
        </fieldset>
        {versions && versions.length > 0 && (
          <>
            <h3 className="panel-sec">{t('console.help_versions')}</h3>
            <ul className="lista-simple">
              {versions.map((v) => (
                <li key={v.version_number}>
                  <b>{t('console.help_version_n', { n: v.version_number, product: v.product_version })}</b> · {formatDateTime(locale, v.published_at)}
                  <details><summary>{v.title}</summary><p className="small" style={{ whiteSpace: 'pre-wrap' }}>{v.body}</p></details>
                </li>
              ))}
            </ul>
          </>
        )}
      </form>
    </SidePanel>
  )
}
