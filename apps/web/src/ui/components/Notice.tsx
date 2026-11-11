'use client'

import type { ReactNode } from 'react'
import { useT } from '@/lib/useT'
import { Icon } from './Icon'

export type NoticeTone = 'info' | 'ok' | 'warn' | 'error'

/**
 * UI-3 · Aviso dentro de la página (no flotante): informa, confirma, advierte o explica un error.
 * Los errores se anuncian de inmediato (role=alert); los demás, con cortesía (role=status).
 * Se puede cerrar solo si se pasa onDismiss.
 */
export function Notice({ tone = 'info', title, children, action, onDismiss }: { tone?: NoticeTone; title?: ReactNode; children?: ReactNode; action?: ReactNode; onDismiss?: () => void }) {
  const { t } = useT()
  return (
    <div className={`notice notice-${tone}`} role={tone === 'error' ? 'alert' : 'status'}>
      <div className="notice-t">
        {title && <b>{title}</b>}
        {children && <div>{children}</div>}
        {action && <div className="mt-2">{action}</div>}
      </div>
      {onDismiss && (
        <button type="button" className="ibtn" onClick={onDismiss} aria-label={t('ui.dismiss')}>
          <Icon name="close" size={16} />
        </button>
      )}
    </div>
  )
}
