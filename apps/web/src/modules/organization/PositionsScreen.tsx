'use client'

import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
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

type Position = Schema<'Position'>
type Occupant = { assignment_id: string; employment_id: string; employee_code: string; person_name: string; valid_from: string; valid_to: string | null; reason: string | null }
type StatusEvent = { id: string; action: string | null; from_status: string | null; to_status: string; reason: string | null; effective_on: string; changed_by_name: string | null; created_at: string }
type LineItem = { position_id: string; code: string; title: string; org_unit_name: string | null; status: string; source: 'position' | 'unit_head'; occupants: Occupant[] }
type Option = { id: string; name?: string; title?: string; code?: string; legal_entity_id?: string | null }
type Options = { legal_entities: Option[]; job_profiles: Option[]; org_units: Option[]; sites: Option[]; cost_centers: Option[]; positions: Option[] }

const STATUSES = ['proposed', 'approved', 'open', 'recruiting', 'filled', 'frozen', 'closed'] as const
const TONE: Record<string, string> = { proposed: 'in', approved: 'in', open: 'wa', recruiting: 'in', filled: 'ok', frozen: 'gr', closed: 'gr' }
/** Acción de la API → permiso que la habilita (la API vuelve a validarlo). */
const ACTION_PERMISSION: Record<string, string> = {
  approve: 'org.positions.approve',
  reject: 'org.positions.approve',
  'open-recruitment': 'org.positions.update',
  'cancel-recruitment': 'org.positions.update',
  freeze: 'org.positions.freeze',
  unfreeze: 'org.positions.unfreeze',
  close: 'org.positions.close',
}
/** Acciones que piden motivo (la API lo exige). */
const NEEDS_REASON = ['reject', 'freeze', 'close']

type Toast = (text: string, kind?: 'ok' | 'error') => void

/**
 * Organización → Posiciones (POS-1 · POS-2): plazas con puesto, unidad, sede y jefe; ocupantes derivados de
 * las asignaciones vigentes; estados solo por acciones (aprobar, congelar, abrir reclutamiento, cerrar…).
 */
