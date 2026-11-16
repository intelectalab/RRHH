import { describe, expect, it } from 'vitest'
import { outcomeText } from '@/modules/imports/outcome'
import { translate } from '@/lib/i18n'

describe('IMP-6 · resultado del lote', () => {
  const t = (key: string, vars?: Record<string, string | number>) => translate('es', key, vars)

  it('muestra creadas, actualizadas y rechazadas tal como las cuenta la API', () => {
    expect(outcomeText({ created_rows: 12, updated_rows: 0, rejected_rows: 3 }, t)).toBe('12 creadas · 0 actualizadas · 3 rechazadas')
  })

  it('no muestra nada mientras el lote no terminó (la API no envía conteos)', () => {
    expect(outcomeText({ created_rows: null, updated_rows: null, rejected_rows: null }, t)).toBeNull()
    expect(outcomeText({}, t)).toBeNull()
  })
})
