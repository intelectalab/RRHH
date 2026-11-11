'use client'

import type { ReactNode } from 'react'
import { useT } from '@/lib/useT'
import { Icon } from '@/ui/components/Icon'
import { BrandMark } from '@/ui/components/BrandMark'
import { BrandName } from '@/ui/components/BrandName'

/**
 * Marco de las pantallas de acceso (docs/10 §3.12 «Ingreso»): panel de marca con mensaje y tres
 * beneficios sobre el degradado de la paleta (sin fotografías) y el formulario a la derecha.
 */
export function AuthShell({ children }: { children: ReactNode }) {
  const { t } = useT()

  return (
    <div className="login">
      <div className="login-marca">
        <div className="logo">
          <BrandMark />
          <BrandName />
        </div>
        <div>
          <h2>{t('auth.brand_title')}</h2>
          <ul>
            {(['benefit_1', 'benefit_2', 'benefit_3'] as const).map((k) => (
              <li key={k}><Icon name="check" size={20} /> <span>{t(`auth.${k}`)}</span></li>
            ))}
          </ul>
        </div>
        <span className="pie">{t('auth.tagline')}</span>
      </div>
      <div className="login-form">
        <div className="caja">
          <div className="logo mb-4">
            <BrandMark />
            <BrandName />
          </div>
          {children}
        </div>
      </div>
    </div>
  )
}
