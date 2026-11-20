import type { Metadata } from 'next'
import { DEFAULT_LOCALE, LOCALES, href } from './i18n'

/**
 * WEB-1 · Solo producción se indexa. Cualquier otro entorno (prueba, local) responde noindex en todas sus páginas,
 * además del encabezado X-Robots-Tag que pone el middleware y del robots.txt que lo prohíbe todo.
 */
export function isIndexable(): boolean {
  return process.env.SITE_ENV === 'production'
}

export function siteUrl(): string {
  return (process.env.SITE_URL ?? 'https://gestiahr.com').replace(/\/$/, '')
}

export function pageMetadata(lang: string, path: string, title: string, description: string): Metadata {
  const languages = Object.fromEntries(LOCALES.map((l) => [l, `${siteUrl()}${href(l, path)}`]))
  return {
    title,
    description,
    metadataBase: new URL(siteUrl()),
    alternates: { canonical: `${siteUrl()}${href(lang, path)}`, languages: { ...languages, 'x-default': `${siteUrl()}${href(DEFAULT_LOCALE, path)}` } },
    openGraph: { title, description, url: `${siteUrl()}${href(lang, path)}`, siteName: 'GestiaHR', type: 'website', images: ['/og-gestiahr.png'] },
    robots: isIndexable() ? { index: true, follow: true } : { index: false, follow: false },
  }
}
