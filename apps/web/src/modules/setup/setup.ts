/** IMP-3 · IMP-4 · Tipos y ayudas del asistente de primeros pasos y de los paquetes de país. */

export type SetupStepStatus = 'pending' | 'done' | 'skipped'

export type SetupStep = {
  code: string
  title: string
  description: string
  module_code: string
  status: SetupStepStatus
  link: string
  completed_at: string | null
  allowed_actions: Array<'skip' | 'reopen'>
}

export type SetupChecklist = {
  progress: number
  total: number
  done: number
  skipped: number
  completed: boolean
  dismissed: boolean
  visible: boolean
  steps: SetupStep[]
}

export type CountryPackComponent = {
  code: string
  name: string
  module_code: string
  module_enabled: boolean
  items: string[]
  total: number
  present: number
  link: string
}

export type CountryPack = {
  code: string
  name: string
  country_code: string
  version: string
  released_on: string
  changelog: Record<string, string>
  pending_validation: string[]
  installed_version: string | null
  installed_at: string | null
  update_available: boolean
  components: CountryPackComponent[]
}

/** Primer paso pendiente: el que el asistente propone hacer ahora. */
export function nextStep(checklist: SetupChecklist): SetupStep | null {
  return checklist.steps.find((s) => s.status === 'pending') ?? null
}

/** Partes del paquete que todavía crearían algo al aplicarlo (con su módulo contratado). */
export function pendingComponents(pack: CountryPack): CountryPackComponent[] {
  return pack.components.filter((c) => c.module_enabled && c.present < c.total)
}

/** Estado del paquete para el botón principal: aplicar por primera vez, actualizar o ya al día. */
export function packState(pack: CountryPack): 'apply' | 'update' | 'complete' {
  if (pack.installed_version === null) return 'apply'
  if (pack.update_available || pendingComponents(pack).length > 0) return 'update'
  return 'complete'
}
