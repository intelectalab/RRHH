'use client'

import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { useT } from '@/lib/useT'
import { useDateTime } from '@/modules/session/useDateTime'
import { useSession } from '@/modules/session/SessionProvider'
import { DataTable, type TableColumn, type TableFilter } from '@/ui/components/DataTable'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState } from '@/ui/components/States'
import { Tabs } from '@/ui/components/Tabs'
import { Timeline } from '@/ui/components/Timeline'
import { useToast } from '@/ui/components/Toast'
import { orgTabs } from './orgTabs'

type Profile = Schema<'JobProfile'>
type CatalogItem = Schema<'JobLevel'> & Partial<Schema<'JobCategory'>>
type Kind = 'level' | 'family' | 'category'
type Version = { version: number; name: string; mission: string | null; functions: string | null; requirements: string | null; change_reason: string | null; changed_by_name: string | null; created_at: string }

const ENDPOINT: Record<Kind, string> = { level: '/job-levels', family: '/job-families', category: '/job-categories' }

/**
 * Organización → Puestos (ORG-5): catálogo de puestos con su descripción versionada, y los catálogos
 * configurables de niveles, familias y categorías laborales.
 */
export function JobProfilesScreen() {
  const { t } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [tab, setTab] = useState('profiles')
  const [catalogs, setCatalogs] = useState<Record<Kind, CatalogItem[]>>({ level: [], family: [], category: [] })
  const [reloadKey, setReloadKey] = useState(0)

  const loadCatalogs = useCallback(async () => {
    const entries = await Promise.all((Object.keys(ENDPOINT) as Kind[]).map(async (k) => {
      const r = await api.get<{ data: CatalogItem[] }>(`${ENDPOINT[k]}?per_page=100&sort=name`)
      return [k, r.data] as const
    }))
    setCatalogs(Object.fromEntries(entries) as Record<Kind, CatalogItem[]>)
  }, [])

  useEffect(() => { void loadCatalogs().catch(() => toast(t('jobs.load_error'), 'error')) }, [loadCatalogs, reloadKey, t, toast])

  const reload = () => setReloadKey((k) => k + 1)

  return (
    <>
      <PageHeader title={t('org.title')} sub={t('jobs.sub')} tabs={orgTabs(t, can, 'jobs')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <Tabs
          label={t('jobs.sections')}
          value={tab}
          onChange={setTab}
          items={[
            { id: 'profiles', label: t('jobs.tab_profiles'), content: <ProfilesTable catalogs={catalogs} reloadKey={reloadKey} onChanged={reload} toast={toast} /> },
            { id: 'level', label: t('jobs.tab_levels'), content: <CatalogTable kind="level" reloadKey={reloadKey} onChanged={reload} toast={toast} /> },
            { id: 'family', label: t('jobs.tab_families'), content: <CatalogTable kind="family" reloadKey={reloadKey} onChanged={reload} toast={toast} /> },
            { id: 'category', label: t('jobs.tab_categories'), content: <CatalogTable kind="category" reloadKey={reloadKey} onChanged={reload} toast={toast} /> },
          ]}
        />
      </main>
      {toastNode}
    </>
  )
}

type Toast = (text: string, kind?: 'ok' | 'error') => void

function StatusBadge({ status }: { status: string }) {
  const { t } = useT()
  return <span className={`st ${status === 'active' ? 'ok' : 'gr'}`}>{t(status === 'active' ? 'jobs.status_active' : 'jobs.status_archived')}</span>
}

function useTransition(base: string, onChanged: () => void, toast: Toast) {
  const { t } = useT()
  return async (id: string, name: string, action: 'archive' | 'restore') => {
    if (action === 'archive' && !window.confirm(t('jobs.confirm_archive', { name }))) return
    try {
      await api.post(`${base}/${id}/${action}`, {})
      toast(t(action === 'archive' ? 'jobs.archived' : 'jobs.restored'))
      onChanged()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }
}

function ProfilesTable({ catalogs, reloadKey, onChanged, toast }: { catalogs: Record<Kind, CatalogItem[]>; reloadKey: number; onChanged: () => void; toast: Toast }) {
  const { t } = useT()
  const { can } = useSession()
  const [editing, setEditing] = useState<Profile | 'new' | null>(null)
  const [history, setHistory] = useState<Profile | null>(null)
  const transition = useTransition('/job-profiles', onChanged, toast)
  const nameOf = (kind: Kind, id?: string | null) => (id ? catalogs[kind].find((c) => c.id === id)?.name ?? '—' : '—')

  const columns: Array<TableColumn<Profile>> = [
    { key: 'name', label: t('jobs.col_name'), sort: 'name', hideable: false, width: 260, render: (r) => <><b>{r.name}</b><span className="sub2">{r.code}</span></> },
    { key: 'level', label: t('jobs.col_level'), render: (r) => nameOf('level', r.job_level_id) },
    { key: 'family', label: t('jobs.col_family'), render: (r) => nameOf('family', r.job_family_id) },
    {
      key: 'category',
      label: t('jobs.col_category'),
      render: (r) => (r.category_pending ? <span className="st wa">{t('jobs.category_pending')}</span> : nameOf('category', r.job_category_id)),
    },
    { key: 'positions', label: t('jobs.col_positions'), render: (r) => r.positions_count ?? 0 },
    { key: 'version', label: t('jobs.col_version'), hiddenByDefault: true, render: (r) => t('jobs.version_n', { n: r.current_version ?? 1 }) },
    { key: 'status', label: t('jobs.col_status'), sort: 'status', render: (r) => <StatusBadge status={r.status} /> },
  ]

  const filters: TableFilter[] = [
    { key: 'status', label: t('jobs.filter_status'), options: [{ value: 'active', label: t('jobs.status_active') }, { value: 'archived', label: t('jobs.status_archived') }] },
    { key: 'category_pending', label: t('jobs.filter_pending'), options: [{ value: 'true', label: t('jobs.pending_yes') }, { value: 'false', label: t('jobs.pending_no') }] },
    ...(catalogs.level.length > 0 ? [{ key: 'job_level_id', label: t('jobs.col_level'), options: catalogs.level.map((c) => ({ value: c.id, label: c.name })) }] : []),
    ...(catalogs.family.length > 0 ? [{ key: 'job_family_id', label: t('jobs.col_family'), options: catalogs.family.map((c) => ({ value: c.id, label: c.name })) }] : []),
  ]

  return (
    <>
      <DataTable<Profile>
        resource="job_profiles"
        endpoint="/job-profiles"
        defaultSort="name"
        searchLabel={t('jobs.search')}
        reloadKey={reloadKey}
        rowKey={(r) => r.id}
        columns={columns}
        filters={filters}
        toolbar={() => can('org.job_profiles.create') && <button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing('new')}><Icon name="plus" /> {t('jobs.new_profile')}</button>}
        empty={<EmptyState title={t('jobs.empty_title')} text={t('jobs.empty_text')} />}
        rowActions={(r) => (
          <>
            <button type="button" className="btn btn-ter btn-sm" onClick={() => setHistory(r)}>{t('jobs.history')}</button>
            {r.status === 'active' && can('org.job_profiles.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(r)}>{t('jobs.edit')}</button>}
            {r.allowed_actions?.includes('archive') && can('org.job_profiles.archive') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r.id, r.name, 'archive')}>{t('jobs.archive')}</button>}
            {r.allowed_actions?.includes('restore') && can('org.job_profiles.restore') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r.id, r.name, 'restore')}>{t('jobs.restore')}</button>}
          </>
        )}
      />
      {editing !== null && (
        <ProfilePanel
          profile={editing === 'new' ? null : editing}
          catalogs={catalogs}
          onClose={() => setEditing(null)}
          onSaved={(created) => { setEditing(null); toast(t(created ? 'jobs.created' : 'jobs.saved')); onChanged() }}
        />
      )}
      {history && <VersionsPanel profile={history} onClose={() => setHistory(null)} />}
    </>
  )
}

