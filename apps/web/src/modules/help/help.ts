import { api } from '@/lib/api'

/** Artículo de ayuda tal como lo entrega el centro de ayuda (última versión publicada). */
export type HelpArticle = {
  id: string
  slug: string
  /** product: ayuda de GestiaHR · organization: artículo o política propia (AYU-4). */
  source: 'product' | 'organization'
  kind: 'guide' | 'screen' | 'field' | 'tour' | 'release_note' | 'faq' | 'policy'
  title: string
  summary: string | null
  body: string
  module_code: string
  screen_keys: string[]
  locale: string
  translation_missing: boolean
  product_version: string
  version_number: number
  /** Artículo de la organización «por revisar» (RN-AYU-04): sigue visible. */
  review_due: boolean
  published_at: string
}

/** AYU-7 · Recorrido de primer ingreso con su estado para el usuario. */
export type HelpTour = HelpArticle & { status: 'pending' | 'completed' | 'skipped' }

/** AYU-6 · Consulta derivada desde la ayuda. */
export type HelpEscalation = {
  id: string
  target: 'hr' | 'platform_support'
  question: string
  screen_key: string | null
  consulted_articles: Array<{ id: string; title: string; source: string }>
  status: 'open' | 'answered' | 'closed'
  request_id: string | null
  request_number: string | null
  created_at: string
}

/**
 * Cada párrafo del recorrido es un paso: «Título: texto» muestra el título aparte y el texto, que deja de seguir a los
 * dos puntos, empieza con mayúscula como una oración.
 */
export function tourSteps(body: string): Array<{ title: string | null; text: string }> {
  return helpBlocks(body).filter((b) => b.kind === 'p').map((b) => {
    const text = (b as { text: string }).text
    const i = text.indexOf(': ')
    if (i > 0 && i < 60) {
      const rest = text.slice(i + 2)
      return { title: text.slice(0, i), text: rest.charAt(0).toLocaleUpperCase() + rest.slice(1) }
    }
    return { title: null, text }
  })
}

export type ScreenHelp = { screen_key: string; articles: HelpArticle[]; fields: Record<string, HelpArticle> }

export type HelpBlock = { kind: 'p'; text: string } | { kind: 'ul'; items: string[] }

/**
 * El cuerpo de un artículo es texto simple: párrafos separados por una línea en blanco y viñetas con «- ».
 * Se muestra como texto (nunca como HTML).
 */
export function helpBlocks(body: string): HelpBlock[] {
  return body.split(/\n\s*\n/).map((chunk) => chunk.trim()).filter(Boolean).map((chunk) => {
    const lines = chunk.split('\n').map((l) => l.trim()).filter(Boolean)
    return lines.every((l) => l.startsWith('- '))
      ? { kind: 'ul' as const, items: lines.map((l) => l.slice(2)) }
      : { kind: 'p' as const, text: lines.join(' ') }
  })
}

const screens = new Map<string, Promise<ScreenHelp | null>>()

/** Ayuda de una pantalla (una petición por pantalla e idioma; las ayudas de campo la comparten). */
export function loadScreenHelp(key: string, locale: string): Promise<ScreenHelp | null> {
  const cacheKey = `${locale}|${key}`
  let found = screens.get(cacheKey)
  if (!found) {
    found = api.get<{ data: ScreenHelp }>(`/help/screens/${encodeURIComponent(key)}`).then((r) => r.data).catch(() => {
      screens.delete(cacheKey)
      return null
    })
    screens.set(cacheKey, found)
  }
  return found
}
