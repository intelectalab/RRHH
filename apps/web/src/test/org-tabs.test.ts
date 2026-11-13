import { describe, expect, it } from 'vitest'
import { orgTabs } from '@/modules/organization/orgTabs'

const t = (k: string) => k

describe('pestañas de Organización (POS-3 · POS-4)', () => {
  it('muestra solicitudes y dotación solo con su permiso y Core HR contratado', () => {
    const all = (p: string) => ['org.positions.view', 'org.position_requests.view'].includes(p)
    const hrefs = orgTabs(t, all, 'headcount', ['core']).map((x) => x.href)
    expect(hrefs).toEqual(['/organizacion/posiciones', '/organizacion/solicitudes-de-posicion', '/organizacion/dotacion'])
    expect(orgTabs(t, all, 'headcount', ['core']).find((x) => x.active)?.href).toBe('/organizacion/dotacion')
    expect(orgTabs(t, all, 'headcount', []).map((x) => x.href)).toEqual([])
  })

  it('quien solo solicita ve la pestaña de solicitudes, no la dotación', () => {
    const requester = (p: string) => p === 'org.position_requests.view'
    expect(orgTabs(t, requester, 'position_requests', ['core']).map((x) => x.href)).toEqual(['/organizacion/solicitudes-de-posicion'])
  })
})
