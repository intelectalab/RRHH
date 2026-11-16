import { describe, expect, it } from 'vitest'
import { groupByMonth, teamStartLinks, teamTabs, type TeamKeyDate } from '@/modules/home/team'

const item = (date: string, kind: TeamKeyDate['kind'] = 'birthday'): TeamKeyDate => ({ kind, date, employment_id: date, name: 'Juan', is_direct: true, years: null, link: '/x' })

describe('MSS-3 · MSS-4 · pantallas del equipo', () => {
  it('agrupa las fechas por mes en el orden de la API', () => {
    const groups = groupByMonth([item('2026-11-20'), item('2026-11-28', 'contract_end'), item('2026-12-01')])
    expect(groups.map((g) => g.month)).toEqual(['2026-11', '2026-12'])
    expect(groups[0].items).toHaveLength(2)
  })

  it('muestra la pestaña de solicitudes solo con permiso del centro de aprobaciones', () => {
    const t = (k: string) => k
    expect(teamTabs(t, () => false, 'members').map((x) => x.href)).toEqual(['/mi-equipo', '/mi-equipo/fechas'])
    const all = teamTabs(t, () => true, 'requests')
    expect(all.map((x) => x.href)).toEqual(['/mi-equipo', '/mi-equipo/solicitudes', '/mi-equipo/fechas'])
    expect(all.find((x) => x.active)?.href).toBe('/mi-equipo/solicitudes')
  })

  it('ofrece iniciar solo lo que el permiso permite (la API lo vuelve a validar)', () => {
    expect(teamStartLinks(() => false)).toEqual([])
    expect(teamStartLinks((p) => p === 'org.position_requests.create').map((l) => l.key)).toEqual(['my_team.start_position'])
  })
})
