import type { MetadataRoute } from 'next'
import { isIndexable, siteUrl } from '@/lib/seo'

/** WEB-1 · En prueba no se indexa nada; en producción todo menos el registro. */
export default function robots(): MetadataRoute.Robots {
  if (!isIndexable()) return { rules: { userAgent: '*', disallow: '/' } }
  return { rules: { userAgent: '*', allow: '/', disallow: ['/registro', '/demo/gestionar', '/ingresar', '/api/'] }, sitemap: `${siteUrl()}/sitemap.xml` }
}
