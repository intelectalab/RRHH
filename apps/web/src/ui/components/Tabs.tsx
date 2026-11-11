'use client'

import { useId, useRef, type KeyboardEvent, type ReactNode } from 'react'
import { nextIndex } from '../a11y'

export type TabItem = { id: string; label: ReactNode; content: ReactNode; disabled?: boolean; count?: number }

/**
 * UI-3 · Pestañas dentro de una pantalla (patrón tablist de WAI-ARIA): flechas, Inicio y Fin mueven
 * el foco y activan la pestaña; las deshabilitadas se saltan. Para pestañas que cambian de ruta se usa
 * el encabezado de página (PageHeader).
 */
export function Tabs({ items, value, onChange, label }: { items: TabItem[]; value: string; onChange: (id: string) => void; label: string }) {
  const base = useId()
  const refs = useRef<Array<HTMLButtonElement | null>>([])
  const current = Math.max(0, items.findIndex((i) => i.id === value))
  const active = items[current]

  function onKey(e: KeyboardEvent<HTMLDivElement>) {
    const next = nextIndex(items.length, current, e.key, (i) => !!items[i]?.disabled)
    if (next === null) return
    e.preventDefault()
    const item = items[next]
    if (!item) return
    onChange(item.id)
    refs.current[next]?.focus()
  }

  return (
    <div className="ptabs">
      <div role="tablist" aria-label={label} className="ptabs-lista" onKeyDown={onKey}>
        {items.map((item, i) => {
          const selected = i === current
          return (
            <button
              key={item.id}
              ref={(el) => { refs.current[i] = el }}
              type="button"
              role="tab"
              id={`${base}-t-${item.id}`}
              aria-selected={selected}
              aria-controls={`${base}-p-${item.id}`}
              tabIndex={selected ? 0 : -1}
              disabled={item.disabled}
              className={selected ? 'on' : undefined}
              onClick={() => onChange(item.id)}
            >
              {item.label}
              {item.count !== undefined && <span className="n">{item.count}</span>}
            </button>
          )
        })}
      </div>
      {active && (
        <div role="tabpanel" id={`${base}-p-${active.id}`} aria-labelledby={`${base}-t-${active.id}`} tabIndex={0} className="ptabs-panel">
          {active.content}
        </div>
      )}
    </div>
  )
}
