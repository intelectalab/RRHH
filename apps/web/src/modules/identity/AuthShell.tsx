'use client'

import type { ReactNode } from 'react'
import { useT } from '@/lib/useT'

/** Marco de las pantallas de acceso: panel de marca a la izquierda y formulario a la derecha. */
export function AuthShell({ children }: { children: ReactNode }) {
  const { t } = useT()

  return (
    <div className="login">
      <div className="login-foto" aria-hidden="true">
        <div className="login-foto-pie">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/logo-aynihr.svg" alt="" />
          <span>{t('auth.tagline')}</span>
        </div>
      </div>
      <div className="login-form">
        <div className="caja">
          <div className="logo mb-4">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/logo-aynihr.svg" alt="" />
            <span>
              <b>Ayni</b>
              <span>HR</span>
            </span>
          </div>
          {children}
        </div>
      </div>
    </div>
  )
}
