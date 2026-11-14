import type { StatusTone } from '@/ui/components/Status'

/** CON-4 · Fila de GET /contracts/expiring: el contrato con los días que le quedan, lo acumulado y la sugerencia. */
export type ExpiringContract = {
  id: string
  employment_id: string
  person_name: string | null
  employee_code: string | null
  legal_entity_name: string | null
  contract_type_name: string | null
  number: string | null
  ends_on: string | null
  status: string
  days_left: number
  accumulation: { modality_days: number; modality_max_days: number | null; total_days: number; total_max_days: number | null; explanation: string }
  suggested_action: 'regularize' | 'continue_renewal' | 'convert_indefinite' | 'renew'
  renewal_contract_id: string | null
  alert_days: number[]
}

/**
 * Color de los días restantes según los umbrales de aviso de la empresa (vienen del servidor): vencido en rojo,
 * dentro del umbral más corto en ámbar y el resto en informativo. El texto siempre dice los días.
 */
export function daysLeftTone(daysLeft: number, alertDays: number[]): StatusTone {
  if (daysLeft < 0) return 'er'
  const shortest = alertDays.length > 0 ? Math.min(...alertDays) : 0
  return daysLeft <= shortest ? 'wa' : 'in'
}
