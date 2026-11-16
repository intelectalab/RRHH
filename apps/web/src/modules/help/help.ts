import { api } from '@/lib/api'

/** Artículo de ayuda tal como lo entrega el centro de ayuda (última versión publicada). */
export type HelpArticle = {
  id: string
  slug: string
  kind: 'guide' | 'screen' | 'field' | 'tour' | 'release_note' | 'faq'
  title: string
  summary: string | null
  body: string
  module_code: string
  screen_keys: string[]
  locale: string
  translation_missing: boolean
  product_version: string
  version_number: number
  published_at: string
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