function ProfilePanel({ profile, catalogs, onClose, onSaved }: { profile: Profile | null; catalogs: Record<Kind, CatalogItem[]>; onClose: () => void; onSaved: (created: boolean) => void }) {
  const { t } = useT()
  const [form, setForm] = useState({
    code: profile?.code ?? '',
    name: profile?.name ?? '',
    job_level_id: profile?.job_level_id ?? '',
    job_family_id: profile?.job_family_id ?? '',
    job_category_id: profile?.job_category_id ?? '',
    mission: profile?.mission ?? '',
    functions: profile?.functions ?? '',
    requirements: profile?.requirements ?? '',
    occupation_code: profile?.occupation_code ?? '',
    trust_classification: profile?.trust_classification ?? 'none',
    risk_job: Boolean(profile?.risk_job),
    change_reason: '',
  })
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const descriptionChanged = profile !== null && (['name', 'mission', 'functions', 'requirements'] as const).some((k) => (form[k] || '') !== (profile[k] ?? ''))

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const nullable = ['job_level_id', 'job_family_id', 'job_category_id', 'mission', 'functions', 'requirements', 'occupation_code', 'change_reason'] as const
    const body = { ...form, ...Object.fromEntries(nullable.map((k) => [k, form[k].trim() || null])) }
    try {
      if (profile) {
        await api.request(`/job-profiles/${profile.id}`, 'PATCH', body, profile.version !== undefined ? { 'If-Match': `"${profile.version}"` } : undefined)
      } else {
        await api.post('/job-profiles', body)
      }
      onSaved(profile === null)
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      setError(general)
    } finally {
      setBusy(false)
    }
  }

  const field = (key: keyof typeof form, label: string, input: ReactNode, help?: string) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`jp-${key}`}>{label}</label>
      {input}
      {help && <p className="small text-secondary">{help}</p>}
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )
  // Solo se eligen elementos activos; el que el puesto ya tiene se mantiene aunque esté archivado.
  const options = (kind: Kind, current: string) => catalogs[kind].filter((c) => c.status === 'active' || c.id === current)
  const select = (key: 'job_level_id' | 'job_family_id' | 'job_category_id', kind: Kind) => (
    <select id={`jp-${key}`} className={`form-select${errors[key] ? ' is-invalid' : ''}`} value={form[key]} onChange={set(key)}>
      <option value="">{t('jobs.none')}</option>
      {options(kind, form[key]).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
    </select>
  )

  return (
    <SidePanel
      title={t(profile ? 'jobs.form_edit' : 'jobs.form_new')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('jobs.cancel')}</button>
        <button type="submit" form="puesto" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('jobs.save')}</button>
      </>}
    >
      <form id="puesto" onSubmit={submit} noValidate>
        {field('code', t('jobs.code'), <input id="jp-code" className={`form-control${errors.code ? ' is-invalid' : ''}`} value={form.code} onChange={set('code')} required />)}
        {field('name', t('jobs.name'), <input id="jp-name" className={`form-control${errors.name ? ' is-invalid' : ''}`} value={form.name} onChange={set('name')} required />)}
        {field('job_level_id', t('jobs.col_level'), select('job_level_id', 'level'))}
        {field('job_family_id', t('jobs.col_family'), select('job_family_id', 'family'))}
        {field('job_category_id', t('jobs.col_category'), select('job_category_id', 'category'), t('jobs.category_help'))}
        <fieldset className="cf-grupo">
          <legend>{t('jobs.section_description')}</legend>
          <p className="small text-secondary">{t('jobs.description_help')}</p>
          {field('mission', t('jobs.mission'), <textarea id="jp-mission" className="form-control" rows={2} value={form.mission} onChange={set('mission')} />)}
          {field('functions', t('jobs.functions'), <textarea id="jp-functions" className="form-control" rows={4} value={form.functions} onChange={set('functions')} />)}
          {field('requirements', t('jobs.requirements'), <textarea id="jp-requirements" className="form-control" rows={3} value={form.requirements} onChange={set('requirements')} />)}
          {descriptionChanged && field('change_reason', t('jobs.change_reason'), <input id="jp-change_reason" className="form-control" value={form.change_reason} onChange={set('change_reason')} />)}
        </fieldset>
        <fieldset className="cf-grupo">
          <legend>{t('jobs.section_classification')}</legend>
          {field('trust_classification', t('jobs.trust'), (
            <select id="jp-trust_classification" className="form-select" value={form.trust_classification} onChange={set('trust_classification')}>
              {(['none', 'trust', 'management'] as const).map((v) => <option key={v} value={v}>{t(`jobs.trust_${v}`)}</option>)}
            </select>
          ))}
          {field('occupation_code', t('jobs.occupation_code'), <input id="jp-occupation_code" className="form-control" value={form.occupation_code} onChange={set('occupation_code')} />)}
          <div className="form-check mb-3">
            <input id="jp-risk_job" type="checkbox" className="form-check-input" checked={form.risk_job} onChange={(e) => setForm((f) => ({ ...f, risk_job: e.target.checked }))} />
            <label className="form-check-label" htmlFor="jp-risk_job">{t('jobs.risk_job')}</label>
          </div>
        </fieldset>
        {error && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}

