import { describe, expect, it } from 'vitest'
import { formatDate, initials, relativeTime } from '@/lib/format'
import { translate } from '@/lib/i18n'
import { typeLabel } from '@/modules/workflow/shared'

describe('formato de pendientes y aprobaciones', () => {
  it('las fechas laborales se muestran sin desplazarse por la zona horaria', () => {
    expect(formatDate('es-PE', '2026-11-09')).toContain('9')
  })

  it('la antigüedad se expresa en lenguaje natural', () => {
    const now = new Date('2026-11-10T12:00:00Z')
    expect(relativeTime('es', '2026-11-08T12:00:00Z', now)).toBe('anteayer')
    expect(relativeTime('en', '2026-11-11T12:00:00Z', now)).toBe('tomorrow')
  })

  it('un tipo de trámite sin texto propio se muestra como «Otro trámite»', () => {
    const t = (k: string) => translate('es', k)
    expect(typeLabel(t, 'vacation_request')).toBe('Vacaciones')
    expect(typeLabel(t, 'tipo_nuevo')).toBe('Otro trámite')
  })

  it('iniciales del solicitante', () => {
    expect(initials('Lucía Quispe Huamán')).toBe('LQ')
    expect(initials(null)).toBe('')
  })
})
