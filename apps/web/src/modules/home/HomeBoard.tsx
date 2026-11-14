'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Icon } from '@/ui/components/Icon'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { cardAmounts, type HomeBoardData, type HomeCard } from './homeView'

/**
 * COL-12 · MSS-1 · Tablero de inicio. Cada número viene calculado por la API con el alcance del usuario; aquí
 * solo se da formato. Cada tarjeta enlaza a la lista que explica su número.
 */
export function HomeBoard({ audience }: { audience: 'hr' | 'manager' }) {
  const { t, locale } = useT()
  const [board, setBoard] = useState<HomeBoardData | null>(null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(async () => {
    try {
      setBoard((await api.get<{ data: HomeBoardData }>(`/home/${audience}`)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [audience])

  useEffect(() => {
    setBoard(null)
    void load()
  }, [load])

  if (failed) return <section className="card"><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></section>
  if (board === null) {
    return (
      <div className="inicio-tarjetas" aria-busy="true">
        {[1, 2, 3, 4].map((i) => <div key={i} className="card inicio-tarjeta"><div className="card-body"><span className="esq" style={{ width: '60%' }} /><span className="esq" style={{ width: 48, height: 28 }} /></div></div>)}
      </div>
    )
  }
  if (board.cards.length === 0) return <section className="card"><EmptyState title={t('home_board.empty_title')} text={t('home_board.empty_text')} /></section>

  const value = (card: HomeCard): string => {
    if (card.key === 'labor_cost') {
      const amounts = cardAmounts(card)
      return amounts.length === 0 ? '—' : amounts.map((m) => `${m.currency} ${formatNumber(locale, m.amount)}`).join(' · ')
    }
    if (card.value === null) return '—'
    const text = formatNumber(locale, String(card.value))
    return card.meta.unit === 'percent' ? t('home_board.percent', { value: text }) : text
  }

  const note = (card: HomeCard): string | null => {
    const m = card.meta
    if (card.key === 'on_vacation' && typeof m.upcoming === 'number') return t('home_board.note_upcoming', { n: m.upcoming, days: Number(m.upcoming_days ?? 0) })
    if (card.key === 'contracts_expiring' && typeof m.overdue === 'number' && m.overdue > 0) return t('home_board.note_overdue', { n: m.overdue })
    if (card.key === 'turnover_12m' && typeof m.exits === 'number') return t('home_board.note_turnover', { exits: m.exits, average: formatNumber(locale, String(m.average_headcount ?? '0')) })
    if (card.key === 'labor_cost' && typeof m.excluded_non_monthly === 'number' && m.excluded_non_monthly > 0) return t('home_board.note_non_monthly', { n: m.excluded_non_monthly })
    if (typeof m.window_days === 'number') return t('home_board.note_window', { days: m.window_days })
    return null
  }

  return (
    <div className="inicio-tarjetas">
      {board.cards.map((card) => {
        const extra = note(card)
        return (
          <section key={card.key} className={`card inicio-tarjeta${card.tone ? ` tono-${card.tone}` : ''}`} aria-labelledby={`tarjeta-${card.key}`}>
            <div className="card-body">
              <h2 id={`tarjeta-${card.key}`} className="inicio-titulo">{t(`home_board.card_${card.key}`)}</h2>
              <Link className="inicio-cifra" href={card.link}>
                <b>{value(card)}</b>
                <span className="visually-hidden">{t('home_board.open_list')}</span>
              </Link>
              {extra && <p className="sub2">{extra}</p>}
              {card.items.length > 0 && (
                <ul className="inicio-items">
                  {card.items.map((item, i) => (
                    <li key={`${item.employment_id ?? item.position_id ?? item.id ?? i}-${i}`}>
                      {item.link ? <Link href={item.link}>{item.name}</Link> : <span>{item.name}</span>}
                      <span className="sub2">
                        {item.date ? formatDate(locale, item.date) : ''}
                        {typeof item.years === 'number' ? ` · ${t('home_board.years', { n: item.years })}` : ''}
                        {item.overdue ? ` · ${t('tasks.overdue')}` : ''}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
              <Link className="btn btn-sec btn-sm inicio-ver" href={card.link}>{t('home_board.see_all')} <Icon name="arrowRight" /></Link>
            </div>
          </section>
        )
      })}
    </div>
  )
}
