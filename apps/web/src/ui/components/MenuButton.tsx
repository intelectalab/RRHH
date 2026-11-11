'use client'

import { useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { nextIndex } from '../a11y'
import { Icon } from './Icon'

type IconName = Parameters<typeof Icon>[0]['name']

export type MenuAction = { id: string; label: ReactNode; onSelect: () => void; icon?: IconName; danger?: boolean; disabled?: boolean }

/**
 * UI-3 · Menú de acciones (patrón menu button de WAI-ARIA). Flecha abajo o Enter abre y enfoca la
 * primera acción; flechas, Inicio y Fin recorren; Escape cierra y devuelve el foco al botón; un clic
 * fuera cierra. Solo se listan acciones que existen (RN-UI-01): el llamador filtra por permiso.
 */
export function MenuButton({ label, items, icon = 'chevron', iconOnly = false, align = 'right' }: { label: string; items: MenuAction[]; icon?: IconName; iconOnly?: boolean; align?: 'left' | 'right' }) {
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const id = useId()
  const wrap = useRef<HTMLDivElement>(null)
  const button = useRef<HTMLButtonElement>(null)
  const refs = useRef<Array<HTMLButtonElement | null>>([])
  const disabled = (i: number) => !!items[i]?.disabled

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => { if (wrap.current && !wrap.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [open])

  useEffect(() => { if (open) refs.current[active]?.focus() }, [open, active])

  function openAt(key: 'Home' | 'End') {
    const i = nextIndex(items.length, 0, key, disabled)
    if (i === null) return
    setActive(i)
    setOpen(true)
  }

  function close(refocus = true) {
    setOpen(false)
    if (refocus) button.current?.focus()
  }

  function onButtonKey(e: KeyboardEvent<HTMLButtonElement>) {
    if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openAt('Home') }
    if (e.key === 'ArrowUp') { e.preventDefault(); openAt('End') }
  }

  function onListKey(e: KeyboardEvent<HTMLDivElement>) {
    if (e.key === 'Escape') { e.preventDefault(); close(); return }
    if (e.key === 'Tab') { setOpen(false); return }
    const i = nextIndex(items.length, active, e.key, disabled)
    if (i !== null) { e.preventDefault(); setActive(i) }
  }

  if (items.length === 0) return null

  return (
    <div className="menu" ref={wrap}>
      <button ref={button} type="button" className={iconOnly ? 'ibtn' : 'btn btn-sec btn-sm'} aria-haspopup="menu" aria-expanded={open} aria-controls={open ? id : undefined} aria-label={iconOnly ? label : undefined} onClick={() => (open ? close(false) : openAt('Home'))} onKeyDown={onButtonKey}>
        {!iconOnly && label}
        <Icon name={icon} size={16} />
      </button>
      {open && (
        <div id={id} role="menu" aria-label={label} className={`menu-lista${align === 'left' ? ' izq' : ''}`} onKeyDown={onListKey}>
          {items.map((item, i) => (
            <button
              key={item.id}
              ref={(el) => { refs.current[i] = el }}
              type="button"
              role="menuitem"
              tabIndex={i === active ? 0 : -1}
              disabled={item.disabled}
              className={`menu-item${item.danger ? ' peligro' : ''}`}
              onClick={() => { close(); item.onSelect() }}
            >
              {item.icon && <Icon name={item.icon} />}
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
