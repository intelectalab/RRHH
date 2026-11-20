import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { tourSteps } from '@/modules/help/help'
import { helpScreenFor } from '@/modules/help/screens'

const load = (l: string) => JSON.parse(readFileSync(join(__dirname, `../../messages/${l}.json`), 'utf8')) as Record<string, Record<string, unknown>>

describe('AYU-4 a AYU-7 · ayuda de la organización, consultas y recorridos', () => {
  it('cada párrafo del recorrido es un paso y «Título: texto» separa el título', () => {
    expect(tourSteps('Mi equipo: ve a tus colaboradores.\n\nSin título aquí.')).toEqual([
      { title: 'Mi equipo', text: 've a tus colaboradores.' },
      { title: null, text: 'Sin título aquí.' },
    ])
  })

  it('la pantalla de ayuda de la organización tiene su propia clave', () => {
    expect(helpScreenFor('/configuracion/ayuda')).toBe('settings.help')
  })

  it('las pantallas nuevas tienen los mismos textos en los tres idiomas', () => {
    const es = load('es')
    for (const l of ['en', 'pt']) {
      const dict = load(l)
      expect(Object.keys(dict.orghelp).sort()).toEqual(Object.keys(es.orghelp).sort())
      expect(Object.keys(dict.help).sort()).toEqual(Object.keys(es.help).sort())
    }
  })
})
