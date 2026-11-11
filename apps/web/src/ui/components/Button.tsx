'use client'

import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { Icon } from './Icon'

type IconName = Parameters<typeof Icon>[0]['name']

export type ButtonVariant = 'primary' | 'secondary' | 'tertiary' | 'danger'

const VARIANT: Record<ButtonVariant, string> = {
  primary: 'btn-primary',
  secondary: 'btn-sec',
  tertiary: 'btn-ter',
  danger: 'btn-pel-sec',
}

/**
 * UI-3 · Botón base. Estados: normal, foco (anillo visible con teclado), deshabilitado y cargando
 * (deshabilitado con indicador y aria-busy, para que no se envíe dos veces).
 */
export function Button({ variant = 'secondary', size, icon, loading = false, block = false, className, children, disabled, type = 'button', ...rest }: Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> & {
  variant?: ButtonVariant
  size?: 'sm' | 'lg'
  icon?: IconName
  loading?: boolean
  block?: boolean
  children: ReactNode
}) {
  const classes = ['btn', VARIANT[variant], size === 'sm' ? 'btn-sm' : size === 'lg' ? 'btn-grande' : '', block ? 'btn-bloque' : '', className ?? ''].filter(Boolean).join(' ')
  return (
    <button type={type} className={classes} disabled={disabled || loading} aria-busy={loading || undefined} {...rest}>
      {loading ? <span className="spin" aria-hidden="true" /> : icon ? <Icon name={icon} size={16} /> : null}
      {children}
    </button>
  )
}
