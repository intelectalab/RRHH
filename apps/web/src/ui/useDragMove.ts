'use client'

import { useCallback, useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'

/**
 * UI-12 · Arrastrar y soltar con eventos de puntero (ratón, lápiz y toque por igual, a diferencia del
 * arrastre HTML5, que no existe en el móvil). El destino se encuentra por el atributo `data-drop` del
 * elemento bajo el dedo o el puntero. Un movimiento menor a 6 px es un toque normal (abre o enfoca).
 */
export function useDragMove<T>(onDrop: (item: T, target: string) => void) {
  const [dragging, setDragging] = useState<T | null>(null)
  const [over, setOver] = useState<string | null>(null)
  const start = useRef<{ x: number; y: number; item: T; active: boolean } | null>(null)

  const targetAt = (x: number, y: number): string | null => {
    const el = typeof document === 'undefined' ? null : document.elementFromPoint(x, y)
    const zone = el instanceof Element ? el.closest('[data-drop]') : null
    return zone?.getAttribute('data-drop') ?? null
  }

  const onPointerDown = useCallback((item: T) => (e: ReactPointerEvent) => {
    if (e.button !== 0) return
    start.current = { x: e.clientX, y: e.clientY, item, active: false }
  }, [])

  useEffect(() => {
    const move = (e: PointerEvent) => {
      const s = start.current
      if (!s) return
      if (!s.active && Math.hypot(e.clientX - s.x, e.clientY - s.y) < 6) return
      if (!s.active) {
        s.active = true
        setDragging(s.item)
      }
      e.preventDefault()
      setOver(targetAt(e.clientX, e.clientY))
    }
    const up = (e: PointerEvent) => {
      const s = start.current
      start.current = null
      if (s?.active) {
        const target = targetAt(e.clientX, e.clientY)
        if (target !== null) onDrop(s.item, target)
      }
      setDragging(null)
      setOver(null)
    }
    window.addEventListener('pointermove', move, { passive: false })
    window.addEventListener('pointerup', up)
    window.addEventListener('pointercancel', up)
    return () => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', up)
      window.removeEventListener('pointercancel', up)
    }
  }, [onDrop])

  return { dragging, over, onPointerDown }
}
