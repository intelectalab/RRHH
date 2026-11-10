'use client'

import { useCallback, useEffect, useState } from 'react'

/** Aviso breve en la parte inferior. */
export function useToast(): { toast: (text: string, kind?: 'ok' | 'error') => void; node: React.ReactNode } {
  const [state, setState] = useState<{ text: string; kind: 'ok' | 'error' } | null>(null)

  useEffect(() => {
    if (state === null) return
    const id = window.setTimeout(() => setState(null), 3500)
    return () => window.clearTimeout(id)
  }, [state])

  const toast = useCallback((text: string, kind: 'ok' | 'error' = 'ok') => setState({ text, kind }), [])

  return {
    toast,
    node: state && (
      <div className={`aviso${state.kind === 'error' ? ' error' : ''}`} role={state.kind === 'error' ? 'alert' : 'status'}>
        {state.text}
      </div>
    ),
  }
}
