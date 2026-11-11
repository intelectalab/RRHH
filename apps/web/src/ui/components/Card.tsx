import type { ReactNode } from 'react'

/** UI-3 · Tarjeta con encabezado opcional (título, contexto y acciones), cuerpo y pie. */
export function Card({ title, context, actions, footer, flush = false, children, id }: {
  title?: ReactNode
  context?: ReactNode
  actions?: ReactNode
  footer?: ReactNode
  /** Sin relleno en el cuerpo: para listas y tablas que llegan al borde. */
  flush?: boolean
  children: ReactNode
  id?: string
}) {
  return (
    <section className="card" id={id} aria-labelledby={title && id ? `${id}-t` : undefined}>
      {(title || actions) && (
        <div className="card-header">
          <div className="min-w-0">
            {title && <h2 id={id ? `${id}-t` : undefined}>{title}</h2>}
            {context && <span className="ctx">{context}</span>}
          </div>
          {actions && <div className="fila-acciones">{actions}</div>}
        </div>
      )}
      {flush ? children : <div className="card-body">{children}</div>}
      {footer && <div className="card-pie">{footer}</div>}
    </section>
  )
}
