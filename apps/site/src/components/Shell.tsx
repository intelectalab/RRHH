import Link from 'next/link'
import type { ReactNode } from 'react'
import { PRODUCT_SLUGS, type Catalog } from '@/lib/catalog'
import { LOCALES, content, href, type Locale } from '@/lib/i18n'

/** Cabecera y pie del sitio: los productos se leen del catálogo; los textos, del contenido del idioma. */
export function SiteShell({ lang, catalog, path, children }: { lang: Locale; catalog: Catalog | null; path: string; children: ReactNode }) {
  const t = content(lang)
  // Solo los productos que ya están a la venta (los «Próximamente» no ocupan el menú; su página sigue existiendo).
  const products = (catalog?.products ?? []).filter((p) => (PRODUCT_SLUGS as readonly string[]).includes(p.slug) && p.status !== 'coming_soon')
  return (
    <>
      <a className="skip" href="#contenido">{t.nav.skip}</a>
      <header className="nav">
        <div className="wrap">
          <Link className="logo" href={href(lang, '/')} aria-label={t.nav.home}>
            <span className="mk" aria-hidden="true" />
            <span className="wm">{t.nav.brand_a}<span>{t.nav.brand_b}</span></span>
          </Link>
          <nav className="menu" aria-label={t.nav.products}>
            <Link href={`${href(lang, '/')}#como-funciona`}>{t.nav.how}</Link>
            <Link href={`${href(lang, '/')}#incluye`}>{t.nav.included}</Link>
            <Link href={href(lang, '/precios')}>{t.nav.pricing}</Link>
          </nav>
          <div className="nav-r">
            <nav className="langs" aria-label={t.nav.languages}>
              {LOCALES.map((l) => (
                <Link key={l} href={href(l, path)} hrefLang={l} aria-current={l === lang ? 'true' : undefined}>{l.toUpperCase()}</Link>
              ))}
            </nav>
            <Link className="nav-login" href={href(lang, '/ingresar')}>{t.nav.login}</Link>
            <Link className="btn btn-primary" href={href(lang, '/demo')}>{t.nav.demo}</Link>
            <details className="mnav">
              <summary aria-label={t.nav.menu}>{'☰'}</summary>
              <div className="mnav-p">
                <b>{t.nav.products}</b>
                {products.map((p) => (
                  <Link key={p.code} href={href(lang, `/${p.slug}`)}>{p.name}</Link>
                ))}
                <Link href={href(lang, '/precios')}>{t.nav.pricing}</Link>
                <Link href={href(lang, '/demo')}>{t.nav.demo}</Link>
                <Link href={href(lang, '/contacto')}>{t.nav.contact}</Link>
                <Link href={href(lang, '/ingresar')}>{t.nav.login}</Link>
              </div>
            </details>
          </div>
        </div>
      </header>
      <main id="contenido">{children}</main>
      <footer>
        <div className="wrap foot-grid">
          <div>
            <b>{t.footer.company}</b>
            <p>{t.footer.tagline}</p>
          </div>
          <div>
            <b>{t.footer.products}</b>
            <ul>
              {products.map((p) => (
                <li key={p.code}><Link href={href(lang, `/${p.slug}`)}>{p.name}</Link></li>
              ))}
              <li><Link href={href(lang, '/precios')}>{t.nav.pricing}</Link></li>
            </ul>
          </div>
          <div>
            <b>{t.nav.contact}</b>
            <ul>
              <li><Link href={href(lang, '/demo')}>{t.nav.demo}</Link></li>
              <li><Link href={href(lang, '/contacto')}>{t.nav.contact}</Link></li>
              <li><Link href={href(lang, '/partners')}>{t.nav.partners}</Link></li>
              <li><Link href={href(lang, '/ingresar')}>{t.nav.login}</Link></li>
            </ul>
          </div>
          <p className="fine">{t.footer.rights}</p>
        </div>
      </footer>
    </>
  )
}