function VersionsPanel({ profile, onClose }: { profile: Profile; onClose: () => void }) {
  const { t } = useT()
  const fmt = useDateTime()
  const [versions, setVersions] = useState<Version[] | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    void api.get<{ data: Version[] }>(`/job-profiles/${profile.id}/versions`).then((r) => setVersions(r.data)).catch(() => setFailed(true))
  }, [profile.id])

  return (
    <SidePanel title={t('jobs.history_title', { name: profile.name })} onClose={onClose} closeLabel={t('common.close')}>
      {failed && <p role="alert">{t('jobs.load_error')}</p>}
      {versions === null && !failed && <span className="esq" style={{ width: 240 }} />}
      {versions && (
        <Timeline
          label={t('jobs.history')}
          items={versions.map((v, i) => ({
            id: String(v.version),
            tone: i === 0 ? 'ok' : 'gr',
            title: t('jobs.version_n', { n: v.version }),
            when: `${fmt(v.created_at)}${v.changed_by_name ? ` · ${v.changed_by_name}` : ''}`,
            text: (
              <>
                {v.change_reason && <p><b>{t('jobs.change_reason')}:</b> {v.change_reason}</p>}
                {v.mission && <p><b>{t('jobs.mission')}:</b> {v.mission}</p>}
                {v.functions && <p style={{ whiteSpace: 'pre-line' }}><b>{t('jobs.functions')}:</b> {v.functions}</p>}
                {v.requirements && <p style={{ whiteSpace: 'pre-line' }}><b>{t('jobs.requirements')}:</b> {v.requirements}</p>}
              </>
            ),
          }))}
        />
      )}
    </SidePanel>
  )
}

