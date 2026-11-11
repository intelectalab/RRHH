import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getLocale, resetLocaleForTests, setLocale, subscribeLocale } from '@/lib/locale'
import { translate } from '@/lib/i18n'

/** CFG-6 · El idioma es un almacén único: cambiarlo avisa a toda la interfaz sin recargar. */
describe('idioma activo', () => {
  let store: Map<string, string>
  let html: { lang: string }

  beforeEach(() => {
    // Entorno node: se simula lo mínimo del navegador (almacenamiento, idioma y <html lang>).
    store = new Map()
    html = { lang: 'es' }
    const localStorage = { getItem: (k: string) => store.get(k) ?? null, setItem: (k: string, v: string) => { store.set(k, v) } }
    vi.stubGlobal('window', { localStorage, navigator: { language: 'es-PE' } })
    vi.stubGlobal('document', { documentElement: html })
    resetLocaleForTests()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    resetLocaleForTests()
  })

  it('cambia al instante, avisa a los suscriptores y se recuerda en el navegador', () => {
    const listener = vi.fn()
    subscribeLocale(listener)
    setLocale('en-US')
    expect(getLocale()).toBe('en')
    expect(listener).toHaveBeenCalledTimes(1)
    expect(html.lang).toBe('en')
    expect(store.get('gestia.locale')).toBe('en')
    expect(translate(getLocale(), 'catalogs.title')).toBe('Catalogs')

    // El mismo idioma no repinta.
    setLocale('en')
    expect(listener).toHaveBeenCalledTimes(1)
  })

  it('un idioma no admitido cae al español', () => {
    setLocale('fr')
    expect(getLocale()).toBe('es')
  })

  it('al iniciar usa el último idioma elegido en el navegador y, si no hay, el del navegador', () => {
    store.set('gestia.locale', 'pt')
    expect(getLocale()).toBe('pt')
    resetLocaleForTests()
    store.clear()
    expect(getLocale()).toBe('es')
  })
})
