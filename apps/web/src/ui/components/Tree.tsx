'use client'

import { useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'

export type TreeNode = { id: string; label: ReactNode; text: string; children?: TreeNode[]; meta?: ReactNode }

/** Nodos visibles en orden (los hijos solo si su padre está abierto), con su nivel y su padre. */
export function visibleNodes(nodes: TreeNode[], open: Set<string>, level = 1, parent: string | null = null): Array<{ node: TreeNode; level: number; parent: string | null }> {
  return nodes.flatMap((n) => [
    { node: n, level, parent },
    ...(n.children && n.children.length > 0 && open.has(n.id) ? visibleNodes(n.children, open, level + 1, n.id) : []),
  ])
}

/**
 * UI-12 · Árbol jerárquico (patrón tree de WAI-ARIA): flechas arriba y abajo recorren, derecha abre o entra
 * al primer hijo, izquierda cierra o sube al padre, Inicio y Fin van a los extremos, Enter elige. Con el dedo,
 * el triángulo abre y cierra y tocar el nombre elige.
 */
export function Tree({ nodes, label, onSelect, selected, defaultOpen = [] }: {
  nodes: TreeNode[]
  label: string
  onSelect?: (node: TreeNode) => void
  selected?: string | null
  defaultOpen?: string[]
}) {
  const [open, setOpen] = useState<Set<string>>(new Set(defaultOpen))
  const [focus, setFocus] = useState<string | null>(nodes[0]?.id ?? null)
  const refs = useRef(new Map<string, HTMLLIElement>())
  const list = useMemo(() => visibleNodes(nodes, open), [nodes, open])

  const toggle = (id: string, value?: boolean) => setOpen((o) => {
    const next = new Set(o)
    if (value ?? !next.has(id)) next.add(id)
    else next.delete(id)
    return next
  })
  const go = (id: string | null | undefined) => {
    if (!id) return
    setFocus(id)
    refs.current.get(id)?.focus()
  }

  function onKey(e: KeyboardEvent, index: number) {
    const item = list[index]
    if (!item) return
    const hasKids = (item.node.children?.length ?? 0) > 0
    const handled = true
    switch (e.key) {
      case 'ArrowDown': go(list[index + 1]?.node.id); break
      case 'ArrowUp': go(list[index - 1]?.node.id); break
      case 'Home': go(list[0]?.node.id); break
      case 'End': go(list[list.length - 1]?.node.id); break
      case 'ArrowRight':
        if (hasKids && !open.has(item.node.id)) toggle(item.node.id, true)
        else if (hasKids) go(list[index + 1]?.node.id)
        break
      case 'ArrowLeft':
        if (hasKids && open.has(item.node.id)) toggle(item.node.id, false)
        else go(item.parent)
        break
      case 'Enter':
      case ' ':
        onSelect?.(item.node)
        break
      default:
        return
    }
    if (handled) e.preventDefault()
  }

  const render = (items: TreeNode[], level: number): ReactNode => (
    <ul role={level === 1 ? 'tree' : 'group'} aria-label={level === 1 ? label : undefined} className={level === 1 ? 'arbol' : 'arbol-grupo'}>
      {items.map((n) => {
        const hasKids = (n.children?.length ?? 0) > 0
        const index = list.findIndex((v) => v.node.id === n.id)
        return (
          <li
            key={n.id}
            role="treeitem"
            aria-level={level}
            aria-expanded={hasKids ? open.has(n.id) : undefined}
            aria-selected={selected === n.id}
            tabIndex={focus === n.id ? 0 : -1}
            ref={(el) => { if (el) refs.current.set(n.id, el); else refs.current.delete(n.id) }}
            onKeyDown={(e) => { if (e.target === e.currentTarget) onKey(e, index) }}
            onFocus={(e) => { if (e.target === e.currentTarget) setFocus(n.id) }}
          >
            <div className={`arbol-fila${selected === n.id ? ' sel' : ''}`} style={{ paddingLeft: (level - 1) * 18 }}>
              {hasKids
                ? <button type="button" tabIndex={-1} className="arbol-flecha" aria-hidden="true" onClick={() => toggle(n.id)}>{open.has(n.id) ? '▾' : '▸'}</button>
                : <span className="arbol-flecha" aria-hidden="true" />}
              <span className="arbol-nombre" onClick={() => { setFocus(n.id); onSelect?.(n) }}>{n.label}</span>
              {n.meta && <span className="arbol-meta">{n.meta}</span>}
            </div>
            {hasKids && open.has(n.id) && render(n.children ?? [], level + 1)}
          </li>
        )
      })}
    </ul>
  )

  return render(nodes, 1)
}