function CatalogTable({ kind, reloadKey, onChanged, toast }: { kind: Kind; reloadKey: number; onChanged: () => void; toast: Toast }) {
  const { t } = useT()
  const { can } = useSession()
  const [editing, setEditing] = useState<CatalogItem | 'new' | null>(null)
  const transition = useTransition(ENDPOINT[kind], onChanged, toast)

  const columns: Array<TableColumn<CatalogItem>> = [
    ...(kind !== 'family' ? [{ key: 'rank', label: t('jobs.rank'), sort: 'rank', width: 90, render: (r: CatalogItem) => r.rank ?? '—' }] : []),
    { key: 'name', label: t('jobs.col_name'), sort: 'name', hideable: false, width: 260, render: (r) => <><b>{r.name}</b><span className="sub2">{r.code}</span></> },
    { key: 'profiles', label: t('jobs.col_profiles'), render: (r) => r.profiles_count ?? 0 },
    { key: 'status', label: t('jobs.col_status'), sort: 'status', render: (r) => <StatusBadge status={r.status} /> },
  ]

  return (
    <>
      <DataTable<CatalogItem>
        resource={`job_${kind}`}
        endpoint={ENDPOINT[kind]}
        defaultSort={kind === 'family' ? 'name' : 'rank'}
        searchLabel={t('jobs.search')}
        reloadKey={reloadKey}
        rowKey={(r) => r.id}
        columns={columns}
        toolbar={() => can('org.job_profiles.create') && <button type="button" className="btn btn-sec btn-sm" onClick={() => setEditing('new')}><Icon name="plus" /> {t(`jobs.new_${kind}`)}</button>}
        filters={[{ key: 'status', label: t('jobs.filter_status'), options: [{ value: 'active', label: t('jobs.status_active') }, { value: 'archived', label: t('jobs.status_archived') }] }]}
        empty={<EmptyState title={t(`jobs.empty_${kind}`)} text={t('jobs.empty_catalog_text')} />}
        rowActions={(r) => (
          <>
            {r.status === 'active' && can('org.job_profiles.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(r)}>{t('jobs.edit')}</button>}
            {r.allowed_actions?.includes('archive') && can('org.job_profiles.archive') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r.id, r.name, 'archive')}>{t('jobs.archive')}</button>}
            {r.allowed_actions?.includes('restore') && can('org.job_profiles.restore') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r.id, r.name, 'restore')}>{t('jobs.restore')}</button>}
          </>
        )}
      />
      {editing !== null && (
        <CatalogPanel
          kind={kind}
          item={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={(created) => { setEditing(null); toast(t(created ? 'jobs.created' : 'jobs.saved')); onChanged() }}
        />
      )}
    </>
  )
}

