import { describe, expect, it } from 'vitest'
import es from '../../messages/es.json'
import en from '../../messages/en.json'
import pt from '../../messages/pt.json'
import { resolveLocale, translate } from '@/lib/i18n'

function keys(node: unknown, prefix = ''): string[] {
  if (typeof node !== 'object' || node === null) return [prefix]
  return Object.entries(node as Record<string, unknown>).flatMap(([k, v]) => keys(v, prefix ? `${prefix}.${k}` : k))
}

describe('Regla 12 · catálogos de idioma completos', () => {
  const base = keys(es).sort()

  it.each([['en', en], ['pt', pt]])('%s tiene exactamente las mismas claves que es', (_, catalog) => {
    expect(keys(catalog).sort()).toEqual(base)
  })

  it('ningún texto está vacío', () => {
    for (const catalog of [es, en, pt]) {
      for (const key of keys(catalog)) {
        expect(translate('es', key).trim(), key).not.toBe('')
      }
    }
  })

  it('traduce y cae al español ante un idioma no soportado', () => {
    expect(translate('en', 'auth.login')).toBe('Sign in')
    expect(translate(resolveLocale('fr-FR'), 'auth.login')).toBe('Ingresar')
    expect(resolveLocale('pt-BR')).toBe('pt')
  })
})
