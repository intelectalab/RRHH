/**
 * WFL-6 · Utilidades puras del lienzo del constructor (sin reglas de negocio: la validación que vale es la
 * del servidor; aquí solo se dibuja y se edita).
 */
export type NodeKind = 'start' | 'condition' | 'approval' | 'action' | 'notification' | 'wait' | 'end'
export type FlowNode = { id: string; kind: NodeKind; label: string; x: number; y: number; config: Record<string, unknown> }
export type FlowEdge = { from: string; to: string; branch: 'yes' | 'no' | null }
export type FlowGraph = { nodes: FlowNode[]; edges: FlowEdge[] }

export const NODE_W = 184
export const NODE_H = 64
export const ADDABLE: NodeKind[] = ['condition', 'approval', 'action', 'notification', 'wait', 'end']

/** Identificador libre para un nodo nuevo del tipo dado. */
export function nextId(graph: FlowGraph, kind: NodeKind): string {
  const used = new Set(graph.nodes.map((n) => n.id))
  for (let i = 1; ; i++) {
    const id = `${kind}_${i}`
    if (!used.has(id)) return id
  }
}

/** Agrega un nodo debajo del último, para que aparezca a la vista. */
export function addNode(graph: FlowGraph, kind: NodeKind): { graph: FlowGraph; id: string } {
  const id = nextId(graph, kind)
  const bottom = graph.nodes.reduce((m, n) => Math.max(m, n.y), 0)
  const node: FlowNode = { id, kind, label: '', x: 40, y: bottom + NODE_H + 48, config: kind === 'wait' ? { days: 1 } : kind === 'condition' ? { match: 'all', rules: [] } : {} }
  return { graph: { ...graph, nodes: [...graph.nodes, node] }, id }
}

/** Quita el nodo y sus conexiones. */
export function removeNode(graph: FlowGraph, id: string): FlowGraph {
  return { nodes: graph.nodes.filter((n) => n.id !== id), edges: graph.edges.filter((e) => e.from !== id && e.to !== id) }
}

/**
 * Conecta dos nodos. Una condición tiene una salida por rama; los demás nodos, una sola salida
 * (conectar de nuevo reemplaza la anterior). Un nodo no se conecta consigo mismo.
 */
export function connect(graph: FlowGraph, from: string, to: string, branch: 'yes' | 'no' | null): FlowGraph {
  if (from === to) return graph
  const kept = graph.edges.filter((e) => !(e.from === from && e.branch === branch))
  return { ...graph, edges: [...kept, { from, to, branch }] }
}

export function disconnect(graph: FlowGraph, from: string, branch: 'yes' | 'no' | null): FlowGraph {
  return { ...graph, edges: graph.edges.filter((e) => !(e.from === from && e.branch === branch)) }
}

export function moveNode(graph: FlowGraph, id: string, x: number, y: number): FlowGraph {
  return { ...graph, nodes: graph.nodes.map((n) => (n.id === id ? { ...n, x: Math.max(0, Math.round(x)), y: Math.max(0, Math.round(y)) } : n)) }
}

export function patchNode(graph: FlowGraph, id: string, change: Partial<Pick<FlowNode, 'label' | 'config'>>): FlowGraph {
  return { ...graph, nodes: graph.nodes.map((n) => (n.id === id ? { ...n, ...change } : n)) }
}

/** Puntos de una conexión: del borde inferior del origen al borde superior del destino. */
export function edgePath(from: FlowNode, to: FlowNode, branch: 'yes' | 'no' | null): { d: string; mx: number; my: number } {
  const offset = branch === 'yes' ? -NODE_W / 4 : branch === 'no' ? NODE_W / 4 : 0
  const x1 = from.x + NODE_W / 2 + offset
  const y1 = from.y + NODE_H
  const x2 = to.x + NODE_W / 2
  const y2 = to.y
  const c = Math.max(24, Math.abs(y2 - y1) / 2)
  return { d: `M ${x1} ${y1} C ${x1} ${y1 + c}, ${x2} ${y2 - c}, ${x2} ${y2}`, mx: (x1 + x2) / 2, my: (y1 + y2) / 2 }
}

/** Tamaño del lienzo para que todos los nodos quepan. */
export function canvasSize(graph: FlowGraph): { w: number; h: number } {
  return {
    w: Math.max(640, ...graph.nodes.map((n) => n.x + NODE_W + 40)),
    h: Math.max(420, ...graph.nodes.map((n) => n.y + NODE_H + 40)),
  }
}
