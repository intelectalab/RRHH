'use client'

import { useEffect, type ReactNode } from 'react'
import { Icon } from './Icon'

/** Panel lateral derecho para formularios cortos; se cierra con Escape o el fondo. */
export function SidePanel({ title, onClose, children, footer, closeLabel }: { title: string; onClose: () => void; children: ReactNode; footer?: ReactNode; closeLabel: string }) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <>
      <div className="panel-fondo" onClick={onClose} aria-hidden="true" />
      <aside className="panel" role="dialog" aria-modal="true" aria-label={title}>
        <div className="panel-cab">
          <h2>{title}</h2>
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
