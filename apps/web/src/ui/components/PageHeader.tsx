'use client'

import Link from 'next/link'
import type { ReactNode } from 'react'
import { usePublishTitle } from '@/modules/session/ShellTitle'

export type Tab = { href: string; label: string; active: boolean }

/**
 * Encabezado de pantalla: subtítulo, acciones a la derecha y pestañas de sección. El título se
 * publica en la cabecera del armazón (docs/10 §3.12); sin armazón se muestra aquí.
 */
export function PageHeader({ title, sub, actions, tabs }: { title: string; sub?: ReactNode; actions?: ReactNode; tabs?: Tab[] }) {
  const inShell = usePublishTitle(title)
  const hasTabs = !!tabs && tabs.length > 0
  return (
    <div className="phead">
      <div className="in">
        <div className={`phead-fila${hasTabs ? '' : ' pb'}`}>
          <div className="min-w-0">
            {!inShell && <h1>{title}</h1>}
            {sub && <div className="sub">{sub}</div>}
          </div>
          {actions && <div className="acc">{actions}</div>}
        </div>
        {hasTabs && (
          <nav className="stabs" aria-label={title}>
            {tabs.map((tab) => (
              <Link key={tab.href} href={tab.href} className={tab.active ? 'on' : undefined} aria-current={tab.active ? 'page' : undefined}>
                {tab.label}
              </Link>
            ))}
          </nav>
        )}
      </div>
    </div>
  )
}
