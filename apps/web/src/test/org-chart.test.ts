import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, expect, it } from 'vitest'
import { imagePdf } from '@/lib/pdf'
import { ancestorsOf, chartSvg, collapsedFromDepth, layoutChart, NODE_H, NODE_W, type ChartNode } from '@/modules/people/orgChartLayout'

const tree: ChartNode[] = [
  { id: 'gg', parent_id: null, title: 'Gerencia General' },
  { id: 'fin', parent_id: 'gg', title: 'Finanzas', subtitle: 'Julia Mendoza' },
  { id: 'ops', parent_id: 'gg', title: 'Operaciones' },
  { id: 'tes', parent_id: 'fin', title: 'Tesorería & <Caja>' },
  { id: 'con', parent_id: 'fin', title: 'Contabilidad' },
]

describe('ORG-6 · organigrama', () => {
  it('centra cada rama sobre sus hijos y no superpone cajas', () => {
    const layout = layoutChart(tree, new Set())
    const at = Object.fromEntries(layout.nodes.map((n) => [n.id, n]))
    expect(layout.nodes).toHaveLength(5)
    expect(layout.edges).toHaveLength(4)
    expect(at.fin.y).toBeGreaterThan(at.gg.y)
    expect(at.tes.y).toBe(at.con.y)
    // El padre queda centrado entre su primer y su último hijo.
    expect(at.fin.x + NODE_W / 2).toBeCloseTo((at.tes.x + at.con.x + NODE_W) / 2)
    const sameRow = layout.nodes.filter((n) => n.y === at.tes.y).sort((a, b) => a.x - b.x)
    for (let i = 1; i < sameRow.length; i++) expect(sameRow[i].x).toBeGreaterThanOrEqual(sameRow[i - 1].x + NODE_W)
    expect(layout.width).toBeGreaterThan(3 * NODE_W)
    expect(layout.height).toBeGreaterThan(3 * NODE_H)
  })

  it('una rama plegada oculta sus hijos y la búsqueda despliega el camino a cada coincidencia', () => {
    const closed = collapsedFromDepth(tree, 1)
    expect([...closed]).toEqual(['fin'])
    const layout = layoutChart(tree, closed)
    expect(layout.nodes.map((n) => n.id)).not.toContain('tes')
    expect(layout.nodes.find((n) => n.id === 'fin')?.collapsed).toBe(true)
    expect([...ancestorsOf(tree, new Set(['tes']))].sort()).toEqual(['fin', 'gg'])
  })

  it('un jefe fuera de la lista o un ciclo no rompen el dibujo', () => {
    const odd: ChartNode[] = [
      { id: 'a', parent_id: 'b', title: 'A' },
      { id: 'b', parent_id: 'a', title: 'B' },
      { id: 'c', parent_id: 'no-visible', title: 'C' },
    ]
    expect(layoutChart(odd, new Set()).nodes.map((n) => n.id).sort()).toEqual(['a', 'b', 'c'])
  })

  it('exporta un SVG autónomo con el texto escapado', () => {
    const palette = { bg: '#fff', box: '#fff', border: '#ccc', title: '#036', text: '#222', muted: '#666', line: '#999', accent: '#0a7' }
    const svg = chartSvg(layoutChart(tree, new Set()), palette, 'Organigrama al 12 nov 2026')
    expect(svg.startsWith('<svg xmlns="http://www.w3.org/2000/svg"')).toBe(true)
    expect(svg).toContain('Tesorería &amp; &lt;Caja&gt;')
    expect(svg).toContain('Organigrama al 12 nov 2026')
    expect(svg).not.toContain('var(--')
  })

  it('arma un PDF válido con la imagen JPEG incrustada', () => {
    const jpeg = new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 1, 2, 3, 0xff, 0xd9])
    const pdf = imagePdf(jpeg, 800, 400)
    const text = new TextDecoder('latin1').decode(pdf)
    expect(text.startsWith('%PDF-1.4')).toBe(true)
    expect(text).toContain('/Filter /DCTDecode')
    expect(text).toContain('/Width 800 /Height 400')
    expect(text.trimEnd().endsWith('%%EOF')).toBe(true)
    // La tabla de referencias apunta al inicio real de cada objeto.
    const xref = Number(/startxref\n(\d+)/.exec(text)?.[1])
    const entries = text.slice(xref).split('\n').slice(3, 8).map((l) => Number(l.slice(0, 10)))
    entries.forEach((offset, i) => expect(new TextDecoder('latin1').decode(pdf.slice(offset, offset + 8))).toBe(`${i + 1} 0 obj\n`.slice(0, 8)))
  })

  it('todas las claves de las pantallas de personas existen en los tres idiomas', () => {
    const files = ['DirectoryScreen.tsx', 'OrgChartScreen.tsx', 'peopleTabs.ts'].map((f) => readFileSync(resolve(__dirname, '../modules/people', f), 'utf8')).join('\n')
    const keys = [...new Set([...files.matchAll(/'people\.([a-z_]+)'/g)].map((m) => m[1]))]
    expect(keys.length).toBeGreaterThan(30)
    for (const lang of ['es', 'en', 'pt']) {
      const messages = JSON.parse(readFileSync(resolve(__dirname, `../../messages/${lang}.json`), 'utf8'))
      for (const key of keys) expect(messages.people[key], `${lang}:people.${key}`).toBeTruthy()
      for (const k of ['contact_work_email', 'contact_work_phone', 'contact_work_phone_ext', 'view_units', 'view_people']) expect(messages.people[k]).toBeTruthy()
    }
  })
})
