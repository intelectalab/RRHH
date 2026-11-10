'use client'

import { useEffect, useRef, useState } from 'react'
import { useT } from '@/lib/useT'
import { useCompany } from './CompanyProvider'

function initials(name: string): string {
  return name.replace(/S\.?A\.?C?\.?|E\.?I\.?R\.?L\.?|S\.?R\.?L\.?/gi, '').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase() ?? '').join('')
}

function BuildingsIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M3 21h18M5 21V5l8-3v19M13 9h6v12M8 8h2M8 12h2M8 16h2M16 13h1M16 17h1" />
    </svg>
  )
}

/** Selector de empresa de la barra superior (diseño aprobado). */
export function CompanySelector() {
  const { t } = useT()
  const { companies, selectedId, canConsolidate, select } = useCompany()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [open])

  if (companies.length === 0) return null

  const current = companies.find((c) => c.id === selectedId) ?? null
  const showAll = canConsolidate && companies.length > 1
  const label = current ? current.name : t('company.all')

  return (
    <div className="menu" ref={ref}>
      <button type="button" className="empsel" onClick={() => setOpen((v) => !v)} aria-expanded={open} aria-haspopup="menu" aria-label={t('company.select_aria', { name: label })}>
        {current ? <span className="logo-emp">{initials(current.name)}</span> : <span className="logo-emp todas"><BuildingsIcon /></span>}
        <span className="t">
          <b>{label}</b>
          <small>{current ? t('company.tax', { tax: current.tax_id }) : t('company.count', { count: companies.length })}</small>
        </span>
      </button>
      {open && (
        <div className="menu-lista izq" role="menu">
          {showAll && (
            <button type="button" role="menuitemradio" aria-checked={selectedId === null} className={`menu-item${selectedId === null ? ' on' : ''}`} onClick={() => { select(null); setOpen(false) }}>
              <span className="logo-emp todas"><BuildingsIcon /></span> {t('company.all')}
            </button>
          )}
          {companies.map((c) => (
            <button key={c.id} type="button" role="menuitemradio" aria-checked={selectedId === c.id} className={`menu-item${selectedId === c.id ? ' on' : ''}`} onClick={() => { select(c.id); setOpen(false) }}>
              <span className="logo-emp">{initials(c.name)}</span>
              <span>{c.name}<small>{t('company.tax', { tax: c.tax_id })}</small></span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
