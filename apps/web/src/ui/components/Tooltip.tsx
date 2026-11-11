'use client'

import { cloneElement, isValidElement, useEffect, useId, useState, type ReactElement, type ReactNode } from 'react'

/**
 * UI-3 · Ayuda breve sobre un control. Aparece con el puntero y con el foco del teclado, se oculta con
 * Escape y queda enlazada al control con aria-describedby. No reemplaza la etiqueta del control ni
 * contiene acciones (para eso, un menú o un panel).
 */
export function Tooltip({ text, children, side = 'top' }: { text: ReactNode; children: ReactElement<Record<string, unknown>>; side?: 'top' | 'bottom' }) {
  const id = useId()
  const [open, setOpen] = useState(false)

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open])

  if (!isValidElement(children)) return children
  const trigger = cloneElement(children, { 'aria-describedby': id })

  return (
    <span className="tip-ancla" onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)} onFocus={() => setOpen(true)} onBlur={() => setOpen(false)}>
      {trigger}
      <span id={id} role="tooltip" className={`tip tip-${side}${open ? ' on' : ''}`}>{text}</span>
    </span>
  )
}
