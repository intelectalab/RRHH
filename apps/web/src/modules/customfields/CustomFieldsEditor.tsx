'use client'

import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'

export type FieldDef = { key: string; label: string; type: string; options: string[] | null; required: boolean; sensitive: boolean; section: string | null }
export type CustomValues = Record<string, unknown>

/** Definiciones del formulario de una entidad (GET /custom-fields/form). */
export function useCustomFields(entity: string): { fields: FieldDef[]; canEdit: boolean } {
  const [state, setState] = useState<{ fields: FieldDef[]; canEdit: boolean }>({ fields: [], canEdit: false })
  useEffect(() => {
    void api.get<{ data: { fields: FieldDef[]; can_edit: boolean } }>(`/custom-fields/form?entity=${entity}`)
      .then((r) => setState({ fields: r.data.fields, canEdit: r.data.can_edit }))
      .catch(() => setState({ fields: [], canEdit: false }))
  }, [entity])
  return state
}

/**
 * CFG-4 · Campos personalizados dentro de cualquier formulario: se dibujan según su tipo; la
 * validación real la hace la API (errores por campo en custom_fields.<clave>).
 */
export function CustomFieldsEditor({ fields, values, onChange, errors, disabled }: { fields: FieldDef[]; values: CustomValues; onChange: (v: CustomValues) => void; errors: Record<string, string>; disabled?: boolean }) {
  const { t } = useT()
  if (fields.length === 0) return null
  const set = (key: string, v: unknown) => onChange({ ...values, [key]: v })

  return (
    <fieldset className="cf-grupo" disabled={disabled}>
      <legend>{t('cf.more')}</legend>
      {fields.map((f) => {
        const id = `cf-${f.key}`
        const v = values[f.key]
        const err = errors[`custom_fields.${f.key}`]
        const cls = (base: string) => `${base}${err ? ' is-invalid' : ''}`
        let input: React.ReactNode
        switch (f.type) {
          case 'checkbox':
            input = <input id={id} type="checkbox" className="switch" role="switch" checked={v === true} onChange={(e) => set(f.key, e.target.checked)} />
            break
          case 'select':
            input = <select id={id} className={cls('form-select')} value={typeof v === 'string' ? v : ''} onChange={(e) => set(f.key, e.target.value || null)}><option value="">{t('cf.pick')}</option>{(f.options ?? []).map((o) => <option key={o} value={o}>{o}</option>)}</select>
            break
          case 'multiselect': {
            const list = Array.isArray(v) ? (v as string[]) : []
            input = <div className="chips" role="group" aria-labelledby={`${id}-l`}>{(f.options ?? []).map((o) => <button key={o} type="button" aria-pressed={list.includes(o)} onClick={() => set(f.key, list.includes(o) ? list.filter((x) => x !== o) : [...list, o])}>{o}</button>)}</div>
            break
          }
          case 'date':
            input = <input id={id} type="date" className={cls('form-control')} value={typeof v === 'string' ? v : ''} onChange={(e) => set(f.key, e.target.value || null)} />
            break
          case 'number':
          case 'percent':
            input = <input id={id} inputMode="decimal" className={cls('form-control')} value={typeof v === 'string' || typeof v === 'number' ? String(v) : ''} onChange={(e) => set(f.key, e.target.value || null)} />
            break
          case 'url':
            input = <input id={id} type="url" className={cls('form-control')} value={typeof v === 'string' ? v : ''} onChange={(e) => set(f.key, e.target.value || null)} />
            break
          case 'currency': {
            const m = (v && typeof v === 'object' ? v : {}) as { amount?: string; currency?: string }
            input = (
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 100px', gap: 8 }}>
                <input id={id} inputMode="decimal" className={cls('form-control')} value={m.amount ?? ''} onChange={(e) => set(f.key, e.target.value ? { amount: e.target.value, currency: m.currency ?? 'PEN' } : null)} />
                <select className="form-select" aria-label={f.label} value={m.currency ?? 'PEN'} onChange={(e) => set(f.key, { amount: m.amount ?? '0', currency: e.target.value })}>{['PEN', 'USD', 'EUR'].map((c) => <option key={c} value={c}>{c}</option>)}</select>
              </div>
            )
            break
          }
          default:
            input = <input id={id} className={cls('form-control')} value={typeof v === 'string' ? v : ''} onChange={(e) => set(f.key, e.target.value || null)} />
        }
        return (
          <div className="mb-3" key={f.key}>
            <label className="form-label" htmlFor={id} id={`${id}-l`}>{f.label}{f.required ? ' *' : ''}{f.sensitive && <span className="tag pe" style={{ marginLeft: 6 }}>{t('cf.sensitive')}</span>}</label>
            {input}
            {err && <div className="invalid-feedback" role="alert">{err}</div>}
          </div>
        )
      })}
    </fieldset>
  )
}
