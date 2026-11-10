'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type Field = { id: string; entity: string; key: string; label: string; type: string; options: string[] | null; required: boolean; sensitive: boolean; permission: string | null; section: string | null; sort_order: number; values_count: number }
const TYPES = ['text', 'number', 'date', 'select', 'multiselect', 'checkbox', 'user', 'file', 'url', 'currency', 'percent'] as const

/** Configuración → Campos personalizados (CFG-4). */
export function CustomFieldsScreen() {
  const { t } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [entities, setEntities] = useState<string[]>([])
  const [permissions, setPermissions] = useState<string[]>([])
  const [entity, setEntity] = useState('legal_entity')
  const [rows, setRows] = useState<Field[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [editing, setEditing] = useState<Field | 'new' | null>(null)

  const load = useCallback(async () => {
    try {
      const res = await api.get<{ data: Field[]; meta: { entities: string[]; field_permissions: string[] } }>(`/custom-fields?filter[entity]=${entity}`)
      setRows(res.data)
      setEntities(res.meta.entities)
      setPermissions(res.meta.field_permissions)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [entity])
  useEffect(() => { void load() }, [load])

  async function deactivate(f: Field) {
    if (!window.confirm(t('cf.confirm_deactivate', { label: f.label }))) return
    try {
      await api.request(`/custom-fields/${f.id}`, 'DELETE')
      toast(t('cf.deactivated'))
      await load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  const entityLabel = (e: string) => (t(`cf.entity_${e}`) === `cf.entity_${e}` ? e : t(`cf.entity_${e}`))

  return (
    <>
      <PageHeader title={t('cf.title')} sub={t('cf.sub')} actions={<>
        <Link className="btn btn-sec" href="/configuracion">{t('cfg.back')}</Link>
        {can('settings.custom_fields.create') && <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('cf.new')}</button>}
      </>} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="barra-filtros">
          <div className="seg" role="group" aria-label={t('cf.entity')}>
            {(entities.length ? entities : [entity]).map((e) => <button key={e} type="button" className={e === entity ? 'on' : undefined} aria-pressed={e === entity} onClick={() => setEntity(e)}>{entityLabel(e)}</button>)}
          </div>
        </div>
        <section className="card">
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th>{t('cf.col_label')}</th><th>{t('cf.col_type')}</th><th>{t('cf.col_rules')}</th><th>{t('cf.col_values')}</th><th /></tr></thead>
              <tbody>
                {loadError && <tr><td colSpan={5}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                {!loadError && rows === null && <tr><td colSpan={5}><span className="esq" style={{ width: '40%' }} /></td></tr>}
                {rows?.length === 0 && <tr><td colSpan={5}><EmptyState title={t('cf.empty_title')} text={t('cf.empty_text')} /></td></tr>}
                {rows?.map((f) => (
                  <tr key={f.id}>
                    <td><b>{f.label}</b><span className="sub2"><code>{f.key}</code>{f.section ? ` · ${f.section}` : ''}</span></td>
                    <td>{t(`cf.type_${f.type}`)}{f.options && f.options.length > 0 && <span className="sub2">{f.options.join(', ')}</span>}</td>
                    <td><div className="tags" style={{ marginTop: 0 }}>{f.required && <span className="tag in">{t('cf.required')}</span>}{f.sensitive && <span className="tag pe">{t('cf.sensitive')}</span>}</div></td>
                    <td>{t('cf.values_n', { n: f.values_count })}</td>
                    <td className="text-end nowrap">
                      {can('settings.custom_fields.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(f)}>{t('common.edit')}</button>}
                      {can('settings.custom_fields.delete') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void deactivate(f)}>{t('cf.deactivate')}</button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
      {editing !== null && <FieldPanel field={editing === 'new' ? null : editing} entity={entity} permissions={permissions} onClose={() => setEditing(null)} onSaved={async (created) => { setEditing(null); toast(t(created ? 'cf.created' : 'cf.saved')); await load() }} />}
      {toastNode}
    </>
  )
}

function FieldPanel({ field, entity, permissions, onClose, onSaved }: { field: Field | null; entity: string; permissions: string[]; onClose: () => void; onSaved: (created: boolean) => Promise<void> }) {
  const { t } = useT()
  const [form, setForm] = useState({
    label: field?.label ?? '', key: field?.key ?? '', type: field?.type ?? 'text', options: (field?.options ?? []).join('\n'),
    required: field?.required ?? false, sensitive: field?.sensitive ?? false, permission: field?.permission ?? '', section: field?.section ?? '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const typeLocked = (field?.values_count ?? 0) > 0
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))

  // Clave sugerida a partir del nombre (sin tildes, minúsculas y guion bajo).
  const suggestKey = (label: string) => label.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'c_$1').slice(0, 40)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const options = ['select', 'multiselect'].includes(form.type) ? form.options.split('\n').map((s) => s.trim()).filter(Boolean) : null
    const body = { label: form.label, type: form.type, options, required: form.required, sensitive: form.sensitive, permission: form.sensitive ? form.permission || null : null, section: form.section || null }
    try {
      if (field) await api.request(`/custom-fields/${field.id}`, 'PATCH', body)
      else await api.post('/custom-fields', { ...body, entity, key: form.key })
      await onSaved(field === null)
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.fields)
      setError(r.general)
    } finally {
      setBusy(false)
    }
  }

  const err = (k: string) => errors[k] && <div className="invalid-feedback" role="alert">{errors[k]}</div>

  return (
    <SidePanel title={t(field ? 'cf.form_edit' : 'cf.form_new')} onClose={onClose} closeLabel={t('common.close')} footer={<>
      <button type="button" className="btn btn-sec" onClick={onClose}>{t('cf.cancel')}</button>
      <button type="submit" form="campo" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('cf.save')}</button>
    </>}>
      <form id="campo" onSubmit={submit} noValidate>
        <div className="mb-3">
          <label className="form-label" htmlFor="f-label">{t('cf.label')}</label>
          <input id="f-label" className={`form-control${errors.label ? ' is-invalid' : ''}`} value={form.label} maxLength={120}
            onChange={(e) => { const label = e.target.value; setForm((f) => ({ ...f, label, key: field || (f.key !== suggestKey(f.label)) ? f.key : suggestKey(label) })) }} />
          {err('label')}
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="f-key">{t('cf.key')}</label>
          <input id="f-key" className={`form-control${errors.key ? ' is-invalid' : ''}`} value={form.key} onChange={set('key')} disabled={field !== null} aria-describedby="f-key-h" />
          <div id="f-key-h" className="small text-secondary">{t('cf.key_help')}</div>
          {err('key')}
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="f-type">{t('cf.type')}</label>
          <select id="f-type" className="form-select" value={form.type} onChange={set('type')} disabled={typeLocked}>{TYPES.map((x) => <option key={x} value={x}>{t(`cf.type_${x}`)}</option>)}</select>
          {typeLocked && <div className="small text-secondary">{t('cf.type_locked')}</div>}
          {err('type')}
        </div>
        {['select', 'multiselect'].includes(form.type) && (
          <div className="mb-3">
            <label className="form-label" htmlFor="f-options">{t('cf.options')}</label>
            <textarea id="f-options" className={`form-control${errors.options ? ' is-invalid' : ''}`} rows={4} value={form.options} onChange={set('options')} />
            {err('options')}
          </div>
        )}
        <label className="check mb-2"><input type="checkbox" checked={form.required} onChange={(e) => setForm((f) => ({ ...f, required: e.target.checked }))} /> {t('cf.required')}</label>
        <label className="check mb-2"><input type="checkbox" checked={form.sensitive} onChange={(e) => setForm((f) => ({ ...f, sensitive: e.target.checked }))} /> <span>{t('cf.sensitive')}<span className="sub2" style={{ display: 'block' }}>{t('cf.sensitive_help')}</span></span></label>
        {form.sensitive && (
          <div className="mb-3">
            <label className="form-label" htmlFor="f-perm">{t('cf.permission')}</label>
            <select id="f-perm" className={`form-select${errors.permission ? ' is-invalid' : ''}`} value={form.permission} onChange={set('permission')}>
              <option value="">{t('cf.pick')}</option>
              {permissions.map((p) => <option key={p} value={p}>{p}</option>)}
            </select>
            {err('permission')}
          </div>
        )}
        <div className="mb-3">
          <label className="form-label" htmlFor="f-section">{t('cf.section')}</label>
          <input id="f-section" className="form-control" value={form.section} onChange={set('section')} maxLength={80} />
        </div>
        {error && <div className="invalid-feedback" role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}