export function PositionsScreen() {
  const { t } = useT()
  const { can, session } = useSession()
  const { toast, node: toastNode } = useToast()
  const [options, setOptions] = useState<Options | null>(null)
  const [reloadKey, setReloadKey] = useState(0)
  const [editing, setEditing] = useState<Position | 'new' | null>(null)
  const [detail, setDetail] = useState<Position | null>(null)
  const [acting, setActing] = useState<{ position: Position; action: string } | null>(null)

  useEffect(() => {
    void api.get<{ data: Options }>('/position-options').then((r) => setOptions(r.data)).catch(() => toast(t('pos.load_error'), 'error'))
  }, [reloadKey, t, toast])

  const reload = () => setReloadKey((k) => k + 1)

  const columns: Array<TableColumn<Position>> = [
    { key: 'title', label: t('pos.col_title'), sort: 'title', hideable: false, width: 240, render: (r) => <><b>{r.title}</b><span className="sub2">{r.code}</span></> },
    { key: 'job', label: t('pos.col_job'), render: (r) => r.job_profile_name ?? '—' },
    { key: 'unit', label: t('pos.col_unit'), render: (r) => r.org_unit_name ?? '—' },
    { key: 'occupants', label: t('pos.col_occupants'), render: (r) => <Occupants position={r} /> },
    { key: 'reports_to', label: t('pos.col_reports_to'), hiddenByDefault: true, render: (r) => r.reports_to_title ?? '—' },
    { key: 'site', label: t('pos.col_site'), hiddenByDefault: true, render: (r) => r.site_name ?? '—' },
    { key: 'valid_from', label: t('pos.col_valid_from'), sort: 'valid_from', hiddenByDefault: true, render: (r) => r.valid_from },
    ...(can('org.positions.budget.view') ? [{ key: 'budget', label: t('pos.col_budget'), sort: 'budget', render: (r: Position) => <Money value={r.budget} /> }] : []),
    { key: 'status', label: t('pos.col_status'), sort: 'status', render: (r) => <StatusBadge status={r.status} /> },
  ]

  const filters: TableFilter[] = [
    { key: 'status', label: t('pos.filter_status'), options: STATUSES.map((s) => ({ value: s, label: t(`pos.status_${s}`) })) },
    ...(options && options.org_units.length > 0 ? [{ key: 'org_unit_id', label: t('pos.col_unit'), options: options.org_units.map((u) => ({ value: u.id, label: u.name ?? '' })) }] : []),
    ...(options && options.job_profiles.length > 0 ? [{ key: 'job_profile_id', label: t('pos.col_job'), options: options.job_profiles.map((p) => ({ value: p.id, label: p.name ?? '' })) }] : []),
  ]

  return (
    <>
      <PageHeader title={t('org.title')} sub={t('pos.sub')} tabs={orgTabs(t, can, 'positions', session.modules)} />
      <main className="main" id="contenido" tabIndex={-1}>
        <DataTable<Position>
          resource="positions"
          endpoint="/positions"
          defaultSort="code"
          searchLabel={t('pos.search')}
          reloadKey={reloadKey}
          rowKey={(r) => r.id}
          columns={columns}
          filters={filters}
          toolbar={() => can('org.positions.create') && <button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing('new')}><Icon name="plus" /> {t('pos.new')}</button>}
          empty={<EmptyState title={t('pos.empty_title')} text={t('pos.empty_text')} />}
          rowActions={(r) => (
            <>
              <button type="button" className="btn btn-ter btn-sm" onClick={() => setDetail(r)}>{t('pos.detail')}</button>
              {r.status !== 'closed' && can('org.positions.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(r)}>{t('pos.edit')}</button>}
              {(r.allowed_actions ?? []).filter((a) => can(ACTION_PERMISSION[a] ?? '')).map((a) => (
                <button key={a} type="button" className="btn btn-ter btn-sm" onClick={() => setActing({ position: r, action: a })}>{t(`pos.action_${a.replace('-', '_')}`)}</button>
              ))}
            </>
          )}
        />
      </main>
      {editing !== null && options && (
        <PositionPanel
          position={editing === 'new' ? null : editing}
          options={options}
          onClose={() => setEditing(null)}
          onSaved={(created) => { setEditing(null); toast(t(created ? 'pos.created' : 'pos.saved')); reload() }}
        />
      )}
      {detail && <DetailPanel position={detail} onClose={() => setDetail(null)} />}
      {acting && (
        <ActionPanel
          position={acting.position}
          action={acting.action}
          onClose={() => setActing(null)}
          onDone={() => { setActing(null); toast(t('pos.action_done')); reload() }}
          toast={toast}
        />
      )}
      {toastNode}
    </>
  )
}

function StatusBadge({ status }: { status: string }) {
  const { t } = useT()
  return <span className={`st ${TONE[status] ?? 'gr'}`}>{t(`pos.status_${status}`)}</span>
}

function Money({ value }: { value?: { amount: string; currency: string } | null }) {
  const { locale } = useT()
  if (!value) return <>—</>
  // Solo formato: el monto viene exacto de la API (regla 12).
  const [int, dec = ''] = value.amount.split('.')
  const grouped = new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(BigInt(int ?? '0'))
  return <>{value.currency} {grouped}{dec ? `${new Intl.NumberFormat(locale).formatToParts(1.1).find((p) => p.type === 'decimal')?.value ?? '.'}${dec.padEnd(2, '0')}` : ''}</>
}

function Occupants({ position }: { position: Position }) {
  const { t } = useT()
  const list = (position.occupants ?? []) as Occupant[]
  const seats = position.seats ?? 1
  if (list.length === 0) return <span className="text-secondary">{t('pos.vacant', { seats })}</span>
  return (
    <>
      {list.map((o) => <div key={o.assignment_id}>{o.person_name}</div>)}
      {seats > 1 && <span className="sub2">{t('pos.seats_used', { used: list.length, seats })}</span>}
    </>
  )
}

function PositionPanel({ position, options, onClose, onSaved }: { position: Position | null; options: Options; onClose: () => void; onSaved: (created: boolean) => void }) {
  const { t } = useT()
  const { can } = useSession()
  const [form, setForm] = useState({
    code: position?.code ?? '',
    title: position?.title ?? '',
    job_profile_id: position?.job_profile_id ?? '',
    legal_entity_id: position?.legal_entity_id ?? (options.legal_entities[0]?.id ?? ''),
    org_unit_id: position?.org_unit_id ?? '',
    site_id: position?.site_id ?? '',
    cost_center_id: position?.cost_center_id ?? '',
    reports_to_position_id: position?.reports_to_position_id ?? '',
    valid_from: position?.valid_from ?? new Date().toISOString().slice(0, 10),
    valid_to: position?.valid_to ?? '',
    is_shared: Boolean(position?.is_shared),
    seats: String(position?.seats ?? 1),
    is_critical: Boolean(position?.is_critical),
    budget_amount: position?.budget?.amount ?? '',
    budget_currency: position?.budget?.currency ?? 'PEN',
  })
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const canBudget = can('org.positions.budget.view')
  // Unidades, sedes y centros de la empresa elegida (o compartidos).
  const ofEntity = (list: Option[]) => list.filter((o) => !o.legal_entity_id || o.legal_entity_id === form.legal_entity_id)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const nullable = (v: string) => (v.trim() === '' ? null : v.trim())
    const body: Record<string, unknown> = {
      code: form.code, title: form.title, job_profile_id: form.job_profile_id, legal_entity_id: form.legal_entity_id,
      org_unit_id: form.org_unit_id, site_id: nullable(form.site_id), cost_center_id: nullable(form.cost_center_id),
      reports_to_position_id: nullable(form.reports_to_position_id), valid_from: form.valid_from, valid_to: nullable(form.valid_to),
      is_shared: form.is_shared, seats: form.is_shared ? Number(form.seats) : 1, is_critical: form.is_critical,
    }
    if (canBudget) body.budget = form.budget_amount.trim() === '' ? null : { amount: form.budget_amount.trim(), currency: form.budget_currency }
    try {
      if (position) {
        await api.request(`/positions/${position.id}`, 'PATCH', body, position.version !== undefined ? { 'If-Match': `"${position.version}"` } : undefined)
      } else {
        await api.post('/positions', body)
      }
      onSaved(position === null)
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      setError(general)
    } finally {
      setBusy(false)
    }
  }

  const field = (key: string, label: string, input: ReactNode, help?: string) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`ps-${key}`}>{label}</label>
      {input}
      {help && <p className="small text-secondary">{help}</p>}
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )
  const select = (key: keyof typeof form, list: Option[], optional = true) => (
    <select id={`ps-${key}`} className={`form-select${errors[key] ? ' is-invalid' : ''}`} value={String(form[key])} onChange={set(key)}>
      <option value="">{t(optional ? 'pos.none' : 'pos.choose')}</option>
      {list.filter((o) => o.id !== position?.id).map((o) => <option key={o.id} value={o.id}>{o.name ?? o.title}{o.code && o.title ? ` · ${o.code}` : ''}</option>)}
    </select>
  )

  return (
    <SidePanel
      title={t(position ? 'pos.form_edit' : 'pos.form_new')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('pos.cancel')}</button>
        <button type="submit" form="posicion" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('pos.save')}</button>
      </>}
    >
      <form id="posicion" onSubmit={submit} noValidate>
        {field('code', t('pos.code'), <input id="ps-code" className={`form-control${errors.code ? ' is-invalid' : ''}`} value={form.code} onChange={set('code')} required />)}
        {field('title', t('pos.title_field'), <input id="ps-title" className={`form-control${errors.title ? ' is-invalid' : ''}`} value={form.title} onChange={set('title')} required />)}
        {field('job_profile_id', t('pos.col_job'), select('job_profile_id', options.job_profiles, false), t('pos.job_help'))}
        {field('legal_entity_id', t('pos.company'), select('legal_entity_id', options.legal_entities, false))}
        {field('org_unit_id', t('pos.col_unit'), select('org_unit_id', ofEntity(options.org_units), false))}
        {field('site_id', t('pos.col_site'), select('site_id', ofEntity(options.sites)))}
        {field('cost_center_id', t('pos.cost_center'), select('cost_center_id', ofEntity(options.cost_centers)))}
        {field('reports_to_position_id', t('pos.col_reports_to'), select('reports_to_position_id', options.positions), t('pos.reports_to_help'))}
        <div className="row g-2">
          <div className="col-6">{field('valid_from', t('pos.col_valid_from'), <input id="ps-valid_from" type="date" className={`form-control${errors.valid_from ? ' is-invalid' : ''}`} value={form.valid_from} onChange={set('valid_from')} />)}</div>
          <div className="col-6">{field('valid_to', t('pos.valid_to'), <input id="ps-valid_to" type="date" className={`form-control${errors.valid_to ? ' is-invalid' : ''}`} value={form.valid_to} onChange={set('valid_to')} />)}</div>
        </div>
        <div className="form-check mb-2">
          <input id="ps-is_shared" type="checkbox" className="form-check-input" checked={form.is_shared} onChange={(e) => setForm((f) => ({ ...f, is_shared: e.target.checked, seats: e.target.checked ? f.seats : '1' }))} />
          <label className="form-check-label" htmlFor="ps-is_shared">{t('pos.shared')}</label>
        </div>
        {form.is_shared && field('seats', t('pos.seats'), <input id="ps-seats" type="number" min={1} max={500} className={`form-control${errors.seats ? ' is-invalid' : ''}`} value={form.seats} onChange={set('seats')} />, t('pos.seats_help'))}
        {!form.is_shared && errors.seats && <div className="invalid-feedback" role="alert">{errors.seats}</div>}
        <div className="form-check mb-3">
          <input id="ps-is_critical" type="checkbox" className="form-check-input" checked={form.is_critical} onChange={(e) => setForm((f) => ({ ...f, is_critical: e.target.checked }))} />
          <label className="form-check-label" htmlFor="ps-is_critical">{t('pos.critical')}</label>
        </div>
        {canBudget && (
          <fieldset className="cf-grupo">
            <legend>{t('pos.budget')}</legend>
            <div className="row g-2">
              <div className="col-8">{field('budget', t('pos.budget_amount'), <input id="ps-budget" inputMode="decimal" className={`form-control${errors.budget || errors['budget.amount'] ? ' is-invalid' : ''}`} value={form.budget_amount} onChange={set('budget_amount')} />)}</div>
              <div className="col-4">{field('budget_currency', t('pos.budget_currency'), <input id="ps-budget_currency" maxLength={3} className="form-control" value={form.budget_currency} onChange={(e) => setForm((f) => ({ ...f, budget_currency: e.target.value.toUpperCase() }))} />)}</div>
            </div>
          </fieldset>
        )}
        {error && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}

