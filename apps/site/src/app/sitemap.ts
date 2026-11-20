import type { MetadataRoute } from 'next'
import { PRODUCT_SLUGS } from '@/lib/catalog'
import { LOCALES, href } from '@/lib/i18n'
import { siteUrl } from '@/lib/seo'

const PAGES = ['/', '/suite', '/precios', ...PRODUCT_SLUGS.map((s) => `/${s}`)]

export default function sitemap(): MetadataRoute.Sitemap {
  return PAGES.map((path) => ({
    url: `${siteUrl()}${href('es', path)}`,
    alternates: { languages: Object.fromEntries(LOCALES.map((l) => [l, `${siteUrl()}${href(l, path)}`])) },
  }))
}
