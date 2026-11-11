'use client'

import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'
import { useT } from '@/lib/useT'
import { nextIndex } from '../a11y'
import { Icon } from './Icon'

export type Person = { id: string; name: string; detail?: string }

export function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase() ?? '').join('')
}

/**
 * UI-3 · Selector de persona (combobox de WAI-ARIA con lista asíncrona). La búsqueda la hace el servidor
 * con los permisos y el alcance de quien pregunta (regla 6): el componente solo recibe la función
 * `search`. Flechas recorren, Enter elige, Escape cierra y el botón ✕ limpia.
 */
export function PersonPicker({ label, value, onChange, search, disabled = false, error, hint, required = false }: {
  label: string
  value: Person | null
  onChange: (p: Person | null) => void
  search: (q: string) => Promise<Person[]>
  disabled?: boolean
  error?: string
  hint?: string
  required?: boolean
}) {
  const { t } = useT()
  const id = useId()
  const input = useRef<HTMLInputElement>(null)
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [failed, setFailed] = useState(false)
  const [results, setResults] = useState<Person[]>([])
  const [active, setActive] = useState(-1)
  const seq = useRef(0)

  useEffect(() => {
    if (!open) return
    const n = ++seq.current
    setLoading(true)
    const timer = window.setTimeout(() => {
      search(q.trim())
        .then((r) => { if (n === seq.current) { setResults(r); setFailed(false); setActive(r.length > 0 ? 0 : -1) } })
        .catch(() => { if (n === seq.current) { setResults([]); setFailed(true) } })
        .finally(() => { if (n === seq.current) setLoading(false) })
    }, 250)
    return () => window.clearTimeout(timer)
  }, [q, open, search])

  function choose(p: Person) {
    onChange(p)
    setOpen(false)
    setQ('')
  }

  function onKey(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Escape') { setOpen(false); return }
    if (e.key === 'Enter') {
      const p = results[active]
      if (open && p) { e.preventDefault(); choose(p) }
      return
    }
    if (e.key === 'ArrowDown' && !open) { e.preventDefault(); setOpen(true); return }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      const i = nextIndex(results.length, active < 0 ? 0 : active, e.key)
      if (i !== null) { e.preventDefault(); setActive(i) }
    }
  }

  const listId = `${id}-l`
  const describedBy = [error ? `${id}-e` : '', hint ? `${id}-h` : ''].filter(Boolean).join(' ') || undefined

  return (
    <div className="combo">
      <label className="form-label" htmlFor={id}>{label}</label>
      {value ? (
        <div className={`combo-elegido${disabled ? ' off' : ''}${error ? ' is-invalid' : ''}`}>
          <span className="av s" aria-hidden="true">{initials(value.name)}</span>
          <span className="min-w-0">
            <b>{value.name}</b>
            {value.detail && <span className="sub2">{value.detail}</span>}
          </span>
          {!disabled && (
            <button type="button" id={id} className="ibtn" onClick={() => { onChange(null); window.setTimeout(() => input.current?.focus(), 0) }} aria-label={t('ui.person_clear', { name: value.name })} aria-describedby={describedBy}>
              <Icon name="close" size={16} />
            </button>
          )}
        </div>
      ) : (
        <div className="combo-in">
          <Icon name="search" size={16} />
          <input
            ref={input}
            id={id}
            className={`form-control${error ? ' is-invalid' : ''}`}
            role="combobox"
            aria-expanded={open}
            aria-controls={listId}
            aria-autocomplete="list"
            aria-activedescendant={open && active >= 0 ? `${id}-o-${active}` : undefined}
            aria-invalid={error ? true : undefined}
            aria-required={required || undefined}
            aria-describedby={describedBy}
            autoComplete="off"
            disabled={disabled}
            placeholder={t('ui.person_placeholder')}
            value={q}
            onChange={(e) => { setQ(e.target.value); setOpen(true) }}
            onFocus={() => setOpen(true)}
            onBlur={() => window.setTimeout(() => setOpen(false), 120)}
            onKeyDown={onKey}
          />
        </div>
      )}
      {open && !value && (
        <ul id={listId} role="listbox" aria-label={label} className="combo-lista" aria-busy={loading || undefined}>
          {loading && results.length === 0 && <li className="combo-nota">{t('ui.searching')}</li>}
          {!loading && failed && <li className="combo-nota" role="alert">{t('ui.search_failed')}</li>}
          {!loading && !failed && results.length === 0 && <li className="combo-nota">{t('ui.no_results')}</li>}
          {results.map((p, i) => (
            <li
              key={p.id}
              id={`${id}-o-${i}`}
              role="option"
              aria-selected={i === active}
              className={i === active ? 'on' : undefined}
              onMouseDown={(e) => { e.preventDefault(); choose(p) }}
              onMouseEnter={() => setActive(i)}
            >
              <span className="av s" aria-hidden="true">{initials(p.name)}</span>
              <span className="min-w-0">
                <b>{p.name}</b>
                {p.detail && <span className="sub2">{p.detail}</span>}
              </span>
            </li>
          ))}
        </ul>
      )}
      {hint && !error && <div id={`${id}-h`} className="small text-secondary mt-2">{hint}</div>}
      {error && <div id={`${id}-e`} className="invalid-feedback">{error}</div>}
    </div>
  )
}
