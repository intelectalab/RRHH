'use client'

import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { orgTabs } from './orgTabs'

type Site = Schema<'Site'>
type Company = { id: string; name: string; status: string }
type Calendar = { id: string; name: string }

const KINDS = ['headquarters', 'branch', 'plant', 'store', 'site', 'remote'] as const

/** ORG-2 · Organización → Sedes: sedes, sucursales y ubicaciones con geocerca, zona horaria, feriados y centro de riesgo. */
export function SitesScreen() {
  const { t } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<Site[] | null>(null)
  const [companies, setCompanies] = useState<Company[]>([])
  const [calendars, setCalendars] = useState<Calendar[]>([])
  const [editing, setEditing] = useState<Site | 'new' | null>(null)
  const [loadError, setLoadError] = useState(false)

  const load = useCallback(async () => {
    try {
      const res = await api.get<{ data: Site[] }>('/sites?per_page=100')
      setRows(res.data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [])

  useEffect(() => {
    void load()
    void api.get<{ data: Company[] }>('/legal-entities?per_page=100').then((r) => setCompanies(r.data)).catch(() => setCompanies([]))
    if (can('time.holiday_calendars.view')) void api.get<{ data: Calendar[] }>('/holiday-calendars').then((r) => setCalendars(r.data)).catch(() => setCalendars([]))
  }, [load, can])

  const companyName = (id?: string | null) => (id ? companies.find((c) => c.id === id)?.name ?? '—' : t('sites.shared'))
  const active = rows?.filter((r) => r.status === 'active').length ?? 0

  async function transition(row: Site, action: 'archive' | 'restore') {
    if (action === 'archive' && !window.confirm(t('sites.confirm_archive', { name: row.name }))) return
    try {
      await api.post(`/sites/${row.id}/${action}`, {})
      toast(t(action === 'archive' ? 'sites.archived' : 'sites.restored'))
      await load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  return (
    <>
      <PageHeader
        title={t('org.title')}
        sub={rows ? t('sites.sub', { active, total: rows.length }) : undefined}
        tabs={orgTabs(t, can, 'sites')}
        actions={can('org.sites.create') && (
          <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('sites.new')}</button>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="tabla-wrap">
            <table className="table">
              <thead>
                <tr><th>{t('sites.col_name')}</th><th>{t('sites.col_company')}</th><th>{t('sites.col_kind')}</th><th>{t('sites.col_location')}</th><th>{t('sites.col_timezone')}</th><th>{t('sites.col_status')}</th><th /></tr>
              </thead>
              <tbody>
                {loadError && <tr><td colSpan={7}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                {!loadError && rows === null && [1, 2].map((i) => <tr key={i}><td colSpan={7}><span className="esq" style={{ width: `${30 + i * 20}%` }} /></td></tr>)}
                {rows?.length === 0 && (
                  <tr><td colSpan={7}>
                    <EmptyState title={t('sites.empty_title')} text={t('sites.empty_text')} action={can('org.sites.create') && (
                      <button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing('new')}><Icon name="plus" /> {t('sites.new')}</button>
                    )} />
                  </td></tr>
                )}
                {rows?.map((r) => (
                  <tr key={r.id}>
                    <td>
                      <b>{r.name}</b>
                      <span className="sub2">{r.code}{r.risk_site ? ` · ${t('sites.risk_badge')}` : ''}</span>
                    </td>
                    <td>{companyName(r.legal_entity_id)}</td>
                    <td>{t(`sites.kind_${r.kind}`)}</td>
                    <td>
                      {r.address ?? '—'}
                      {r.latitude != null && r.longitude != null && (
                        <span className="sub2">{t('sites.geofence_summary', { radius: r.geofence_radius_m ?? '—' })}</span>
                      )}
                    </td>
                    <td>{r.effective_timezone ?? r.timezone ?? '—'}</td>
                    <td><span className={`st ${r.status === 'active' ? 'ok' : 'gr'}`}>{t(r.status === 'active' ? 'sites.status_active' : 'sites.status_archived')}</span></td>
                    <td className="text-end nowrap">
                      {r.status === 'active' && can('org.sites.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(r)}>{t('org.edit')}</button>}
                      {r.allowed_actions?.includes('archive') && can('org.sites.archive') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r, 'archive')}>{t('org.archive')}</button>}
                      {r.allowed_actions?.includes('restore') && can('org.sites.restore') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r, 'restore')}>{t('org.restore')}</button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
      {editing !== null && (
        <SitePanel
          site={editing === 'new' ? null : editing}
          companies={companies.filter((c) => c.status === 'active')}
          calendars={calendars}
          onClose={() => setEditing(null)}
          onSaved={async (created) => {
            setEditing(null)
            toast(t(created ? 'sites.created' : 'sites.saved'))
            await load()
          }}
        />
      )}
      {toastNode}
    </>
  )
}

function SitePanel({ site, companies, calendars, onClose, onSaved }: { site: Site | null; companies: Company[]; calendars: Calendar[]; onClose: () => void; onSaved: (created: boolean) => Promise<void> }) {
  const { t } = useT()
  const [form, setForm] = useState({
    legal_entity_id: site?.legal_entity_id ?? companies[0]?.id ?? '',
    code: site?.code ?? '',
    name: site?.name ?? '',
    kind: site?.kind ?? 'branch',
    address: site?.address ?? '',
    district_code: site?.district_code ?? '',
    latitude: site?.latitude != null ? String(site.latitude) : '',
    longitude: site?.longitude != null ? String(site.longitude) : '',
    geofence_radius_m: site?.geofence_radius_m != null ? String(site.geofence_radius_m) : '',
    timezone: site?.timezone ?? '',
    holiday_calendar_id: site?.holiday_calendar_id ?? '',
    establishment_code: site?.establishment_code ?? '',
  })
  const [risk, setRisk] = useState(Boolean(site?.risk_site))
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const text = (v: string) => v.trim() || null
    const num = (v: string) => (v.trim() === '' ? null : Number(v))
    const body = {
      legal_entity_id: text(form.legal_entity_id),
      code: form.code.trim(),
      name: form.name.trim(),
      kind: form.kind,
      address: text(form.address),
      district_code: text(form.district_code),
      latitude: num(form.latitude),
      longitude: num(form.longitude),
      geofence_radius_m: num(form.geofence_radius_m),
      timezone: text(form.timezone),
      holiday_calendar_id: text(form.holiday_calendar_id),
      establishment_code: text(form.establishment_code),
      risk_site: risk,
    }
    try {
      if (site) await api.request(`/sites/${site.id}`, 'PATCH', body, site.version != null ? { 'If-Match': `"${site.version}"` } : undefined)
      else await api.post('/sites', body)
      await onSaved(site === null)
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
      <label className="form-label" htmlFor={`s-${key}`}>{label}</label>
      {input}
      {help && <p className="small text-secondary">{help}</p>}
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )
  const input = (key: keyof typeof form, extra: Record<string, string> = {}) => (
    <input id={`s-${key}`} className={`form-control${errors[key] ? ' is-invalid' : ''}`} value={form[key]} onChange={set(key)} {...extra} />
  )

  return (
    <SidePanel
      title={t(site ? 'sites.form_edit' : 'sites.form_new')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('org.cancel')}</button>
        <button type="submit" form="sede" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('org.save')}</button>
      </>}
    >
      <form id="sede" onSubmit={submit} noValidate>
        {field('legal_entity_id', t('sites.company'), (
          <select id="s-legal_entity_id" className={`form-select${errors.legal_entity_id ? ' is-invalid' : ''}`} value={form.legal_entity_id} onChange={set('legal_entity_id')}>
            <option value="">{t('sites.shared')}</option>
            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        ))}
        {field('code', t('sites.code'), input('code', { required: 'required', maxLength: '20' }))}
        {field('name', t('sites.name'), input('name', { required: 'required' }))}
        {field('kind', t('sites.kind'), (
          <select id="s-kind" className="form-select" value={form.kind} onChange={set('kind')}>
            {KINDS.map((k) => <option key={k} value={k}>{t(`sites.kind_${k}`)}</option>)}
          </select>
        ))}

        <fieldset className="cf-grupo">
          <legend>{t('sites.section_location')}</legend>
          {field('address', t('sites.address'), input('address', { autoComplete: 'street-address' }))}
          {field('district_code', t('org.district_code'), input('district_code', { inputMode: 'numeric' }), t('org.district_help'))}
          <div className="row g-2">
            <div className="col-6">{field('latitude', t('sites.latitude'), input('latitude', { inputMode: 'decimal' }))}</div>
            <div className="col-6">{field('longitude', t('sites.longitude'), input('longitude', { inputMode: 'decimal' }))}</div>
          </div>
          {field('geofence_radius_m', t('sites.radius'), input('geofence_radius_m', { inputMode: 'numeric' }), t('sites.radius_help'))}
        </fieldset>

        <fieldset className="cf-grupo">
          <legend>{t('sites.section_rules')}</legend>
          {field('timezone', t('sites.timezone'), input('timezone', { placeholder: 'America/Lima' }), t('sites.timezone_help'))}
          {field('holiday_calendar_id', t('sites.calendar'), (
            <select id="s-holiday_calendar_id" className={`form-select${errors.holiday_calendar_id ? ' is-invalid' : ''}`} value={form.holiday_calendar_id} onChange={set('holiday_calendar_id')}>
              <option value="">{t('sites.calendar_default')}</option>
              {calendars.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          ))}
          {field('establishment_code', t('sites.establishment'), input('establishment_code', { inputMode: 'numeric', maxLength: '4' }), t('sites.establishment_help'))}
          <div className="form-check mb-3">
            <input id="s-risk" type="checkbox" className="form-check-input" checked={risk} onChange={(e) => setRisk(e.target.checked)} />
            <label className="form-check-label" htmlFor="s-risk">{t('sites.risk')}</label>
            <p className="small text-secondary">{t('sites.risk_help')}</p>
          </div>
        </fieldset>
        {error && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}