function ActionPanel({ position, action, onClose, onDone, toast }: { position: Position; action: string; onClose: () => void; onDone: () => void; toast: Toast }) {
  const { t } = useT()
  const [reason, setReason] = useState('')
  const [effectiveOn, setEffectiveOn] = useState(new Date().toISOString().slice(0, 10))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const needsReason = NEEDS_REASON.includes(action)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.post(`/positions/${position.id}/${action}`, { reason: reason.trim() || null, effective_on: effectiveOn })
      onDone()
    } catch (err) {
      const message = err instanceof ApiRequestError ? err.message : t('auth.generic_error')
      setError(message)
      toast(message, 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel
      title={t(`pos.action_${action.replace('-', '_')}`)}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('pos.cancel')}</button>
        <button type="submit" form="accion-posicion" className="btn btn-primary" disabled={busy || (needsReason && reason.trim() === '')}>{busy && <span className="spin" aria-hidden="true" />}{t('pos.confirm')}</button>
      </>}
    >
      <form id="accion-posicion" onSubmit={submit} noValidate>
        <p>{t('pos.action_on', { title: position.title, code: position.code })}</p>
        <div className="mb-3">
          <label className="form-label" htmlFor="pa-reason">{t(needsReason ? 'pos.reason_required' : 'pos.reason_optional')}</label>
          <textarea id="pa-reason" className="form-control" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="pa-effective">{t('pos.effective_on')}</label>
          <input id="pa-effective" type="date" className="form-control" value={effectiveOn} onChange={(e) => setEffectiveOn(e.target.value)} />
        </div>
        {error && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}

