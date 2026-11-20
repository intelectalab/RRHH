import { describe, expect, it } from 'vitest'
import { addNode, canvasSize, connect, disconnect, edgePath, moveNode, NODE_H, NODE_W, removeNode, type FlowGraph } from '@/modules/workflow/flowGraph'
import { HELP_SCREENS } from '@/modules/help/screens'
import es from '../../messages/es.json'
import en from '../../messages/en.json'
import pt from '../../messages/pt.json'

const base: FlowGraph = {
  nodes: [
    { id: 'start', kind: 'start', label: '', x: 40, y: 40, config: {} },
    { id: 'end', kind: 'end', label: '', x: 40, y: 320, config: {} },
  ],
  edges: [],
}

describe('WFL-6 · lienzo del constructor', () => {
  it('agrega nodos con identificador libre debajo del último', () => {
    const a = addNode(base, 'condition')
    const b = addNode(a.graph, 'condition')
    expect(a.id).toBe('condition_1')
    expect(b.id).toBe('condition_2')
    expect(b.graph.nodes.at(-1)!.y).toBeGreaterThan(a.graph.nodes.at(-1)!.y)
    expect(a.graph.nodes.at(-1)!.config).toEqual({ match: 'all', rules: [] })
    expect(addNode(base, 'wait').graph.nodes.at(-1)!.config).toEqual({ days: 1 })
  })

  it('una condición tiene una salida por rama y los demás nodos una sola salida', () => {
    let g = addNode(base, 'condition').graph
    g = connect(g, 'condition_1', 'end', 'yes')
    g = connect(g, 'condition_1', 'start', 'no')
    expect(g.edges).toHaveLength(2)
    g = connect(g, 'condition_1', 'end', 'no')
    expect(g.edges.filter((e) => e.from === 'condition_1' && e.branch === 'no')).toEqual([{ from: 'condition_1', to: 'end', branch: 'no' }])
    g = connect(g, 'start', 'condition_1', null)
    g = connect(g, 'start', 'end', null)
    expect(g.edges.filter((e) => e.from === 'start')).toEqual([{ from: 'start', to: 'end', branch: null }])
    expect(connect(g, 'end', 'end', null)).toBe(g)
    expect(disconnect(g, 'condition_1', 'yes').edges.some((e) => e.branch === 'yes')).toBe(false)
  })

  it('quitar un nodo quita sus conexiones; mover no sale del lienzo', () => {
    const g = connect(connect(addNode(base, 'action').graph, 'start', 'action_1', null), 'action_1', 'end', null)
    expect(removeNode(g, 'action_1').edges).toEqual([])
    const moved = moveNode(g, 'action_1', -50, 12.6)
    expect(moved.nodes.find((n) => n.id === 'action_1')).toMatchObject({ x: 0, y: 13 })
  })

  it('las conexiones van del borde inferior al superior y el lienzo crece con los nodos', () => {
    const p = edgePath(base.nodes[0], base.nodes[1], null)
    expect(p.d.startsWith(`M ${40 + NODE_W / 2} ${40 + NODE_H}`)).toBe(true)
    expect(p.d.endsWith(`${40 + NODE_W / 2} 320`)).toBe(true)
    expect(canvasSize(moveNode(base, 'end', 900, 900))).toEqual({ w: 900 + NODE_W + 40, h: 900 + NODE_H + 40 })
  })

  it('la pantalla tiene su ayuda y los textos existen en los tres idiomas', () => {
    expect(HELP_SCREENS.some((s) => s.route === '/configuracion/constructor' && s.key === 'settings.flow_builder')).toBe(true)
    for (const m of [es, en, pt] as unknown as Array<Record<string, Record<string, string>>>) {
      expect(Object.keys(m.vflow).sort()).toEqual(Object.keys(es.vflow).sort())
      expect(m.settings.tab_builder).toBeTruthy()
    }
  })
})
