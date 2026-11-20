'use client'

import { useCallback, useState, type ReactNode } from 'react'
import { MenuButton } from './MenuButton'
import { useDragMove } from '../useDragMove'

export type KanbanColumn = { id: string; title: string }
export type KanbanCard = { id: string; column: string; title: string; meta?: ReactNode; href?: string }

/**
 * UI-12 · Tablero kanban. Arrastrar una tarjeta a otra columna llama a `onMove`, que ejecuta la acción real
 * en la API y devuelve el texto de confirmación (o lanza el error): la tarjeta solo cambia de columna cuando
 * el servidor lo confirma. Solo se ofrecen las columnas que `canMove` admite (las mismas que la máquina de
 * estados). Alternativa por teclado y lector de pantalla: el menú «Mover a…» de cada tarjeta.
 */
export function Kanban({ columns, cards, canMove, onMove, labels, emptyText }: {
  columns: KanbanColumn[]
  cards: KanbanCard[]
  canMove: (card: KanbanCard, to: string) => boolean
  onMove: (card: KanbanCard, to: string) => Promise<string>
  labels: { moveTo: string; moving: string }
  emptyText: string
}) {
  const [busy, setBusy] = useState<string | null>(null)
  const [status, setStatus] = useState<{ text: string; error: boolean } | null>(null)

  const move = useCallback(async (card: KanbanCard, to: string) => {
    if (card.column === to || !canMove(card, to)) return
    setBusy(card.id)
    try {
      setStatus({ text: await onMove(card, to), error: false })
    } catch (e) {
      setStatus({ text: e instanceof Error ? e.message : String(e), error: true })
    } finally {
      setBusy(null)
    }
  }, [canMove, onMove])

  const drop = useCallback((card: KanbanCard, to: string) => void move(card, to), [move])
  const { dragging, over, onPointerDown } = useDragMove<KanbanCard>(drop)

  return (
    <div className="kanban">
      <p className="vh" role="status" aria-live="polite">{status?.text ?? ''}</p>
      <div className="kanban-cols">
        {columns.map((col) => {
          const list = cards.filter((c) => c.column === col.id)
          const allowed = dragging !== null && canMove(dragging, col.id) && dragging.column !== col.id
          return (
            <section key={col.id} className={`kanban-col${allowed ? ' destino' : ''}${allowed && over === col.id ? ' encima' : ''}`} data-drop={allowed ? col.id : undefined} aria-labelledby={`kb-${col.id}`}>
              <h3 id={`kb-${col.id}`} className="kanban-t">{col.title} <span className="kanban-n">{list.length}</span></h3>
              <ul className="kanban-lista">
                {list.length === 0 && <li className="kanban-vacio">{emptyText}</li>}
                {list.map((card) => {
                  const targets = columns.filter((c) => c.id !== card.column && canMove(card, c.id))
                  return (
                    <li
                      key={card.id}
                      className={`kanban-card${dragging?.id === card.id ? ' arrastrando' : ''}${targets.length > 0 ? ' movible' : ''}`}
                      onPointerDown={targets.length > 0 ? onPointerDown(card) : undefined}
                      aria-busy={busy === card.id}
                    >
                      <div className="kanban-card-c">
                        {card.href ? <a href={card.href}>{card.title}</a> : <b>{card.title}</b>}
                        {card.meta && <div className="small">{card.meta}</div>}
                      </div>
                      {targets.length > 0 && (
                        <MenuButton
                          label={busy === card.id ? labels.moving : `${labels.moveTo} · ${card.title}`}
                          iconOnly
                          icon="more"
                          items={targets.map((c) => ({ id: c.id, label: `${labels.moveTo} ${c.title}`, onSelect: () => void move(card, c.id), disabled: busy !== null }))}
                        />
                      )}
                    </li>
                  )
                })}
              </ul>
            </section>
          )
        })}
      </div>
    </div>
  )
}
