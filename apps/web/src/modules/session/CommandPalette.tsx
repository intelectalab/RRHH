'use client'

import { useRouter } from 'next/navigation'
import { useEffect, useMemo, useRef, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { Icon } from '@/ui/components/Icon'
import { useSession } from './SessionProvider'

type Hit = { type: string; id: string; title: string; subtitle: string | null; href: string }
type Item = { key: string; label: string; hint?: string; href: string; group: 'pages' | 'results' }

const PAGES: Array<{ key: string; href: string; permission?: string }> = [
  { key: 'palette.go_home', href: '/' },
  { key: 'palette.go_companies', href: '/organizacion/empresas', permission: 'org.legal_entities.view' },
  { key: 'palette.go_users', href: '/configuracion/usuarios', permission: 'identity.users.view' },
  { key: 'palette.go_roles', href: '/configuracion/roles', permission: 'identity.roles.view' },
  { key: 'palette.go_audit', href: '/configuracion/auditoria', permission: 'audit.logs.view' },
  { key: 'palette.go_sensitive', href: '/configuracion/accesos-sensibles', permission: 'audit.sensitive_access.view' },
]

const normalize = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

/** Paleta de comandos y búsqueda global (UI-7). Ctrl/⌘ + K. Los resultados vienen filtrados por el servidor. */
export function CommandPalette() {
  const { t } = useT()
  const { can } = useSession()
  const router = useRouter()
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const [hits, setHits] = useState<Hit[]>([])
  const [active, setActive] = useState(0)
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        setOpen((v) => !v)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  useEffect(() => {
    if (open) {
      setQ('')
      setHits([])
      setActive(0)
      window.setTimeout(() => inputRef.current?.focus(), 0)
    }
  }, [open])

  useEffect(() => {
    if (q.trim().length < 2) {
      setHits([])
      return
    }
    const id = window.setTimeout(async () => {
      try {
        const res = await api.get<{ data: Hit[] }>(`/search?q=${encodeURIComponent(q.trim())}`)
        setHits(res.data)
      } catch {
        setHits([])
      }
    }, 200)
    return () => window.clearTimeout(id)
  }, [q])

  const items = useMemo<Item[]>(() => {
    const term = normalize(q.trim())
    const pages = PAGES.filter((p) => !p.permission || can(p.permission))
      .map((p) => ({ key: p.href, label: t(p.key), href: p.href, group: 'pages' as const }))
      .filter((p) => term === '' || normalize(p.label).includes(term))
    const results = hits.map((h) => ({ key: `${h.type}:${h.id}`, label: h.title, hint: [t(`palette.type_${h.type}`), h.type === 'user' && h.subtitle ? t(`users.status_${h.subtitle}`) : h.subtitle].filter(Boolean).join(' · '), href: h.href, group: 'results' as const }))
    return [...results, ...pages]
  }, [q, hits, can, t])

  useEffect(() => setActive(0), [items.length])

  function go(item: Item | undefined) {
    if (!item) return
    setOpen(false)
    router.push(item.href)
  }

  function onKeyDown(e: React.KeyboardEvent) {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, items.length - 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)) }
    else if (e.key === 'Enter') { e.preventDefault(); go(items[active]) }
    else if (e.key === 'Escape') { setOpen(false) }
  }

  return (
    <>
      <button type="button" className="gsearch" onClick={() => setOpen(true)} aria-label={t('palette.open')}>
        <Icon name="search" size={16} />
        <span className="ph">{t('palette.open')}</span>
        <kbd>{t('palette.shortcut')}</kbd>
      </button>
      {open && (
        <>
          <div className="panel-fondo" onClick={() => setOpen(false)} aria-hidden="true" />
          <div className="paleta" role="dialog" aria-modal="true" aria-label={t('palette.open')}>
            <div className="paleta-in">
              <Icon name="search" />
              <input
                ref={inputRef}
                role="combobox"
                aria-expanded="true"
                aria-controls="paleta-lista"
                aria-activedescendant={items[active] ? `pal-${active}` : undefined}
                placeholder={t('palette.placeholder')}
                value={q}
                onChange={(e) => setQ(e.target.value)}
                onKeyDown={onKeyDown}
              />
            </div>
            <ul className="paleta-lista" id="paleta-lista" role="listbox">
              {items.map((item, i) => (
                <li key={item.key} id={`pal-${i}`} role="option" aria-selected={i === active}>
                  {(i === 0 || items[i - 1]?.group !== item.group) && <div className="paleta-grupo">{t(`palette.${item.group}`)}</div>}
                  <button type="button" className={i === active ? 'on' : undefined} onMouseEnter={() => setActive(i)} onClick={() => go(item)}>
                    <span>{item.label}</span>
                    {item.hint && <small>{item.hint}</small>}
                  </button>
                </li>
              ))}
              {items.length === 0 && <li className="vacio">{t('palette.empty', { q })}</li>}
            </ul>
            <div className="paleta-pie small text-secondary">{t('palette.hint')}</div>
          </div>
        </>
      )}
    </>
  )
}
