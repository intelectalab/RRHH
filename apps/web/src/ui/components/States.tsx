'use client'

import type { ReactNode } from 'react'

/** Estado vacío con explicación y, si el usuario puede, la acción que corresponde (UI-6). */
export function EmptyState({ title, text, action }: { title: string; text: string; action?: ReactNode }) {
  return (
    <div className="vacio">
      <b>{title}</b>
      {text}
      {action && <div className="mt-2">{action}</div>}
    </div>
  )
}

/** Error de carga con reintento. */
export function ErrorState({ text, retryLabel, onRetry }: { text: string; retryLabel: string; onRetry: () => void }) {
  return (
    <div className="vacio" role="alert">
      <b>{text}</b>
      <div className="mt-2"><button type="button" className="btn btn-sec btn-sm" onClick={onRetry}>{retryLabel}</button></div>
    </div>
  )
}
