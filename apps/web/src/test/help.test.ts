import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { helpBlocks } from '@/modules/help/help'
import { HELP_SCREENS, helpScreenFor } from '@/modules/help/screens'
import es from '../../messages/es.json'
import en from '../../messages/en.json'
import pt from '../../messages/pt.json'

const shell = readFileSync(join(__dirname, '../modules/session/AppShell.tsx'), 'utf8')
const menuBlock = shell.slice(shell.indexOf('const MENU'), shell.indexOf('\n]', shell.indexOf('const MENU')))
const menuHrefs = [...menuBlock.matchAll(/href: '([^']+)'/g)].map((m) => m[1])
const apiScreens = JSON.parse(readFileSync(join(__dirname, '../../../api/resources/help/screens.json'), 'utf8')) as { screens: Record<string, unknown> }

describe('AYU-3 · ayuda contextual por pantalla', () => {
  it('toda pantalla del menú tiene clave de ayuda', () => {
    expect(menuHrefs.length).toBeGreaterThan(10)
    const missing = menuHrefs.filter((href) => helpScreenFor(href) === null)
    expect(missing).toEqual([])
    // Configuración y la cuenta, fuera del menú principal, también.
    expect(helpScreenFor('/configuracion')).toBe('settings')
    expect(helpScreenFor('/cuenta/seguridad')).toBe('account.security')
  })

  it('la ruta más larga gana y las subpantallas heredan la clave', () => {
    expect(helpScreenFor('/')).toBe('home')
    expect(helpScreenFor('/personas/colaboradores/0198-abc')).toBe('people.employees')
    expect(helpScreenFor('/mi')).toBe('portal.home')
    expect(helpScreenFor('/mi/solicitudes')).toBe('portal.requests')
    expect(helpScreenFor('/mi-equipo/fechas')).toBe('my_team')
    expect(helpScreenFor('/organizacion/sedes')).toBe('org')
    expect(helpScreenFor('/configuracion/usuarios?x=1')).toBe('settings')
    expect(helpScreenFor('/ruta-desconocida')).toBeNull()
  })

  it('las claves de la web son exactamente las que conoce la API', () => {
    expect([...new Set(HELP_SCREENS.map((s) => s.key))].sort()).toEqual(Object.keys(apiScreens.screens).sort())
  })
})

describe('AYU-2 · centro de ayuda', () => {
  it('el cuerpo del artículo se arma como párrafos y viñetas de texto', () => {
    expect(helpBlocks('Primero.\n\n- uno\n- dos\n\nFinal\ncontinúa.')).toEqual([
      { kind: 'p', text: 'Primero.' },
      { kind: 'ul', items: ['uno', 'dos'] },
      { kind: 'p', text: 'Final continúa.' },
    ])
    expect(helpBlocks('<b>no es html</b>')).toEqual([{ kind: 'p', text: '<b>no es html</b>' }])
  })

  it('los textos del centro de ayuda y de la consola están en los tres idiomas', () => {
    for (const dict of [es, en, pt] as Array<Record<string, Record<string, unknown>>>) {
      expect(Object.keys(dict.help).sort()).toEqual(Object.keys(es.help).sort())
      for (const k of ['tab_help', 'help_title', 'help_status', 'help_action', 'help_kind_label']) expect(dict.console[k]).toBeTruthy()
    }
  })
})
