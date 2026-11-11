'use client'

import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'

/**
 * docs/10 §3.12: el título de la pantalla vive en la cabecera del armazón y la pantalla no lo repite.
 * PageHeader lo publica aquí; fuera del armazón (sin proveedor) PageHeader muestra su propio h1.
 */
const Ctx = createContext<{ title: string; setTitle: (t: string) => void } | null>(null)

export function ShellTitleProvider({ children }: { children: ReactNode }) {
  const [title, setTitle] = useState('')
  return <Ctx.Provider value={{ title, setTitle }}>{children}</Ctx.Provider>
}

export function useShellTitle(): string {
  return useContext(Ctx)?.title ?? ''
}

/** Publica el título en la cabecera; devuelve false si no hay armazón (la pantalla debe mostrarlo). */
export function usePublishTitle(title: string): boolean {
  const ctx = useContext(Ctx)
  const set = ctx?.setTitle
  useEffect(() => {
    if (!set) return
    set(title)
    document.title = `${title} · GestiaHR`
  }, [set, title])
  return ctx !== null
}
