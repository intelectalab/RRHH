'use client'

import { useEffect } from 'react'
import { registerServiceWorker } from './pwa'

/** UI-9 · Se monta una vez en el layout raíz para que toda la aplicación quede instalable con su armazón guardado. */
export function ServiceWorker() {
  useEffect(() => { registerServiceWorker() }, [])
  return null
}
