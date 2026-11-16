/**
 * ORG-6 · Disposición del organigrama: árbol de arriba hacia abajo, cada rama centrada sobre sus hijos.
 * Funciones puras (sin DOM): las usan la vista en pantalla y la exportación a imagen y PDF.
 */

/** tone: estado que el nodo dibuja aparte (POS-7: plaza vacante, en reclutamiento o congelada). */
export type ChartNode = { id: string; parent_id: string | null; title: string; subtitle?: string | null; detail?: string | null; tone?: 'vacant' | 'recruiting' | 'frozen' }

export type PlacedNode = ChartNode & { x: number; y: number; depth: number; childCount: number; collapsed: boolean }
export type Edge = { from: string; to: string; path: string }
export type Layout = { nodes: PlacedNode[]; edges: Edge[]; width: number; height: number }

export const NODE_W = 220
export const NODE_H = 72
const GAP_X = 20
const GAP_Y = 44
const MARGIN = 16

/** Hijos por padre; un padre que no está en la lista deja al nodo como raíz. */
export function childrenMap(nodes: ChartNode[]): { roots: string[]; children: Map<string, string[]> } {
  const ids = new Set(nodes.map((n) => n.id))
  const children = new Map<string, string[]>()
  const roots: string[] = []
  for (const n of nodes) {
    if (n.parent_id && ids.has(n.parent_id) && n.parent_id !== n.id) {
      const list = children.get(n.parent_id) ?? []
      list.push(n.id)
      children.set(n.parent_id, list)
    } else {
      roots.push(n.id)
    }
  }
  return { roots, children }
}

/** Ancestros de cada nodo que coincide con la búsqueda: hay que desplegarlos para verlo. */
export function ancestorsOf(nodes: ChartNode[], matches: Set<string>): Set<string> {
  const parent = new Map(nodes.map((n) => [n.id, n.parent_id]))
  const out = new Set<string>()
  for (const id of matches) {
    let cur = parent.get(id) ?? null
    const seen = new Set<string>([id])
    while (cur && !seen.has(cur)) {
      out.add(cur)
      seen.add(cur)
      cur = parent.get(cur) ?? null
    }
  }
  return out
}

/** Nodos con hijos a partir de cierta profundidad: el organigrama abre plegado desde ahí. */
export function collapsedFromDepth(nodes: ChartNode[], depth: number): Set<string> {
  const { roots, children } = childrenMap(nodes)
  const out = new Set<string>()
  const walk = (id: string, d: number, seen: Set<string>) => {
    if (seen.has(id)) return
    seen.add(id)
    const kids = children.get(id) ?? []
    if (kids.length > 0 && d >= depth) out.add(id)
    kids.forEach((k) => walk(k, d + 1, seen))
  }
  const seen = new Set<string>()
  roots.forEach((r) => walk(r, 0, seen))
  return out
}

