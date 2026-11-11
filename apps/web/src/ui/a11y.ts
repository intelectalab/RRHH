'use client'

import { useEffect, useRef, type RefObject } from 'react'

/**
 * UI-3 · Ayudas de teclado compartidas por la biblioteca de componentes (pestañas, menús, selectores).
 * La lógica de navegación es pura para poder probarla sin navegador.
 */

export type NavKey = 'ArrowDown' | 'ArrowUp' | 'ArrowLeft' | 'ArrowRight' | 'Home' | 'End'

const NEXT = new Set(['ArrowDown', 'ArrowRight'])
const PREV = new Set(['ArrowUp', 'ArrowLeft'])

/**
 * Índice siguiente al pulsar una tecla de navegación, saltando los elementos deshabilitados y dando la vuelta.
 * Devuelve null si la tecla no navega o si no hay ningún elemento habilitado.
 */
export function nextIndex(count: number, current: number, key: string, disabled: (i: number) => boolean = () => false): number | null {
  if (count <= 0) return null
  const enabled = Array.from({ length: count }, (_, i) => i).filter((i) => !disabled(i))
  if (enabled.length === 0) return null
  if (key === 'Home') return enabled[0] ?? null
  if (key === 'End') return enabled[enabled.length - 1] ?? null
  if (!NEXT.has(key) && !PREV.has(key)) return null
  const step = NEXT.has(key) ? 1 : -1
  for (let n = 1; n <= count; n++) {
    const i = (((current + step * n) % count) + count) % count
    if (!disabled(i)) return i
  }
  return null
}

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

export function focusableIn(root: HTMLElement): HTMLElement[] {
  return Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((el) => !el.hasAttribute('aria-hidden'))
}

/**
 * Mantiene el foco dentro de un diálogo mientras está abierto: Tab y Mayús+Tab dan la vuelta, Escape
 * llama a onEscape y, al cerrar, el foco vuelve al control que lo abrió.
 */
export function useFocusTrap(ref: RefObject<HTMLElement | null>, active: boolean, onEscape?: () => void): void {
  // El manejador vive en una referencia: si el padre pasa una función nueva en cada render, el foco no salta.
  const escape = useRef(onEscape)
  useEffect(() => { escape.current = onEscape }, [onEscape])
  useEffect(() => {
    if (!active) return
    const root = ref.current
    if (!root) return
    const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null
    // Primero lo marcado con data-autofocus, luego el primer control del cuerpo y, si no hay, el diálogo mismo.
    const body = root.querySelector<HTMLElement>('.panel-cuerpo')
    const first = root.querySelector<HTMLElement>('[data-autofocus]') ?? (body ? focusableIn(body)[0] : undefined) ?? focusableIn(root)[0]
    ;(first ?? root).focus()

    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && escape.current) {
        e.stopPropagation()
        escape.current()
        return
      }
      if (e.key !== 'Tab') return
      const items = focusableIn(root)
      if (items.length === 0) {
        e.preventDefault()
        return
      }
      const firstItem = items[0] as HTMLElement
      const lastItem = items[items.length - 1] as HTMLElement
      if (e.shiftKey && document.activeElement === firstItem) {
        e.preventDefault()
        lastItem.focus()
      } else if (!e.shiftKey && document.activeElement === lastItem) {
        e.preventDefault()
        firstItem.focus()
      }
    }
    root.addEventListener('keydown', onKey)
    return () => {
      root.removeEventListener('keydown', onKey)
      previous?.focus()
    }
  }, [ref, active])
}
