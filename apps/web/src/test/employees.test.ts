import { describe, expect, it } from 'vitest'
import { translate } from '@/lib/i18n'
import { EMPLOYMENT_STATUSES, ofEntity, STATUS_TONE } from '@/modules/people/employment'
import { peopleTabs } from '@/modules/people/peopleTabs'

describe('COL-2 · COL-9 · colaboradores', () => {
  const t = (k: string) => translate('es', k)

  it('la pestaña Colaboradores aparece solo con permiso de ver colaboradores', () => {
    expect(peopleTabs(t, 'employees', () => true)[0]).toMatchObject({ href: '/personas/colaboradores', label: 'Colaboradores', active: true })
    expect(peopleTabs(t, 'directory', (p) => p === 'people.directory.view').some((tab) => tab.href === '/personas/colaboradores')).toBe(false)
    // Sin permiso de directorio, la ficha del colaborador no ofrece pestañas que darían 403.
    expect(peopleTabs(t, 'employees', (p) => p === 'people.employments.view').map((tab) => tab.href)).toEqual(['/personas/colaboradores'])
  })

  it('cada estado de ME-01 tiene color y texto en los tres idiomas', () => {
    for (const s of EMPLOYMENT_STATUSES) {
      expect(STATUS_TONE[s]).toBeTruthy()
      for (const locale of ['es', 'en', 'pt'] as const) expect(translate(locale, `emp.status_${s}`)).not.toBe(`emp.status_${s}`)
    }
  })

  it('las opciones de unidad, sede y centro se limitan a la empresa elegida o a las compartidas', () => {
    const rows = [{ id: 'a', legal_entity_id: 'e1' }, { id: 'b', legal_entity_id: 'e2' }, { id: 'c', legal_entity_id: null }]
    expect(ofEntity(rows, 'e1').map((r) => r.id)).toEqual(['a', 'c'])
  })
})
