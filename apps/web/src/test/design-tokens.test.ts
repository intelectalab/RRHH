import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * UI-1 · Fundamentos visuales: los componentes solo consumen variables y ambos temas
 * cumplen contraste AA (4,5:1 en texto normal) en los pares de uso real.
 */
const SRC = join(__dirname, '..')
const tokens = readFileSync(join(SRC, 'ui/tokens.css'), 'utf8')

function block(selector: string): Record<string, string> {
  const start = tokens.indexOf(selector)
  const body = tokens.slice(tokens.indexOf('{', start) + 1, tokens.indexOf('\n}', start))
  const vars: Record<string, string> = {}
  for (const m of body.matchAll(/--([\w-]+):\s*([^;]+);/g)) vars[m[1]!] = m[2]!.trim()
  return vars
}

const light = block(':root')
const dark = { ...light, ...block('[data-theme="dark"]') }

function resolve(theme: Record<string, string>, name: string): string {
  let v = theme[name] ?? ''
  for (let i = 0; i < 5 && v.startsWith('var('); i++) v = theme[v.slice(6, -1)] ?? ''
  return v
}

function luminance(hex: string): number {
  const h = hex.replace('#', '')
  const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
  return 0.2126 * r! + 0.7152 * g! + 0.0722 * b!
}

function contrast(a: string, b: string): number {
  const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (l1! + 0.05) / (l2! + 0.05)
}

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((f) => {
    const p = join(dir, f)
    return statSync(p).isDirectory() ? files(p) : [p]
  })
}

describe('UI-1 · colores solo por variables', () => {
  it('ningún estilo ni componente usa colores literales fuera de tokens.css', () => {
    const offenders = [...files(join(SRC, 'ui')), ...files(join(SRC, 'modules'))]
      .filter((f) => /\.(css|tsx|ts)$/.test(f) && !f.endsWith('tokens.css'))
      .flatMap((f) => {
        const text = readFileSync(f, 'utf8').replace(/url\("data:[^"]*"\)/g, '')
        return [...text.matchAll(/#[0-9a-fA-F]{3,8}\b|rgba?\(/g)].map((m) => `${f.replace(SRC, 'src')}: ${m[0]}`)
      })
    expect(offenders).toEqual([])
  })

  it('el tema oscuro redefine todos los colores semánticos de componentes', () => {
    const semantic = ['primario', 'control-bd', 'neutro-50', 'barra', 'barra-tx', 'velo', 'aviso-bg', 'aviso-tx', 'av-bg', 'av-tx', 'tx', 'tx2', 'bg', 'sup', 'bd']
    const own = block('[data-theme="dark"]')
    expect(semantic.filter((k) => !(k in own))).toEqual([])
  })
})

describe('UI-1 · contraste AA en ambos temas', () => {
  const pairs: Array<[string, string, number]> = [
    ['tx', 'sup', 4.5], ['tx', 'bg', 4.5], ['tx2', 'sup', 4.5], ['tx2', 'bg', 4.5],
    ['sobre-marca', 'primario', 4.5], ['barra-tx', 'barra', 4.5], ['barra-tx-on', 'barra', 4.5],
    ['pe-600', 'sup', 4.5], ['er', 'sup', 4.5], ['ok', 'sup', 4.5], ['wa', 'sup', 4.5], ['in', 'sup', 4.5],
    ['in', 'in-50', 4.5], ['wa', 'oc-50', 4.5], ['pe-700', 'pe-100', 4.5], ['aviso-tx', 'aviso-bg', 4.5],
    ['av-tx', 'av-bg', 4.5], ['control-bd', 'sup', 1.5],
  ]

  it.each([['claro', light], ['oscuro', dark]] as const)('tema %s', (_, theme) => {
    const failing = pairs
      .map(([fg, bg, min]) => ({ fg, bg, min, ratio: contrast(resolve(theme, fg), resolve(theme, bg)) }))
      .filter((p) => p.ratio < p.min)
      .map((p) => `${p.fg} sobre ${p.bg}: ${p.ratio.toFixed(2)} < ${p.min}`)
    expect(failing).toEqual([])
  })
})
