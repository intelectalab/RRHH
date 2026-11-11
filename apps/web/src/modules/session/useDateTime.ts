'use client'

import { useCallback } from 'react'
import { formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useOptionalCompany } from './CompanyProvider'
import { useSession } from './SessionProvider'

/**
 * CFG-2 · Zona horaria para mostrar fechas y horas: la de la empresa elegida en la barra superior;
 * con todas las empresas (consolidado), la del usuario, que por defecto es la de la organización.
 */
export function useTimeZone(): string | undefined {
  const { session } = useSession()
  const company = useOptionalCompany()
  const selected = company?.selectedId ? company.companies.find((c) => c.id === company.selectedId) : undefined

  return selected?.timezone || session.user.timezone || undefined
}

/** Formateador de fecha y hora en el idioma activo y la zona de la empresa. */
export function useDateTime(): (iso: string | null | undefined, seconds?: boolean) => string {
  const { locale } = useT()
  const zone = useTimeZone()

  return useCallback((iso, seconds = false) => formatDateTime(locale, iso, zone, seconds), [locale, zone])
}
