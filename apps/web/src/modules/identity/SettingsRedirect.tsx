'use client'

import { useRouter } from 'next/navigation'
import { useEffect } from 'react'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { settingsTabs } from './settingsTabs'

/** Configuración abre la primera pestaña que el usuario puede ver. */
export function SettingsRedirect() {
  const { t } = useT()
  const { can } = useSession()
  const router = useRouter()

  useEffect(() => {
    const first = settingsTabs(t, can, 'users')[0]
    router.replace(first ? first.href : '/')
  }, [t, can, router])

  return null
}
