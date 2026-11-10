'use client'

import { useRouter } from 'next/navigation'
import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, ApiRequestError } from '@/lib/api'

/** Respuesta de GET /me (el contrato la publica como sobre genérico). */
export type SessionData = {
  user: { id: string; display_name: string; locale: string | null; timezone: string | null; must_change_password: boolean }
  tenant: { id: string; slug: string; name: string; currency: string } | null
  roles: Array<{ id: string; code: string; name: string; system: boolean }>
  permissions: string[]
  field_permissions: string[]
  scopes: Record<string, string>
  modules: string[]
}

type SessionContextValue = {
  session: SessionData
  can: (permission: string) => boolean
  reload: () => Promise<void>
  logout: () => Promise<void>
}

const SessionContext = createContext<SessionContextValue | null>(null)

/**
 * Carga la sesión desde la API. Sin sesión, envía a /ingresar.
 * El menú y los botones usan `can()`; el servidor valida igual cada acción (regla 10).
 */
export function SessionProvider({ children, fallback }: { children: ReactNode; fallback: ReactNode }) {
  const router = useRouter()
  const [session, setSession] = useState<SessionData | null>(null)

  const load = useCallback(async () => {
    try {
      const res = await api.get<{ data: SessionData }>('/me')
      setSession(res.data)
    } catch (e) {
      if (e instanceof ApiRequestError && (e.status === 401 || e.status === 400)) {
        router.replace('/ingresar')
        return
      }
      throw e
    }
  }, [router])

  useEffect(() => {
    void load()
  }, [load])

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout', {})
    } finally {
      router.replace('/ingresar')
    }
  }, [router])

  if (session === null) return <>{fallback}</>

  return (
    <SessionContext.Provider value={{ session, can: (p) => session.permissions.includes(p), reload: load, logout }}>
      {children}
    </SessionContext.Provider>
  )
}

export function useSession(): SessionContextValue {
  const value = useContext(SessionContext)
  if (value === null) throw new Error('useSession fuera de SessionProvider')
  return value
}
