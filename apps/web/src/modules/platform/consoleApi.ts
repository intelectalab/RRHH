import { ApiClient } from '@/lib/api'

/**
 * SAS-1 · Cliente de la consola de la plataforma: rutas /api/v1/platform, cookie de sesión propia
 * y su propio token XSRF. Nunca comparte sesión con la aplicación de las organizaciones.
 */
export const consoleApi = new ApiClient('/api/v1/platform', undefined, undefined, 'CONSOLE-XSRF-TOKEN')

export type ConsoleStep = 'ok' | 'verify' | 'enroll'

export type ConsoleMe = {
  user: { id: string; full_name: string; email: string; role: string; role_label: string }
  session: { id: string; expires_at: string }
  mfa_step: ConsoleStep
  permissions: string[]
}

export type ConsoleMetrics = {
  tenants: { total: number; by_status: Record<string, number>; new_last_30_days: number; demo: number }
  users: { active: number }
  console_users: number
  generated_at: string
}

export type ConsoleAuditEvent = {
  id: string
  action: string
  actor: { name: string; email: string } | null
  entity_type: string | null
  entity_id: string | null
  tenant_id: string | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  ip: string | null
  occurred_at: string
}
