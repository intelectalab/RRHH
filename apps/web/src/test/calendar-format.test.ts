import { describe, expect, it } from 'vitest'
import { formatDateTime } from '@/lib/format'
import { monthRange } from '@/modules/calendar/CalendarScreen'

/** CFG-8 · La vista mensual va de lunes a domingo y cubre el mes completo. */
describe('rango de la vista mensual', () => {
  it('julio de 2026 empieza el lunes 29 de junio y termina el domingo 2 de agosto', () => {
    const r = monthRange('2026-07-01')
    expect(r.from).toBe('2026-06-29')
    expect(r.to).toBe('2026-08-02')
    expect(r.days).toHaveLength(35)
    expect(r.days).toContain('2026-07-31')
  })

  it('un mes que empieza en lunes no agrega días del anterior', () => {
    const r = monthRange('2026-06-01')
    expect(r.from).toBe('2026-06-01')
    expect(r.days.length % 7).toBe(0)
  })
})

/** CFG-2 · Las fechas y horas (UTC en base) se muestran en la zona horaria de la empresa. */
describe('fecha y hora en la zona de la empresa', () => {
  const instant = '2026-07-28T03:30:00Z'

  it('el mismo instante se ve distinto en Lima y en Madrid', () => {
    expect(formatDateTime('es-ES', instant, 'America/Lima')).toContain('22:30')
    expect(formatDateTime('es-ES', instant, 'Europe/Madrid')).toContain('5:30')
  })

  it('una zona inválida no rompe la pantalla', () => {
    expect(formatDateTime('es-PE', instant, 'Zona/Inexistente')).not.toBe('')
  })
})
