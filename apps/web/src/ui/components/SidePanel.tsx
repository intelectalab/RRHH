'use client'

import { useId, useRef, type ReactNode } from 'react'
import { useFocusTrap } from '../a11y'
import { Icon } from './Icon'

/**
 * Panel lateral derecho (cajón) para formularios y detalles. UI-3: el foco queda dentro mientras está
 * abierto, Escape y el fondo lo cierran y el foco vuelve al control que lo abrió.
 */
export function SidePanel({ title, onClose, children, footer, closeLabel, wide = false }: { title: string; onClose: () => void; children: ReactNode; footer?: ReactNode; closeLabel: string; wide?: boolean }) {
  const ref = useRef<HTMLElement>(null)
  const titleId = useId()
  useFocusTrap(ref, true, onClose)

  return (
    <>
      <div className="panel-fondo" onClick={onClose} aria-hidden="true" />
      <aside ref={ref} className={`panel${wide ? ' panel-ancho' : ''}`} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1}>
        <div className="panel-cab">
          <h2 id={titleId}>{title}</h2>
          <button type="button" className="ibtn" onClick={onClose} aria-label={closeLabel}>
            <Icon name="close" />
          </button>
        </div>
        <div className="panel-cuerpo">{children}</div>
        {footer && <div className="panel-pie">{footer}</div>}
      </aside>
    </>
  )
}
