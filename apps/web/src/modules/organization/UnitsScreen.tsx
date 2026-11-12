'use client'

import { orgTabs } from './orgTabs'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useCompany } from '@/modules/session/CompanyProvider'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { MenuButton, type MenuAction } from '@/ui/components/MenuButton'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { Tag } from '@/ui/components/Status'
import { Timeline } from '@/ui/components/Timeline'
import { useToast } from '@/ui/components/Toast'

type Unit = {
  id: string; code: string; name: string; legal_entity_id: string | null; type_id: string; type_name: string | null
  parent_id: string | null; parent_name: string | null; head_position_id: string | null; head_position_title: string | null
  head_person_name: string | null; cost_center_id: string | null; cost_center_name: string | null; depth: number
  children_count: number; valid_from: string | null; valid_to: string | null; status: 'active' | 'archived'
  scheduled_change: { effective_on: string; parent_id: string | null; name: string } | null
}
type UnitType = { id: string; code: string; name: string; rank: number; units_count: number }
type Options = {
  types: UnitType[]
  positions: Array<{ id: string; code: string; title: string; occupant_name: string | null }>
  cost_centers: Array<{ id: string; code: string; name: string }>
}
type Version = {
  id: string; valid_from: string; valid_to: string | null; is_current: boolean; is_scheduled: boolean; name: string
  parent_name: string | null; type_name: string | null; head_position_title: string | null; cost_center_name: string | null
  reason: string | null; changed_by: string | null
}
type Impact = { subunits: number; descendants: number; active_positions: number; people: number }
type Panel = { kind: 'form'; unit: Unit | null; parent: string | null } | { kind: 'move'; unit: Unit } | { kind: 'history'; unit: Unit } | { kind: 'types' } | { kind: 'archive'; unit: Unit }

/**
 * Organización → Unidades (ORG-3): árbol de unidades con vigencia. Ver a una fecha muestra la
 * estructura de ese día; crear, editar y mover registran el cambio desde la fecha indicada.
 */
