'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SkeletonRows } from '@/ui/components/Skeleton'
import { ErrorState } from '@/ui/components/States'
import { Tabs } from '@/ui/components/Tabs'
import type { AiUsageLog, AiUseCaseSetting, AssistantQuality } from './ai'

type Option = { id: string; name: string }

/**
 * IAR-10 · Configuración → Asistente de ayuda: encender o apagar, roles habilitados, límite de preguntas y aviso.
 * Si el plan no lo incluye, lo dice y no deja encenderlo (la API también lo rechaza).
 * IAR-11 · Calidad: preguntas sin respuesta y respuestas mal valoradas. IAR-2 · Bitácora de usos de IA.
 */
export function AssistantSettingsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const [tab, setTab] = useState('settings')
  const [setting, setSetting] = useState<AiUseCaseSetting | null>(null)
  const [roles, setRoles] = useState<Option[]>([])
  const [failed, setFailed] = useState(false)
  const [quality, setQuality] = useState<AssistantQuality | null>(null)
  const [logs, setLogs] = useState<AiUsageLog[] | null>(null)

  const load = useCallback(() => {
    setFailed(false)
    Promise.all([
      api.get<{ data: AiUseCaseSetting }>('/ai/use-case-settings/help_assistant'),
      api.get<{ data: Option[] }>('/roles?per_page=100').catch(() => ({ data: [] as Option[] })),
    ]).then(([s, r]) => { setSetting(s.data); setRoles(r.data) }).catch(() => setFailed(true))
  }, [])
  useEffect(load, [load])

  useEffect(() => {
    if (tab === 'quality' && !quality) api.get<{ data: AssistantQuality }>('/ai/help-assistant/quality').then((r) => setQuality(r.data)).catch(() => setFailed(true))
    if (tab === 'logs' && !logs) api.get<{ data: AiUsageLog[] }>('/ai/usage-logs').then((r) => setLogs(r.data)).catch(() => setFailed(true))
  }, [tab, quality, logs])

  const settings = failed && !setting ? <ErrorState text={t('ai.cfg_error')} onRetry={load} retryLabel={t('common.retry')} />
    : !setting ? <SkeletonRows /> : <SettingsForm setting={setting} roles={roles} canEdit={can('ai.use_case_settings.update')} onSaved={setSetting} />

  const qualityContent = !quality ? <SkeletonRows /> : (
    <>
      <p className="text-secondary">{t('ai.quality_intro', { days: quality.days, total: quality.questions, answered: quality.answered, up: quality.rated_up, down: quality.rated_down })}</p>
      <h3 className="panel-sec">{t('ai.quality_unanswered')}</h3>
      {quality.unanswered_questions.length === 0 ? <p className="text-secondary">{t('ai.quality_unanswered_empty')}</p> : (
        <ul className="ayuda-lista">
          {quality.unanswered_questions.map((q) => <li key={q.query_text} className="ayuda-item"><b>{q.query_text}</b><span className="sub2">{t('orghelp.times', { n: q.times })} · {formatDate(locale, q.last_at)}</span></li>)}
        </ul>
      )}
      <h3 className="panel-sec">{t('ai.quality_badly_rated')}</h3>
      {quality.badly_rated.length === 0 ? <p className="text-secondary">{t('ai.quality_badly_rated_empty')}</p> : (
        <ul className="ayuda-lista">
          {quality.badly_rated.map((b) => (
            <li key={b.interaction_id} className="ayuda-item">
              <b>{b.cited_articles.map((a) => a.title ?? a.id).join(' · ')}</b>
              <span className="sub2">{b.comment ? `${b.comment} · ` : ''}{formatDate(locale, b.rated_at)}</span>
            </li>
          ))}
        </ul>
      )}
    </>
  )

  const logsContent = !logs ? <SkeletonRows /> : logs.length === 0 ? <p className="text-secondary">{t('ai.logs_empty')}</p> : (
    <div className="table-responsive">
      <table className="table">
        <thead>
          <tr><th>{t('ai.logs_when')}</th><th>{t('ai.logs_user')}</th><th>{t('ai.logs_use_case')}</th><th>{t('ai.logs_tokens')}</th><th>{t('ai.logs_sources')}</th><th>{t('ai.logs_content')}</th></tr>
        </thead>
        <tbody>
          {logs.map((l) => (
            <tr key={l.id}>
              <td data-label={t('ai.logs_when')}>{formatDate(locale, l.occurred_at)}</td>
              <td data-label={t('ai.logs_user')}>{l.user_name ?? '—'}</td>
              <td data-label={t('ai.logs_use_case')}>{l.use_case}</td>
              <td data-label={t('ai.logs_tokens')}>{(l.input_tokens ?? 0) + (l.output_tokens ?? 0)}</td>
              <td data-label={t('ai.logs_sources')}>{l.citations.length}</td>
              <td data-label={t('ai.logs_content')}>{t(l.content_purged ? 'ai.logs_purged' : 'ai.logs_kept')}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )

  return (
    <>
      <PageHeader title={t('ai.cfg_title')} sub={t('ai.cfg_sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {failed && setting && <Notice tone="error">{t('ai.cfg_error')}</Notice>}
        <Tabs
          label={t('ai.cfg_title')}
          value={tab}
          onChange={setTab}
          items={[
            { id: 'settings', label: t('ai.tab_settings'), content: settings },
            ...(can('ai.usage_logs.view') ? [{ id: 'quality', label: t('ai.tab_quality'), content: qualityContent }, { id: 'logs', label: t('ai.tab_logs'), content: logsContent }] : []),
          ]}
        />
      </main>
    </>
  )
}

function SettingsForm({ setting, roles, canEdit, onSaved }: { setting: AiUseCaseSetting; roles: Option[]; canEdit: boolean; onSaved: (s: AiUseCaseSetting) => void }) {
  const { t } = useT()
  const [enabled, setEnabled] = useState(setting.enabled)
  const [roleIds, setRoleIds] = useState<string[]>(setting.role_ids)
  const [limit, setLimit] = useState(setting.question_limit === null ? '' : String(setting.question_limit))
  const [period, setPeriod] = useState(setting.limit_period)
  const [notice, setNotice] = useState(setting.notice ?? '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const disabled = !canEdit || busy

  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    setSaved(false)
    try {
      const r = await api.request<{ data: AiUseCaseSetting }>('/ai/use-case-settings/help_assistant', 'PATCH', {
        enabled: setting.plan_allows ? enabled : false,
        role_ids: roleIds,
        question_limit: limit === '' ? null : Number(limit),
        limit_period: period,
        notice: notice.trim() === '' ? null : notice.trim(),
      })
      onSaved(r.data)
      setSaved(true)
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.message : t('ai.cfg_error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={save} style={{ maxWidth: 640 }}>
      {!setting.plan_allows && <Notice tone="warn">{t('ai.plan_excluded')}</Notice>}
      {error && <Notice tone="error">{error}</Notice>}
      {saved && <Notice tone="ok">{t('ai.saved')}</Notice>}
      <p className="text-secondary small">{t('ai.cfg_rule')}</p>
      <div className="form-check form-switch mb-3">
        <input id="ia-enabled" className="form-check-input" type="checkbox" checked={enabled && setting.plan_allows} disabled={disabled || !setting.plan_allows} onChange={(e) => setEnabled(e.target.checked)} />
        <label className="form-check-label" htmlFor="ia-enabled">{t('ai.enabled')}</label>
      </div>
      <fieldset className="mb-3" disabled={disabled}>
        <legend className="form-label">{t('ai.roles')}</legend>
        {roles.map((r) => (
          <div key={r.id} className="form-check">
            <input id={`ia-rol-${r.id}`} className="form-check-input" type="checkbox" checked={roleIds.includes(r.id)} onChange={(e) => setRoleIds((ids) => (e.target.checked ? [...ids, r.id] : ids.filter((x) => x !== r.id)))} />
            <label className="form-check-label" htmlFor={`ia-rol-${r.id}`}>{r.name}</label>
          </div>
        ))}
      </fieldset>
      <div className="row g-2 mb-3">
        <div className="col-6">
          <label className="form-label" htmlFor="ia-limit">{t('ai.limit')}</label>
          <input id="ia-limit" className="form-control" type="number" min={1} max={1000} value={limit} disabled={disabled} placeholder={t('ai.limit_none')} onChange={(e) => setLimit(e.target.value)} />
        </div>
        <div className="col-6">
          <label className="form-label" htmlFor="ia-period">{t('ai.period')}</label>
          <select id="ia-period" className="form-select" value={period} disabled={disabled} onChange={(e) => setPeriod(e.target.value as AiUseCaseSetting['limit_period'])}>
            {(['day', 'week', 'month'] as const).map((p) => <option key={p} value={p}>{t(`ai.period_${p}`)}</option>)}
          </select>
        </div>
      </div>
      <label className="form-label" htmlFor="ia-notice">{t('ai.notice')}</label>
      <textarea id="ia-notice" className="form-control mb-3" rows={2} maxLength={500} value={notice} disabled={disabled} onChange={(e) => setNotice(e.target.value)} />
      {canEdit && <button type="submit" className="btn btn-primary" disabled={busy}>{t('ai.save')}</button>}
    </form>
  )
}
