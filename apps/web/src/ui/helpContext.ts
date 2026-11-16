'use client'

import { createContext } from 'react'

/**
 * AYU-3 · Abre la ayuda de la pantalla actual. El centro de ayuda (modules/help) lo provee dentro de la
 * aplicación; fuera de ella (consola, páginas públicas) no hay ayuda y los componentes no muestran el enlace.
 */
export const HelpOpenerContext = createContext<{ open: () => void; label: string } | null>(null)