export function UnitsScreen() {
  const { t, locale } = useT()
  const { can, session } = useSession()
  const company = useCompany()
  const { toast, node: toastNode } = useToast()
  const [asOf, setAsOf] = useState(localToday())
  const [rows, setRows] = useState<Unit[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [options, setOptions] = useState<Options | null>(null)
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set())
  const [query, setQuery] = useState('')
  const [panel, setPanel] = useState<Panel | null>(null)

  const load = useCallback(async () => {
    try {
      const le = company.selectedId ? `&filter[legal_entity_id]=${encodeURIComponent(company.selectedId)}` : ''
      setRows((await api.get<{ data: Unit[] }>(`/org-units?as_of=${encodeURIComponent(asOf)}${le}`)).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [asOf, company.selectedId])
  const loadOptions = useCallback(async () => {
    try { setOptions((await api.get<{ data: Options }>('/org-unit-options')).data) } catch { /* el formulario muestra solo los campos básicos */ }
  }, [])
  useEffect(() => { void load() }, [load])
  useEffect(() => { void loadOptions() }, [loadOptions])

  const isToday = asOf === localToday()
  const visible = useMemo(() => {
    if (!rows) return null
    const q = query.trim().toLowerCase()
    if (q) return rows.filter((r) => `${r.name} ${r.code}`.toLowerCase().includes(q))
    // Plegar ramas: se ocultan los descendientes de una unidad plegada.
    const hidden = new Set<string>()
    return rows.filter((r) => {
      if (r.parent_id && (hidden.has(r.parent_id) || collapsed.has(r.parent_id))) { hidden.add(r.id); return false }
      return true
    })
  }, [rows, query, collapsed])

  const toggle = (id: string) => setCollapsed((s) => { const n = new Set(s); if (n.has(id)) n.delete(id); else n.add(id); return n })

  async function restore(unit: Unit) {
    try {
      await api.post(`/org-units/${unit.id}/restore`, {})
      toast(t('ounit.restored'))
      await load()
    } catch (err) {
      toast(formErrors(err, t).general ?? t('common.load_error'), 'error')
    }
  }

  const actionsFor = (u: Unit): MenuAction[] => {
    const items: MenuAction[] = [{ id: 'history', label: t('ounit.history'), icon: 'calendar', onSelect: () => setPanel({ kind: 'history', unit: u }) }]
    if (!isToday) return items
    if (u.status === 'active') {
      if (can('org.units.create')) items.unshift({ id: 'child', label: t('ounit.add_child'), icon: 'plus', onSelect: () => setPanel({ kind: 'form', unit: null, parent: u.id }) })
      if (can('org.units.update')) items.unshift({ id: 'edit', label: t('ounit.edit'), onSelect: () => setPanel({ kind: 'form', unit: u, parent: u.parent_id }) })
      if (can('org.units.move')) items.push({ id: 'move', label: t('ounit.move'), icon: 'sliders', onSelect: () => setPanel({ kind: 'move', unit: u }) })
      if (can('org.units.archive')) items.push({ id: 'archive', label: t('ounit.archive'), danger: true, onSelect: () => setPanel({ kind: 'archive', unit: u }) })
    } else if (can('org.units.restore')) {
      items.push({ id: 'restore', label: t('ounit.restore'), onSelect: () => void restore(u) })
    }
    return items
  }

  const done = async (msg: string) => { setPanel(null); toast(t(msg)); await Promise.all([load(), loadOptions()]) }

  return (
    <>
      <PageHeader
        title={t('org.title')}
        sub={rows ? t('ounit.sub', { n: rows.length }) : undefined}
        tabs={orgTabs(t, can, 'units', session.modules)}
        actions={<>
          {can('org.units.update') && <button type="button" className="btn btn-sec" onClick={() => setPanel({ kind: 'types' })}>{t('ounit.levels')}</button>}
          {can('org.units.create') && isToday && <button type="button" className="btn btn-primary" onClick={() => setPanel({ kind: 'form', unit: null, parent: null })}><Icon name="plus" /> {t('ounit.new')}</button>}
        </>}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="ou-filtros">
            <div>
              <label className="form-label" htmlFor="ou-as-of">{t('ounit.as_of')}</label>
              <input id="ou-as-of" type="date" className="form-control" value={asOf} onChange={(e) => e.target.value && setAsOf(e.target.value)} />
            </div>
            <div className="ou-buscar">
              <label className="form-label" htmlFor="ou-q">{t('ounit.search')}</label>
              <input id="ou-q" type="search" className="form-control" value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t('ounit.search_ph')} />
            </div>
          </div>
          {!isToday && <div className="nota" style={{ margin: '0 16px 16px' }}>{t('ounit.past_note', { date: formatDate(locale, asOf) })}</div>}
          {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
          {!loadError && visible === null && <div style={{ padding: 16 }}><span className="esq" style={{ width: '40%' }} /></div>}
          {visible?.length === 0 && <EmptyState title={t(query ? 'ounit.no_match' : 'ounit.empty_title')} text={t(query ? 'ounit.no_match_text' : 'ounit.empty_text')} />}
          {visible && visible.length > 0 && (
            <ul className="ou-arbol" role="tree" aria-label={t('ounit.tree')}>
              {visible.map((u) => (
                <li key={u.id} role="treeitem" aria-level={u.depth + 1} aria-expanded={u.children_count > 0 ? !collapsed.has(u.id) : undefined} aria-selected={false} className={u.status === 'archived' ? 'arch' : undefined}>
                  <div className="ou-fila">
                    <div className="ou-cab" style={{ paddingInlineStart: Math.min(u.depth, 8) * 24 }}>
                    {u.children_count > 0 && !query
                      ? <button type="button" className="ibtn ou-pliegue" onClick={() => toggle(u.id)} aria-label={t(collapsed.has(u.id) ? 'ounit.expand' : 'ounit.collapse', { name: u.name })}>
                          <Icon name={collapsed.has(u.id) ? 'chevronRight' : 'chevron'} size={16} />
                        </button>
                      : <span className="ou-pliegue" aria-hidden="true" />}
                    <div className="ou-nombre">
                      <b>{u.name}</b>
                      <span className="sub2">{u.code}{u.type_name ? ` · ${u.type_name}` : ''}{query && u.parent_name ? ` · ${t('ounit.under', { name: u.parent_name })}` : ''}</span>
                    </div>
                    </div>
                    <div className="ou-dato">
                      <span className="sub2">{t('ounit.head')}</span>
                      <span>{u.head_position_title ? `${u.head_position_title}${u.head_person_name ? ` · ${u.head_person_name}` : ''}` : <span className="text-secondary">{t('ounit.no_head')}</span>}</span>
                    </div>
                    <div className="ou-dato">
                      <span className="sub2">{t('ounit.cost_center')}</span>
                      <span>{u.cost_center_name ?? <span className="text-secondary">—</span>}</span>
                    </div>
                    <div className="ou-tags">
                      {u.status === 'archived' && <Tag>{t('ounit.archived_tag')}</Tag>}
                      {u.scheduled_change && <Tag tone="wa">{t('ounit.scheduled', { date: formatDate(locale, u.scheduled_change.effective_on) })}</Tag>}
                    </div>
                    <MenuButton label={t('ounit.actions_for', { name: u.name })} items={actionsFor(u)} icon="more" iconOnly />
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>
      </main>
      {panel?.kind === 'form' && <UnitForm unit={panel.unit} parent={panel.parent} units={rows ?? []} options={options} onClose={() => setPanel(null)} onDone={done} />}
      {panel?.kind === 'move' && <MoveForm unit={panel.unit} units={rows ?? []} onClose={() => setPanel(null)} onDone={done} />}
      {panel?.kind === 'archive' && <ArchiveForm unit={panel.unit} onClose={() => setPanel(null)} onDone={done} />}
      {panel?.kind === 'history' && <History unit={panel.unit} onClose={() => setPanel(null)} />}
      {panel?.kind === 'types' && <Levels types={options?.types ?? null} onClose={() => setPanel(null)} onChanged={async () => { await Promise.all([loadOptions(), load()]) }} />}
      {toastNode}
    </>
  )
}

/** Unidades que pueden ser superior de `unit`: ni ella ni su rama (la API vuelve a validarlo). */
function parentChoices(units: Unit[], unit: Unit | null): Unit[] {
  const active = units.filter((u) => u.status === 'active')
  if (!unit) return active
  const blocked = new Set<string>([unit.id])
  for (const u of active) if (u.parent_id && blocked.has(u.parent_id)) blocked.add(u.id)
  return active.filter((u) => !blocked.has(u.id))
}

function DateField({ id, label, value, onChange, error }: { id: string; label: string; value: string; onChange: (v: string) => void; error?: string }) {
  return (
    <div><label className="form-label" htmlFor={id}>{label}</label>
      <input id={id} type="date" className={`form-control${error ? ' is-invalid' : ''}`} value={value} onChange={(e) => e.target.value && onChange(e.target.value)} />
      {error && <div className="invalid-feedback" role="alert">{error}</div>}</div>
  )
}

function UnitForm({ unit, parent, units, options, onClose, onDone }: { unit: Unit | null; parent: string | null; units: Unit[]; options: Options | null; onClose: () => void; onDone: (msg: string) => Promise<void> }) {
  const { t } = useT()
  const { companies } = useCompany()
  const [form, setForm] = useState({
    code: unit?.code ?? '', name: unit?.name ?? '', type_id: unit?.type_id ?? options?.types[0]?.id ?? '', parent_id: unit?.parent_id ?? parent ?? '',
    legal_entity_id: unit?.legal_entity_id ?? '', head_position_id: unit?.head_position_id ?? '', cost_center_id: unit?.cost_center_id ?? '',
    effective_from: localToday(), reason: '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))
  // Si los niveles llegan después de abrir el formulario, se propone el primero.
  const firstType = options?.types[0]?.id ?? ''
  useEffect(() => { if (firstType) setForm((f) => (f.type_id ? f : { ...f, type_id: firstType })) }, [firstType])
  const nul = (v: string) => (v === '' ? null : v)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setSaving(true)
    const body = {
      code: form.code.trim(), name: form.name.trim(), type_id: form.type_id, parent_id: nul(form.parent_id), legal_entity_id: nul(form.legal_entity_id),
      head_position_id: nul(form.head_position_id), cost_center_id: nul(form.cost_center_id), effective_from: form.effective_from, reason: nul(form.reason.trim()),
    }
    try {
      if (unit) await api.request(`/org-units/${unit.id}`, 'PATCH', body)
      else await api.post('/org-units', body)
      await onDone(unit ? 'ounit.saved' : 'ounit.created')
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.fields)
      setGeneral(r.general)
    } finally {
      setSaving(false)
    }
  }

  const text = (k: 'code' | 'name' | 'reason', label: string) => (
    <div><label className="form-label" htmlFor={`ou-${k}`}>{label}</label>
      <input id={`ou-${k}`} className={`form-control${errors[k] ? ' is-invalid' : ''}`} value={form[k]} onChange={(e) => set(k, e.target.value)} />
      {errors[k] && <div className="invalid-feedback" role="alert">{errors[k]}</div>}</div>
  )
  const select = (k: 'type_id' | 'parent_id' | 'legal_entity_id' | 'head_position_id' | 'cost_center_id', label: string, items: Array<{ id: string; label: string }>, empty?: string) => (
    <div><label className="form-label" htmlFor={`ou-${k}`}>{label}</label>
      <select id={`ou-${k}`} className={`form-select${errors[k] ? ' is-invalid' : ''}`} value={form[k]} onChange={(e) => set(k, e.target.value)}>
        {empty !== undefined && <option value="">{empty}</option>}
        {items.map((i) => <option key={i.id} value={i.id}>{i.label}</option>)}
      </select>
      {errors[k] && <div className="invalid-feedback" role="alert">{errors[k]}</div>}</div>
  )

  return (
    <SidePanel title={unit ? t('ounit.edit_title', { name: unit.name }) : t('ounit.new')} onClose={onClose} closeLabel={t('ounit.cancel')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('ounit.cancel')}</button>
        <button type="submit" form="ou-form" className="btn btn-primary" disabled={saving}>{t('ounit.save')}</button>
      </>}>
      <form id="ou-form" className="param-form" onSubmit={submit} noValidate>
        {general && <div className="alert alert-danger" role="alert">{general}</div>}
        {unit && <div className="nota">{t('ounit.edit_note')}</div>}
        <div className="grid-param" style={{ gridTemplateColumns: '1fr 1fr' }}>
          {text('name', t('ounit.name'))}
          {text('code', t('ounit.code'))}
          {select('type_id', t('ounit.type'), (options?.types ?? []).map((ty) => ({ id: ty.id, label: ty.name })))}
          {select('parent_id', t('ounit.parent'), parentChoices(units, unit).map((u) => ({ id: u.id, label: `${'· '.repeat(Math.min(u.depth, 6))}${u.name}` })), t('ounit.root'))}
          {companies.length > 0 && select('legal_entity_id', t('ounit.company'), companies.map((c) => ({ id: c.id, label: c.name })), t('ounit.all_companies'))}
          {select('head_position_id', t('ounit.head'), (options?.positions ?? []).map((p) => ({ id: p.id, label: p.occupant_name ? `${p.title} · ${p.occupant_name}` : `${p.title} (${p.code})` })), t('ounit.no_head'))}
          {select('cost_center_id', t('ounit.cost_center'), (options?.cost_centers ?? []).map((c) => ({ id: c.id, label: `${c.code} · ${c.name}` })), t('ounit.no_cost_center'))}
          <DateField id="ou-from" label={t('ounit.effective_from')} value={form.effective_from} onChange={(v) => set('effective_from', v)} error={errors.effective_from} />
        </div>
        {options && options.positions.length === 0 && <p className="sub2">{t('ounit.no_positions')}</p>}
        {text('reason', t('ounit.reason'))}
      </form>
    </SidePanel>
  )
}

function useImpact(unitId: string): Impact | null {
  const [impact, setImpact] = useState<Impact | null>(null)
  useEffect(() => { void api.get<{ data: Impact }>(`/org-units/${unitId}/impact`).then((r) => setImpact(r.data)).catch(() => setImpact(null)) }, [unitId])
  return impact
}

function ImpactNote({ impact }: { impact: Impact | null }) {
  const { t } = useT()
  if (!impact) return null
  return <div className="nota">{t('ounit.impact', { subunits: impact.descendants, positions: impact.active_positions, people: impact.people })}</div>
}

function MoveForm({ unit, units, onClose, onDone }: { unit: Unit; units: Unit[]; onClose: () => void; onDone: (msg: string) => Promise<void> }) {
  const { t } = useT()
  const impact = useImpact(unit.id)
  const [parent, setParent] = useState(unit.parent_id ?? '')
  const [date, setDate] = useState(localToday())
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    try {
      await api.post(`/org-units/${unit.id}/move`, { parent_id: parent === '' ? null : parent, effective_on: date, reason: reason.trim() || null })
      await onDone('ounit.moved')
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.fields)
      setGeneral(r.general)
    }
  }

  return (
    <SidePanel title={t('ounit.move_title', { name: unit.name })} onClose={onClose} closeLabel={t('ounit.cancel')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('ounit.cancel')}</button>
        <button type="submit" form="ou-move" className="btn btn-primary">{t('ounit.move')}</button>
      </>}>
      <form id="ou-move" className="param-form" onSubmit={submit} noValidate>
        {general && <div className="alert alert-danger" role="alert">{general}</div>}
        <ImpactNote impact={impact} />
        <div><label className="form-label" htmlFor="ou-mv-parent">{t('ounit.new_parent')}</label>
          <select id="ou-mv-parent" className={`form-select${errors.parent_id ? ' is-invalid' : ''}`} value={parent} onChange={(e) => setParent(e.target.value)}>
            <option value="">{t('ounit.root')}</option>
            {parentChoices(units, unit).map((u) => <option key={u.id} value={u.id}>{`${'· '.repeat(Math.min(u.depth, 6))}${u.name}`}</option>)}
          </select>
          {errors.parent_id && <div className="invalid-feedback" role="alert">{errors.parent_id}</div>}</div>
        <DateField id="ou-mv-date" label={t('ounit.effective_on')} value={date} onChange={setDate} error={errors.effective_on} />
        <p className="sub2">{t('ounit.move_help')}</p>
        <div><label className="form-label" htmlFor="ou-mv-reason">{t('ounit.reason')}</label>
          <input id="ou-mv-reason" className="form-control" value={reason} onChange={(e) => setReason(e.target.value)} /></div>
      </form>
    </SidePanel>
  )
}

function ArchiveForm({ unit, onClose, onDone }: { unit: Unit; onClose: () => void; onDone: (msg: string) => Promise<void> }) {
  const { t } = useT()
  const impact = useImpact(unit.id)
  const [reason, setReason] = useState('')
  const [general, setGeneral] = useState<string | null>(null)
  const blocked = impact !== null && (impact.subunits > 0 || impact.active_positions > 0)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    try {
      await api.post(`/org-units/${unit.id}/archive`, { reason: reason.trim() || null })
      await onDone('ounit.archived')
    } catch (err) {
      setGeneral(formErrors(err, t).general)
    }
  }

  return (
    <SidePanel title={t('ounit.archive_title', { name: unit.name })} onClose={onClose} closeLabel={t('ounit.cancel')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('ounit.cancel')}</button>
        <button type="submit" form="ou-archive" className="btn btn-pel-sec" disabled={blocked}>{t('ounit.archive')}</button>
      </>}>
      <form id="ou-archive" className="param-form" onSubmit={submit} noValidate>
        {general && <div className="alert alert-danger" role="alert">{general}</div>}
        <ImpactNote impact={impact} />
        <p>{t(blocked ? 'ounit.archive_blocked' : 'ounit.archive_help')}</p>
        <div><label className="form-label" htmlFor="ou-ar-reason">{t('ounit.reason')}</label>
          <input id="ou-ar-reason" className="form-control" value={reason} onChange={(e) => setReason(e.target.value)} /></div>
      </form>
    </SidePanel>
  )
}

function History({ unit, onClose }: { unit: Unit; onClose: () => void }) {
  const { t, locale } = useT()
  const [rows, setRows] = useState<Version[] | null>(null)
  const [error, setError] = useState(false)
  useEffect(() => {
    void api.get<{ data: Version[] }>(`/org-units/${unit.id}/history`).then((r) => setRows(r.data)).catch(() => setError(true))
  }, [unit.id])

  return (
    <SidePanel title={t('ounit.history_title', { name: unit.name })} onClose={onClose} closeLabel={t('ounit.close')}>
      {error && <div className="alert alert-danger" role="alert">{t('common.load_error')}</div>}
      {!error && rows === null && <span className="esq" style={{ width: '60%' }} />}
      {rows && (
        <Timeline label={t('ounit.history')} items={rows.map((v) => ({
          id: v.id,
          tone: v.is_scheduled ? 'wa' : v.is_current ? 'ok' : 'gr',
          title: <>{v.name}{v.is_scheduled ? ` · ${t('ounit.scheduled_short')}` : v.is_current ? ` · ${t('ounit.current')}` : ''}</>,
          when: v.valid_to ? t('ounit.range', { from: formatDate(locale, v.valid_from), to: formatDate(locale, v.valid_to) }) : t('ounit.since', { from: formatDate(locale, v.valid_from) }),
          text: <>
            {t('ounit.history_line', { parent: v.parent_name ?? t('ounit.root'), type: v.type_name ?? '—', head: v.head_position_title ?? t('ounit.no_head'), cc: v.cost_center_name ?? '—' })}
            {v.reason && <><br />{t('ounit.reason_value', { reason: v.reason })}</>}
            {v.changed_by && <><br />{t('ounit.by', { name: v.changed_by })}</>}
          </>,
        }))} />
      )}
    </SidePanel>
  )
}

function Levels({ types, onClose, onChanged }: { types: UnitType[] | null; onClose: () => void; onChanged: () => Promise<void> }) {
  const { t } = useT()
  const [names, setNames] = useState<Record<string, string>>({})
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  async function rename(type: UnitType) {
    const value = (names[type.id] ?? type.name).trim()
    if (value === type.name) return
    try {
      await api.request(`/org-unit-types/${type.id}`, 'PATCH', { name: value })
      setGeneral(null)
      await onChanged()
    } catch (err) {
      setGeneral(formErrors(err, t).general)
    }
  }

  async function add(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    try {
      await api.post('/org-unit-types', { code: code.trim(), name: name.trim() })
      setCode('')
      setName('')
      setErrors({})
      setGeneral(null)
      await onChanged()
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.fields)
      setGeneral(r.general)
    }
  }

  return (
    <SidePanel title={t('ounit.levels')} onClose={onClose} closeLabel={t('ounit.close')}>
      <p className="sub2">{t('ounit.levels_help')}</p>
      {general && <div className="alert alert-danger" role="alert">{general}</div>}
      {types === null && <span className="esq" style={{ width: '60%' }} />}
      {types?.map((ty) => (
        <div className="ou-nivel" key={ty.id}>
          <label className="visually-hidden" htmlFor={`ou-lv-${ty.id}`}>{t('ounit.level_name', { code: ty.code })}</label>
          <input id={`ou-lv-${ty.id}`} className="form-control" value={names[ty.id] ?? ty.name}
            onChange={(e) => setNames((n) => ({ ...n, [ty.id]: e.target.value }))} onBlur={() => void rename(ty)} />
          <span className="sub2">{t('ounit.level_units', { n: ty.units_count })}</span>
        </div>
      ))}
      <form className="param-form" onSubmit={add} noValidate style={{ marginTop: 16 }}>
        <b>{t('ounit.add_level')}</b>
        <div className="grid-param" style={{ gridTemplateColumns: '1fr 1fr' }}>
          <div><label className="form-label" htmlFor="ou-lv-name">{t('ounit.name')}</label>
            <input id="ou-lv-name" className={`form-control${errors.name ? ' is-invalid' : ''}`} value={name} onChange={(e) => setName(e.target.value)} />
            {errors.name && <div className="invalid-feedback" role="alert">{errors.name}</div>}</div>
          <div><label className="form-label" htmlFor="ou-lv-code">{t('ounit.level_code')}</label>
            <input id="ou-lv-code" className={`form-control${errors.code ? ' is-invalid' : ''}`} value={code} onChange={(e) => setCode(e.target.value)} />
            {errors.code && <div className="invalid-feedback" role="alert">{errors.code}</div>}</div>
        </div>
        <button type="submit" className="btn btn-sec">{t('ounit.add_level')}</button>
      </form>
    </SidePanel>
  )
}
