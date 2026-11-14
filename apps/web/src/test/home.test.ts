import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, expect, it } from 'vitest'
import { cardAmounts, pickView, type HomeCard } from '@/modules/home/homeView'
import { memberLinks } from '@/modules/home/MyTeamScreen'
import { urlState } from '@/ui/components/DataTable'

describe('COL-12 · MSS-1 · inicio según el rol', () => {
  it('abre la vista que indica la API y respeta la recordada solo si sigue disponible', () => {
    expect(pickView(['hr', 'employee'], 'hr', null)).toBe('hr')
    expect(pickView(['manager', 'employee'], 'manager', 'employee')).toBe('employee')
    // Una vista recordada que ya no tiene (cambió de rol) no se abre.
    expect(pickView(['employee'], 'employee', 'hr')).toBe('employee')
    expect(pickView([], 'x', null)).toBe('employee')
  })

  it('el costo laboral se muestra tal como lo suma la API, una línea por moneda', () => {
    const card: HomeCard = { key: 'labor_cost', module: 'core', value: null, link: '/', tone: null, items: [], meta: { amounts: [{ amount: '1200.50', currency: 'PEN' }, { amount: '300.00', currency: 'USD' }, { amount: 5 }] } }
    expect(cardAmounts(card)).toEqual([{ amount: '1200.50', currency: 'PEN' }, { amount: '300.00', currency: 'USD' }])
  })

  it('las tarjetas enlazan a listas que leen sus filtros de la dirección', () => {
    expect(urlState('?filter[hired_from]=2026-11-01&filter[hired_to]=2026-11-30&q=ana')).toEqual({ q: 'ana', filters: { hired_from: '2026-11-01', hired_to: '2026-11-30' } })
    expect(urlState('?otro=1&filter[bad-key]=x')).toEqual({ q: '', filters: {} })
  })

  it('MSS-2 · desde Mi equipo se inicia un movimiento solo si la API lo ofrece', () => {
    expect(memberLinks({ id: 'e1', team_actions: ['propose_movement'] })).toEqual([{ key: 'my_team.action_movement', href: '/personas/colaboradores/e1?tab=history&accion=movimiento' }])
    expect(memberLinks({ id: 'e1', team_actions: [] })).toEqual([])
  })

  it('cada texto de los tableros existe en los tres idiomas', () => {
    const cards = ['pending_approvals', 'headcount', 'hires_month', 'terminations_month', 'pending_start', 'contracts_expiring', 'probation_ending', 'absent_today', 'on_vacation', 'pending_change_requests', 'vacant_positions', 'labor_cost', 'turnover_12m', 'birthdays', 'anniversaries']
    const sources = ['../modules/home/HomeBoard.tsx', '../modules/home/MyTeamScreen.tsx', '../modules/session/HomeScreen.tsx'].map((f) => readFileSync(resolve(__dirname, f), 'utf8')).join('\n')
    const keys = [...sources.matchAll(/t\('((?:home_board|my_team)\.[a-z_]+)'/g)].map((m) => m[1] ?? '')
    keys.push(...cards.map((c) => `home_board.card_${c}`), 'my_team.action_movement', 'my_team.action_vacation', 'home_board.view_hr', 'home_board.view_manager', 'home_board.view_employee')
    for (const lang of ['es', 'en', 'pt']) {
      const messages = JSON.parse(readFileSync(resolve(__dirname, `../../messages/${lang}.json`), 'utf8')) as Record<string, Record<string, string>>
      for (const key of keys) {
        const [ns, k] = key.split('.') as [string, string]
        expect(messages[ns]?.[k], `${lang}:${key}`).toBeTruthy()
      }
    }
  })
})
