import type { Schema } from '@/lib/api'

type Outcome = Pick<Schema<'ImportJob'>, 'created_rows' | 'updated_rows' | 'rejected_rows'>

/** IMP-6 · Resultado del lote (creadas, actualizadas y rechazadas): lo cuenta la API; aquí solo se formatea. */
export function outcomeText(job: Outcome, t: (key: string, vars?: Record<string, string | number>) => string): string | null {
  if (job.created_rows == null) return null
  return t('imp.outcome', { created: job.created_rows, updated: job.updated_rows ?? 0, rejected: job.rejected_rows ?? 0 })
}
