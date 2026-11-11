'use client'

import { useId, useRef, type ReactNode } from 'react'
import { useT } from '@/lib/useT'
import { useFocusTrap } from '../a11y'
import { Icon } from './Icon'

/**
 * UI-3 · Diálogo modal centrado para confirmar o completar algo breve. El foco queda dentro mientras
 * está abierto, Escape y el fondo lo cierran y, al cerrar, el foco vuelve al control que lo abrió.
 * Para formularios largos se usa el panel lateral (SidePanel).
 */
export function Modal({ title, onClose, children, footer, tone = 'normal', size = 'm' }: {
  title: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
  /** «danger» para confirmaciones irreversibles: el diálogo se anuncia como alertdialog. */
  tone?: 'normal' | 'danger'
  size?: 's' | 'm' | 'l'
}) {
  const { t } = useT()
  const ref = useRef<HTMLDivElement>(null)
  const titleId = useId()
  useFocusTrap(ref, true, onClose)

  return (
    <>
      <div className="panel-fondo" onClick={onClose} aria-hidden="true" />
      <div ref={ref} className={`modal modal-${size}`} role={tone === 'danger' ? 'alertdialog' : 'dialog'} aria-modal="true" aria-labelledby={titleId} tabIndex={-1}>
        <div className="panel-cab">
          <h2 id={titleId}>{title}</h2>
          <button type="button" className="ibtn" onClick={onClose} aria-label={t('ui.close')}>
            <Icon name="close" />
          </button>
        </div>
        <div className="panel-cuerpo">{children}</div>
        {footer && <div className="panel-pie">{footer}</div>}
      </div>
    </>
  )
}