function DetailPanel({ position, onClose }: { position: Position; onClose: () => void }) {
  const { t } = useT()
  const fmt = useDateTime()
  const [tab, setTab] = useState('occupants')
  const [occupants, setOccupants] = useState<Occupant[] | null>(null)
  const [events, setEvents] = useState<StatusEvent[] | null>(null)
  const [line, setLine] = useState<LineItem[] | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    void Promise.all([
      api.get<{ data: Occupant[] }>(`/positions/${position.id}/occupants`),
      api.get<{ data: StatusEvent[] }>(`/positions/${position.id}/status-events`),
      api.get<{ data: LineItem[] }>(`/positions/${position.id}/reporting-line`),
    ]).then(([o, e, l]) => { setOccupants(o.data); setEvents(e.data); setLine(l.data) }).catch(() => setFailed(true))
  }, [position.id])

  const loading = !failed && (occupants === null || events === null || line === null)

  return (
    <SidePanel title={position.title} onClose={onClose} closeLabel={t('common.close')}>
      <dl className="ficha mb-3">
        <dt>{t('pos.code')}</dt><dd>{position.code}</dd>
        <dt>{t('pos.col_status')}</dt><dd><StatusBadge status={position.status} /></dd>
        <dt>{t('pos.col_job')}</dt><dd>{position.job_profile_name ?? '—'}</dd>
        <dt>{t('pos.col_unit')}</dt><dd>{position.org_unit_name ?? '—'}</dd>
        <dt>{t('pos.col_site')}</dt><dd>{position.site_name ?? '—'}</dd>
        <dt>{t('pos.seats')}</dt><dd>{t('pos.seats_used', { used: position.occupied_seats ?? 0, seats: position.seats ?? 1 })}</dd>
        {(position.heads_units ?? []).length > 0 && <><dt>{t('pos.heads')}</dt><dd>{(position.heads_units as Array<{ id: string; name: string }>).map((u) => u.name).join(', ')}</dd></>}
      </dl>
      {failed && <p role="alert">{t('pos.load_error')}</p>}
      {loading && <span className="esq" style={{ width: 240 }} />}
      {!loading && !failed && (
        <Tabs
          label={t('pos.detail')}
          value={tab}
          onChange={setTab}
          items={[
            {
              id: 'occupants',
              label: t('pos.tab_occupants'),
              content: (occupants ?? []).length === 0 ? <p className="text-secondary">{t('pos.no_occupants')}</p> : (
                <Timeline
                  label={t('pos.tab_occupants')}
                  items={(occupants ?? []).map((o) => ({
                    id: o.assignment_id,
                    tone: o.valid_to === null ? 'ok' : 'gr',
                    title: `${o.person_name} · ${o.employee_code}`,
                    when: o.valid_to ? t('pos.period', { from: o.valid_from, to: o.valid_to }) : t('pos.since', { from: o.valid_from }),
                    text: o.reason ?? undefined,
                  }))}
                />
              ),
            },
            {
              id: 'line',
              label: t('pos.tab_line'),
              content: (line ?? []).length === 0 ? <p className="text-secondary">{t('pos.no_line')}</p> : (
                <ol className="mb-0">
                  {(line ?? []).map((l) => (
                    <li key={l.position_id}>
                      <b>{l.title}</b> <span className="sub2">{l.org_unit_name ?? ''}{l.source === 'unit_head' ? ` · ${t('pos.via_unit_head')}` : ''}</span>
                      <div>{l.occupants.length > 0 ? l.occupants.map((o) => o.person_name).join(', ') : t('pos.vacant', { seats: 1 })}</div>
                    </li>
                  ))}
                </ol>
              ),
            },
            {
              id: 'events',
              label: t('pos.tab_events'),
              content: (
                <Timeline
                  label={t('pos.tab_events')}
                  items={(events ?? []).map((ev) => ({
                    id: ev.id,
                    tone: ev.to_status === 'filled' ? 'ok' : ev.to_status === 'closed' || ev.to_status === 'frozen' ? 'gr' : 'wa',
                    title: `${t(`pos.event_${(ev.action ?? 'create').replace('-', '_')}`)} → ${t(`pos.status_${ev.to_status}`)}`,
                    when: `${fmt(ev.created_at)}${ev.changed_by_name ? ` · ${ev.changed_by_name}` : ` · ${t('pos.system')}`}`,
                    text: ev.reason ? <p>{ev.reason}</p> : undefined,
                  }))}
                />
              ),
            },
          ]}
        />
      )}
    </SidePanel>
  )
}
