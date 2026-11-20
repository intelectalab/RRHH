import type { TableColumn, TableFilter } from '@/ui/components/DataTable'
import type { CustomValues, FieldDef } from './CustomFieldsEditor'

type T = (key: string, vars?: Record<string, string | number>) => string

/**
 * CFG-5 · Texto de un valor de campo personalizado para una celda (la API ya dejó fuera los sensibles
 * sin permiso). Solo formatea: no interpreta reglas.
 */
export function customCell(field: Pick<FieldDef, 'type'>, value: unknown, t: T): string {
  if (value === null || value === undefined || value === '') return '—'
  if (field.type === 'checkbox') return value === true ? t('cf.yes') : t('cf.no')
  if (Array.isArray(value)) return value.join(', ')
  if (typeof value === 'object') {
    const v = value as { amount?: string; currency?: string }
    return v.amount !== undefined ? `${v.currency ?? ''} ${v.amount}`.trim() : '—'
  }
  return String(value)
}

/** Una columna por campo personalizado, oculta al inicio (el usuario la muestra desde «Columnas»). */
export function customColumns<R extends { custom_fields?: CustomValues }>(fields: FieldDef[], t: T): Array<TableColumn<R>> {
  return fields.map((f) => ({
    key: `custom.${f.key}`,
    label: f.label,
    hiddenByDefault: true,
    render: (row: R) => customCell(f, row.custom_fields?.[f.key], t),
  }))
}

/** Filtros de la tabla para los campos con opciones (lista, selección múltiple y casilla). */
export function customFilters(fields: FieldDef[], t: T): TableFilter[] {
  return fields.flatMap((f): TableFilter[] => {
    if ((f.type === 'select' || f.type === 'multiselect') && f.options) {
      return [{ key: `custom.${f.key}`, label: f.label, options: f.options.map((o) => ({ value: o, label: o })) }]
    }
    if (f.type === 'checkbox') {
      return [{ key: `custom.${f.key}`, label: f.label, options: [{ value: 'true', label: t('cf.yes') }, { value: 'false', label: t('cf.no') }] }]
    }
    return []
  })
}

/** Filtros activos de la tabla que viajan también a la exportación (los nativos que se indiquen y todos los custom.*). */
export function exportFilters(filters: Record<string, string>, native: string[]): Record<string, string> {
  return Object.fromEntries(Object.entries(filters).filter(([k]) => native.includes(k) || k.startsWith('custom.')))
}
