/**
 * COL-12 · MSS-1 · Inicio según el rol. La API decide qué vistas tiene el usuario (GET /home); aquí solo se
 * elige cuál mostrar (la recordada si sigue disponible, si no la que la API indica por defecto).
 */
export type HomeView = 'hr' | 'manager' | 'employee'

export type HomeCardItem = {
  employment_id?: string
  position_id?: string
  id?: string
  name: string
  date?: string | null
  detail?: string | null
  years?: number
  overdue?: boolean
  link?: string
}

export type Money = { amount: string; currency: string }

export type HomeCard = {
  key: string
  module: string
  value: number | string | null
  link: string
  tone: 'danger' | 'warning' | null
  items: HomeCardItem[]
  meta: Record<string, unknown>
}

export type HomeBoardData = { audience: 'hr' | 'manager'; as_of: string; cards: HomeCard[] }

export const HOME_VIEW_KEY = 'gestia.home.view'

const KNOWN: ReadonlyArray<HomeView> = ['hr', 'manager', 'employee']

export function pickView(available: ReadonlyArray<string>, fallback: string, remembered: string | null): HomeView {
  const views = available.filter((v): v is HomeView => (KNOWN as ReadonlyArray<string>).includes(v))
  if (remembered && (views as ReadonlyArray<string>).includes(remembered)) return remembered as HomeView
  if ((views as ReadonlyArray<string>).includes(fallback)) return fallback as HomeView
  return views[0] ?? 'employee'
}

/** Montos de una tarjeta (costo laboral): uno por moneda, tal como los suma la API. */
export function cardAmounts(card: HomeCard): Money[] {
  const amounts = card.meta.amounts
  if (!Array.isArray(amounts)) return []
  return amounts.filter((m): m is Money => typeof m === 'object' && m !== null && typeof (m as Money).amount === 'string' && typeof (m as Money).currency === 'string')
}
