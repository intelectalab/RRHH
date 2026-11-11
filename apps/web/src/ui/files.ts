/**
 * UI-3 · Validación previa del cargador de archivos. Solo mejora la respuesta inmediata: el servidor
 * vuelve a validar tipo y tamaño (PLT-7, regla 10).
 */

export type FileRejection = { name: string; reason: 'type' | 'size' | 'count' }

export type FileRules = { accept?: string[]; maxBytes?: number; maxFiles?: number }

type FileLike = { name: string; size: number; type: string }

function matches(file: FileLike, accept: string[]): boolean {
  const name = file.name.toLowerCase()
  const type = file.type.toLowerCase()
  return accept.some((rule) => {
    const r = rule.trim().toLowerCase()
    if (r === '') return false
    if (r.startsWith('.')) return name.endsWith(r)
    if (r.endsWith('/*')) return type.startsWith(r.slice(0, -1))
    return type === r
  })
}

/** Separa los archivos aceptados de los rechazados, con el motivo de cada rechazo. */
export function validateFiles<T extends FileLike>(files: T[], rules: FileRules, alreadySelected = 0): { accepted: T[]; rejected: FileRejection[] } {
  const accepted: T[] = []
  const rejected: FileRejection[] = []
  for (const file of files) {
    if (rules.accept && rules.accept.length > 0 && !matches(file, rules.accept)) {
      rejected.push({ name: file.name, reason: 'type' })
    } else if (rules.maxBytes !== undefined && file.size > rules.maxBytes) {
      rejected.push({ name: file.name, reason: 'size' })
    } else if (rules.maxFiles !== undefined && alreadySelected + accepted.length >= rules.maxFiles) {
      rejected.push({ name: file.name, reason: 'count' })
    } else {
      accepted.push(file)
    }
  }
  return { accepted, rejected }
}

/** Tamaño legible (KB, MB) con el formato numérico del idioma activo. */
export function formatBytes(bytes: number, locale: string): string {
  const units = ['B', 'KB', 'MB', 'GB']
  let value = bytes
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  const digits = unit === 0 ? 0 : value < 10 ? 1 : 0
  return `${new Intl.NumberFormat(locale, { maximumFractionDigits: digits }).format(value)} ${units[unit]}`
}
