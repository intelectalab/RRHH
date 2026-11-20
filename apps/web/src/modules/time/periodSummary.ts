/**
 * AUS-5 · AUS-6 · Tipos del resumen de ausencias del periodo. El servidor calcula todo (regla 11): aquí solo se
 * eligen el color de cada tipo de día y el periodo por defecto (el mes de la fecha dada).
 */
export type SummaryDayKind = 'outside_employment' | 'rest_day' | 'holiday' | 'worked' | 'remote' | 'absence'

export type SummaryAbsence = {
  id: string
  type_code: string
  type_name: string
  category: string
  effect: 'paid' | 'unpaid' | 'employer_paid' | 'subsidy'
  hours: string | null
}

export type SummaryDay = {
  date: string
  kind: SummaryDayKind
  remote_source: 'agreement' | 'absence' | null
  absence: SummaryAbsence | null
  hours_absent: string | null
  explanation: string
}

export type SummaryType = {
  type_code: string
  type_name: string
  category: string
  unit: string
  days: number
  hours: string
  paid_days: number
  unpaid_days: number
  employer_paid_days: number
  subsidized_days: number
  payroll_concept_code: string | null
}

export type ClosingDiff = {
  closed_at: string
  changed: boolean
  days: Array<{ date: string; before: { kind: string } | null; after: { kind: string } }>
}

export type PeriodSummary = {
  employment_id: string
  from: string
  to: string
  source: string
  days: SummaryDay[]
  types: SummaryType[]
  totals: Record<string, number | string>
  imported_incidents: Array<{ id: string; concept_code: string; quantity: string | null }>
  fingerprint: string
  closing: ClosingDiff | null
}

export const DAY_TONE: Record<SummaryDayKind, string> = {
  outside_employment: 'gr',
  rest_day: 'gr',
  holiday: 'in',
  worked: 'ok',
  remote: 'pe',
  absence: 'wa',
}

/** Primer y último día del mes de la fecha (AAAA-MM-DD), sin zona horaria. */
export function monthOf(date: string): { from: string; to: string } {
  const [y, m] = date.split('-').map((x) => Number(x))
  const year = y ?? 1970
  const month = m ?? 1
  const last = new Date(Date.UTC(year, month, 0)).getUTCDate()
  const mm = String(month).padStart(2, '0')
  return { from: `${year}-${mm}-01`, to: `${year}-${mm}-${String(last).padStart(2, '0')}` }
}
