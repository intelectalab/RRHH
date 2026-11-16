'use client'

import { usePathname, useRouter } from 'next/navigation'
import { useEffect, useRef, useState } from 'react'
import { useT } from '@/lib/useT'
import { Icon } from '@/ui/components/Icon'
import { QUICK_CREATE_EVENT, quickCreateHref, quickCreateOptions, type QuickCreateOption } from './quickCreateRegistry'
import { useSession } from './SessionProvider'

/** Abre la opción: si ya estamos en su pantalla, avisa a la pantalla; si no, navega con `?crear=`. */
export function useOpenQuickCreate(): (o: QuickCreateOption) => void {
  const router = useRouter()
  const pathname = usePathname()
  return (o) => {
    if (!o.page && pathname === o.href) window.dispatchEvent(new CustomEvent(QUICK_CREATE_EVENT, { detail: o.id }))
    else router.push(quickCreateHref(o))
  }
}

/** UI-8 · Botón «+ Crear» de la barra superior. Alt + N lo abre desde cualquier pantalla. */
export function QuickCreate() {
  const { t } = useT()
  const { session, can } = useSession()
  const openOption = useOpenQuickCreate()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const options = quickCreateOptions(session.modules, can)

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.altKey && !e.ctrlKey && !e.metaKey && e.key.toLowerCase() === 'n') {
        e.preventDefault()
        setOpen((v) => !v)
      } else if (e.key === 'Escape') setOpen(false)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', close)
    window.setTimeout(() => ref.current?.querySelector<HTMLButtonElement>('.menu-item')?.focus(), 0)
    return () => document.removeEventListener('mousedown', close)
  }, [open])

  if (options.length === 0) return null

  return (
    <div className="menu qcreate" ref={ref}>
      <button type="button" className="btn btn-primary btn-sm qcreate-btn" onClick={() => setOpen((v) => !v)} aria-expanded={open} aria-haspopup="menu" aria-label={t('quick.open')} title={t('quick.shortcut')}>
        <Icon name="plus" size={16} />
        <span className="qcreate-t">{t('quick.button')}</span>
      </button>
      {open && (
        <div className="menu-lista" role="menu" aria-label={t('quick.open')}>
          {options.map((o) => (
            <button key={o.id} type="button" className="menu-item" role="menuitem" onClick={() => { setOpen(false); openOption(o) }}>
              <Icon name="plus" /> {t(o.key)}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
