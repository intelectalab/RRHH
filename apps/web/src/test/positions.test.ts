import { describe, expect, it } from 'vitest'
import { translate } from '@/lib/i18n'
import { orgTabs } from '@/modules/organization/orgTabs'

describe('POS-1 · POS-2 · posiciones', () => {
  const t = (k: string) => translate('es', k)

  it('la pestaña Posiciones aparece solo con permiso y con Core HR contratado', () => {
    const all = () => true
    expect(orgTabs(t, all, 'positions', ['core']).find((tab) => tab.href === '/organizacion/posiciones')).toMatchObject({ label: 'Posiciones', active: true })
    expect(orgTabs(t, all, 'positions', []).some((tab) => tab.href === '/organizacion/posiciones')).toBe(false)
    expect(orgTabs(t, (p) => p !== 'org.positions.view', 'companies', ['core']).some((tab) => tab.href === '/organizacion/posiciones')).toBe(false)
  })

  it('cada estado y cada acción de la posición tienen texto en los tres idiomas', () => {
    const states = ['proposed', 'approved', 'open', 'recruiting', 'filled', 'frozen', 'closed']
    const actions = ['approve', 'reject', 'open_recruitment', 'cancel_recruitment', 'freeze', 'unfreeze', 'close']
    for (const locale of ['es', 'en', 'pt'] as const) {
      for (const s of states) expect(translate(locale, `pos.status_${s}`)).not.toBe(`pos.status_${s}`)
      for (const a of actions) expect(translate(locale, `pos.action_${a}`)).not.toBe(`pos.action_${a}`)
    }
  })
})
