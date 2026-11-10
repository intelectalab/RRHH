'use client'

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, type Schema } from '@/lib/api'
import { useSession } from './SessionProvider'

type Company = Schema<'LegalEntity'>

type CompanyContextValue = {
  companies: Company[]
  /** null = todas las empresas (consolidado). */
  selectedId: string | null
  canConsolidate: boolean
  select: (id: string | null) => void
  /** Parámetro para los listados; el backend valida el alcance (TEN-5). */
  filterParam: () => Record<string, string>
  reload: () => Promise<void>
}

const CompanyContext = createContext<CompanyContextValue | null>(null)

const storageKey = (tenantId: string) => `ayni.company.${tenantId}`

/**
 * Empresa activa de la barra superior. La lista viene ya filtrada por el alcance del
 * usuario; la elección se recuerda en este navegador como comodidad.
 */
export function CompanyProvider({ children }: { children: ReactNode }) {
  const { session, can } = useSession()
  const [companies, setCompanies] = useState<Company[]>([])
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const tenantId = session.tenant?.id ?? 'none'
  const canConsolidate = can('org.legal_entities.view_consolidated')

  const reload = useCallback(async () => {
    if (!can('org.legal_entities.view')) return
    const res = await api.get<{ data: Company[] }>('/legal-entities?status=active&per_page=100')
    setCompanies(res.data)

    let stored: string | null = null
    try {
      stored = window.localStorage.getItem(storageKey(tenantId))
    } catch {
      stored = null
    }
    const valid = res.data.some((c) => c.id === stored) ? stored : null
    // Sin permiso de consolidado, siempre hay una empresa elegida.
    setSelectedId(valid ?? (canConsolidate && res.data.length > 1 ? null : (res.data[0]?.id ?? null)))
  }, [can, canConsolidate, tenantId])

  useEffect(() => {
    void reload()
  }, [reload])

  const select = useCallback((id: string | null) => {
    setSelectedId(id)
    try {
      if (id === null) window.localStorage.removeItem(storageKey(tenantId))
      else window.localStorage.setItem(storageKey(tenantId), id)
    } catch {
      // almacenamiento no disponible: la elección dura la sesión de la página
    }
  }, [tenantId])

  const value = useMemo<CompanyContextValue>(() => ({
    companies,
    selectedId,
    canConsolidate,
    select,
    filterParam: (): Record<string, string> => (selectedId ? { legal_entity_id: selectedId } : {}),
    reload,
  }), [companies, selectedId, canConsolidate, select, reload])

  return <CompanyContext.Provider value={value}>{children}</CompanyContext.Provider>
}

export function useCompany(): CompanyContextValue {
  const value = useContext(CompanyContext)
  if (value === null) throw new Error('useCompany fuera de CompanyProvider')
  return value
}
