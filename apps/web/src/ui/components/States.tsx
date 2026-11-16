'use client'

import { useContext, type ReactNode } from 'react'
import { HelpOpenerContext } from '../helpContext'

/**
 * Estado vacío con explicación y, si el usuario puede, la acción que corresponde (UI-6). Dentro de la
 * aplicación enlaza a la ayuda de la pantalla y a la guía de su módulo (AYU-3).
 */
export function EmptyState({ title, text, action }: { title: string; text: string; action?: ReactNode }) {
  const help = useContext(HelpOpenerContext)
  return (
    <div className="vacio">
      <b>{title}</b>
      {text}
      {action && <div className="mt-2">{action}</div>}
      {help && <div className="mt-2"><button type="button" className="btn btn-link btn-sm" onClick={help.open}>{help.label}</button></div>}
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
