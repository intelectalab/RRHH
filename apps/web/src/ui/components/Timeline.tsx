import type { ReactNode } from 'react'

export type TimelineTone = 'ok' | 'wa' | 'er' | 'gr'

export type TimelineItem = { id: string; title: ReactNode; when?: ReactNode; text?: ReactNode; tone?: TimelineTone }

/**
 * UI-3 · Línea de tiempo vertical (historia de un registro, pasos de una aprobación). El tono del punto
 * acompaña al texto; nunca es la única señal del estado.
 */
export function Timeline({ items, label }: { items: TimelineItem[]; label: string }) {
  return (
    <ol className="traza" aria-label={label}>
      {items.map((item) => (
        <li key={item.id} className={item.tone && item.tone !== 'gr' ? item.tone : undefined}>
          <b>{item.title}</b>
          {item.when && <span className="sub2">{item.when}</span>}
          {item.text && <div className="small text-secondary">{item.text}</div>}
        </li>
      ))}
    </ol>
  )
}
