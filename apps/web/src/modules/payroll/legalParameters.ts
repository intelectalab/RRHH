/**
 * PAR-1 a PAR-7 · Tipos y formato de los parámetros legales que muestra la pantalla. Los valores, la vigencia y lo
 * que falta los decide el servidor (regla 11); aquí solo se presentan.
 */
export type LegalParameter = {
  id: string; code: string; name: string; regime: string | null; value: unknown; unit: string; currency: string | null
  valid_from: string; valid_to: string | null; legal_source: string | null; verified: boolean; pending_validation: boolean; confirmed?: boolean
}

export type LegalParameterDetail = LegalParameter & {
  history: Array<{ id: string; value: unknown; valid_from: string; valid_to: string | null; legal_source: string | null; pending_validation: boolean }>
  own_values: Array<{ id: string; value: unknown; reason: string; valid_from: string; valid_to: string | null; legal_entity_id: string | null }>
  confirmations: Array<{ id: string; note: string | null; confirmed_by: string; confirmed_at: string }>
  adjustable: 'mayor' | 'menor' | null
}

export type FundRate = {
  fund_code: string; fund_name: string; missing: boolean; contribution_rate?: string | null; commission_flow_rate?: string | null
  commission_mixed_rate?: string | null; insurance_premium_rate?: string | null; max_insurable_remuneration?: string | null
  currency?: string | null; source?: string | null; valid_from?: string; valid_to?: string | null
}

export type WageTable = {
  id: string; code: string; name: string; regime: string; valid_from: string; valid_to: string | null; legal_source: string | null
  expired: boolean; verified: boolean; values: Array<{ code: string; name: string; value: unknown; unit: string | null; pending_validation: boolean }>
  verification: { acta_reference: string; verified_by: string; verified_at: string } | null
}

export type LegalUpdate = {
  id: string; code: string; name: string; regime: string | null; unit: string; previous_value: unknown; new_value: unknown
  valid_from: string; valid_to: string | null; legal_source: string | null; origin: string; published_at: string
  open_periods: Array<{ id: string; group: string; starts_on: string; ends_on: string; status: string }>
  closed_periods: Array<{ id: string; group: string; starts_on: string; ends_on: string; status: string }>
  suggests_simulation: boolean; suggests_complementary: boolean
}

export type RegimeParameters = {
  regime: string; supported: boolean; can_calculate: boolean; missing: string[]
  parameters: Array<{ code: string; required: boolean; missing: boolean; value: unknown; unit: string | null; origin: string | null; legal_source: string | null; pending_validation: boolean }>
}

/** Valor legible: número con su unidad, tabla JSON resumida o guion si no tiene valor. */
export function formatValue(value: unknown, unit: string | null, locale: string): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'object') return JSON.stringify(value)
  const text = String(value)
  const n = Number(text)
  const shown = Number.isFinite(n) && text.trim() !== '' ? new Intl.NumberFormat(locale, { maximumFractionDigits: 6 }).format(n) : text
  if (unit === 'percent') return `${shown} %`
  return shown
}

export const TABS = ['parametros', 'afp', 'quinta', 'regimenes', 'tablas', 'novedades'] as const
export type TabId = (typeof TABS)[number]

export function isTab(v: string | null): v is TabId {
  return v !== null && (TABS as readonly string[]).includes(v)
}
