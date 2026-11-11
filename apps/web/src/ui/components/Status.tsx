import type { ReactNode } from 'react'

export type StatusTone = 'ok' | 'wa' | 'er' | 'in' | 'gr'
export type TagTone = 'ok' | 'wa' | 'er' | 'in' | 'gr' | 'pe' | 'oc'

/** UI-3 · Etiqueta de estado: punto de color + texto (el texto siempre dice el estado). */
export function StatusBadge({ tone, children }: { tone: StatusTone; children: ReactNode }) {
  return <span className={`st ${tone}`}>{children}</span>
}

/** UI-3 · Etiqueta compacta para categorías, contadores o marcas («Por validar», «Del paquete»). */
export function Tag({ tone = 'gr', children }: { tone?: TagTone; children: ReactNode }) {
  return <span className={`tag ${tone}`}>{children}</span>
}