export function layoutChart(nodes: ChartNode[], collapsed: Set<string>): Layout {
  const byId = new Map(nodes.map((n) => [n.id, n]))
  const { roots, children } = childrenMap(nodes)
  const width = new Map<string, number>()
  const visiting = new Set<string>()

  const measure = (id: string): number => {
    if (visiting.has(id)) return NODE_W
    visiting.add(id)
    const kids = collapsed.has(id) ? [] : children.get(id) ?? []
    const w = kids.length === 0 ? NODE_W : Math.max(NODE_W, kids.reduce((s, k) => s + measure(k), 0) + GAP_X * (kids.length - 1))
    width.set(id, w)
    return w
  }

  const placed: PlacedNode[] = []
  const edges: Edge[] = []
  let maxY = 0
  const place = (id: string, left: number, depth: number, done: Set<string>) => {
    if (done.has(id)) return
    done.add(id)
    const node = byId.get(id)
    if (!node) return
    const w = width.get(id) ?? NODE_W
    const x = left + (w - NODE_W) / 2
    const y = MARGIN + depth * (NODE_H + GAP_Y)
    maxY = Math.max(maxY, y + NODE_H)
    const all = children.get(id) ?? []
    const isCollapsed = collapsed.has(id) && all.length > 0
    placed.push({ ...node, x, y, depth, childCount: all.length, collapsed: isCollapsed })
    if (isCollapsed) return
    let cursor = left
    for (const k of all) {
      const kw = width.get(k) ?? NODE_W
      const cx = cursor + kw / 2
      const px = x + NODE_W / 2
      const midY = y + NODE_H + GAP_Y / 2
      edges.push({ from: id, to: k, path: `M${px},${y + NODE_H} V${midY} H${cx} V${y + NODE_H + GAP_Y}` })
      place(k, cursor, depth + 1, done)
      cursor += kw + GAP_X
    }
  }

  let left = MARGIN
  const done = new Set<string>()
  for (const r of roots) {
    const w = measure(r)
    place(r, left, 0, done)
    left += w + GAP_X * 2
  }
  // Un ciclo en los datos que no tenga raíz queda igual a la vista, al final (lo que está debajo de
  // una rama plegada sí tiene raíz y no se dibuja).
  const reachable = new Set<string>()
  const stack = [...roots]
  while (stack.length > 0) {
    const id = stack.pop() as string
    if (reachable.has(id)) continue
    reachable.add(id)
    stack.push(...(children.get(id) ?? []))
  }
  for (const n of nodes) {
    if (!done.has(n.id) && !reachable.has(n.id)) {
      reachable.add(n.id)
      for (const k of children.get(n.id) ?? []) stack.push(k)
      while (stack.length > 0) {
        const id = stack.pop() as string
        if (reachable.has(id)) continue
        reachable.add(id)
        stack.push(...(children.get(id) ?? []))
      }
      const w = measure(n.id)
      place(n.id, left, 0, done)
      left += w + GAP_X * 2
    }
  }

  return { nodes: placed, edges, width: Math.max(left - GAP_X * 2 + MARGIN, NODE_W + MARGIN * 2), height: maxY + MARGIN }
}

/** Recorta un texto para que entre en la caja (las cajas tienen ancho fijo). */
export function fit(text: string | null | undefined, max: number): string {
  const s = (text ?? '').trim()
  return s.length <= max ? s : `${s.slice(0, max - 1).trimEnd()}…`
}

const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

export type Palette = { bg: string; box: string; border: string; title: string; text: string; muted: string; line: string; accent: string }

/** El organigrama como SVG autónomo (fuentes del sistema y los colores indicados), para imagen y PDF. */
export function chartSvg(layout: Layout, palette: Palette, heading?: string): string {
  const top = heading ? 36 : 0
  const w = Math.ceil(layout.width)
  const h = Math.ceil(layout.height + top)
  const parts: string[] = [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" font-family="Helvetica, Arial, sans-serif">`,
    `<rect width="${w}" height="${h}" fill="${palette.bg}"/>`,
  ]
  if (heading) parts.push(`<text x="16" y="24" font-size="15" font-weight="700" fill="${palette.title}">${esc(heading)}</text>`)
  parts.push(`<g transform="translate(0,${top})">`)
  for (const e of layout.edges) parts.push(`<path d="${e.path}" fill="none" stroke="${palette.line}" stroke-width="1.5"/>`)
  for (const n of layout.nodes) {
    parts.push(`<rect x="${n.x}" y="${n.y}" width="${NODE_W}" height="${NODE_H}" rx="8" fill="${palette.box}" stroke="${palette.border}"/>`)
    parts.push(`<rect x="${n.x}" y="${n.y}" width="4" height="${NODE_H}" rx="2" fill="${palette.accent}"/>`)
    parts.push(`<text x="${n.x + 14}" y="${n.y + 24}" font-size="13" font-weight="700" fill="${palette.title}">${esc(fit(n.title, 28))}</text>`)
    if (n.subtitle) parts.push(`<text x="${n.x + 14}" y="${n.y + 43}" font-size="11.5" fill="${palette.text}">${esc(fit(n.subtitle, 32))}</text>`)
    if (n.detail) parts.push(`<text x="${n.x + 14}" y="${n.y + 60}" font-size="11" fill="${palette.muted}">${esc(fit(n.detail, 34))}</text>`)
  }
  parts.push('</g></svg>')
  return parts.join('')
}
