import Link from 'next/link'
import type { ReactNode } from 'react'

export type Tab = { href: string; label: string; active: boolean }

/** Encabezado de pantalla: título, subtítulo, acciones a la derecha y pestañas de sección. */
export function PageHeader({ title, sub, actions, tabs }: { title: string; sub?: ReactNode; actions?: ReactNode; tabs?: Tab[] }) {
  return (
    <div className="phead">
      <div className="in">
        <div className={`phead-fila${tabs && tabs.length > 0 ? '' : ' pb'}`}>
          <div className="min-w-0">
            <h1>{title}</h1>
            {sub && <div className="sub">{sub}</div>}
          </div>
          {actions && <div className="acc">{actions}</div>}
        </div>
        {tabs && tabs.length > 0 && (
          <nav className="stabs">
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