function CatalogPanel({ kind, item, onClose, onSaved }: { kind: Kind; item: CatalogItem | null; onClose: () => void; onSaved: (created: boolean) => void }) {
  const { t } = useT()
  const [form, setForm] = useState({
    code: item?.code ?? '',
    name: item?.name ?? '',
    rank: item?.rank !== undefined && item?.rank !== null ? String(item.rank) : '',
    description: item?.description ?? '',
    functions: item?.functions ?? '',
    responsibility_level: item?.responsibility_level ?? '',
    requirements: item?.requirements ?? '',
  })
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const body: Record<string, unknown> = { code: form.code, name: form.name, description: form.description.trim() || null }
    if (kind !== 'family') body.rank = form.rank === '' ? null : Number(form.rank)
    if (kind === 'category') {
      body.functions = form.functions.trim() || null
      body.responsibility_level = form.responsibility_level.trim() || null
      body.requirements = form.requirements.trim() || null
    }
    try {
      if (item) {
        await api.request(`${ENDPOINT[kind]}/${item.id}`, 'PATCH', body, item.version !== undefined ? { 'If-Match': `"${item.version}"` } : undefined)
      } else {
        await api.post(ENDPOINT[kind], body)
      }
      onSaved(item === null)
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      setError(general)
    } finally {
      setBusy(false)
    }
  }

  const field = (key: keyof typeof form, label: string, input: ReactNode) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`jc-${key}`}>{label}</label>
      {input}
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )

  return (
    <SidePanel
      title={t(item ? `jobs.edit_${kind}` : `jobs.new_${kind}`)}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('jobs.cancel')}</button>
        <button type="submit" form="catalogo-puesto" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('jobs.save')}</button>
      </>}
    >
      <form id="catalogo-puesto" onSubmit={submit} noValidate>
        {field('code', t('jobs.code'), <input id="jc-code" className={`form-control${errors.code ? ' is-invalid' : ''}`} value={form.code} onChange={set('code')} required />)}
        {field('name', t('jobs.name'), <input id="jc-name" className={`form-control${errors.name ? ' is-invalid' : ''}`} value={form.name} onChange={set('name')} required />)}
        {kind !== 'family' && field('rank', t('jobs.rank'), <input id="jc-rank" type="number" min={1} className={`form-control${errors.rank ? ' is-invalid' : ''}`} value={form.rank} onChange={set('rank')} required={kind === 'level'} />)}
        {field('description', t('jobs.description'), <textarea id="jc-description" className="form-control" rows={2} value={form.description} onChange={set('description')} />)}
        {kind === 'category' && (
          <>
            {field('functions', t('jobs.functions'), <textarea id="jc-functions" className="form-control" rows={3} value={form.functions} onChange={set('functions')} />)}
            {field('responsibility_level', t('jobs.responsibility'), <input id="jc-responsibility_level" className="form-control" value={form.responsibility_level} onChange={set('responsibility_level')} />)}
            {field('requirements', t('jobs.requirements'), <textarea id="jc-requirements" className="form-control" rows={3} value={form.requirements} onChange={set('requirements')} />)}
          </>
        )}
        {error && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}
