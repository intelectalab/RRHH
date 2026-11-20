'use client'

import { useId, useState, type ReactNode } from 'react'

/**
 * UI-10 · Divulgación progresiva: lo esencial a la vista y lo avanzado plegado detrás de un botón con su
 * resumen («Más opciones: prioridad, descripción»). Si un campo plegado trae un error del servidor, la sección
 * se abre sola (`forceOpen`) para que el error quede junto a su campo (RN-UI-05). El contenido plegado sigue
 * en el DOM solo cuando está abierto: lo que no se ve no se envía por accidente.
 */
export function Disclosure({ label, summary, children, defaultOpen = false, forceOpen = false }: {
  label: string
  summary?: string
  children: ReactNode
  defaultOpen?: boolean
  forceOpen?: boolean
}) {
  const [open, setOpen] = useState(defaultOpen)
  const id = useId()
  const shown = open || forceOpen
  return (
    <div className={`plegable${shown ? ' abierto' : ''}`}>
      <button type="button" className="plegable-btn" aria-expanded={shown} aria-controls={id} onClick={() => setOpen(!shown)}>
        <span aria-hidden="true">{shown ? '▾' : '▸'}</span> {label}
        {!shown && summary && <span className="plegable-resumen">{summary}</span>}
      </button>
      {shown && <div id={id} className="plegable-c">{children}</div>}
    </div>
  )
}
