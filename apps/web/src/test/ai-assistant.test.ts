import { describe, expect, it } from 'vitest'
import en from '../../messages/en.json'
import es from '../../messages/es.json'
import pt from '../../messages/pt.json'
import { helpScreenFor } from '@/modules/help/screens'

describe('IAR-9 · IAR-10 · asistente de ayuda', () => {
  it('la pantalla de activación tiene su clave de ayuda', () => {
    expect(helpScreenFor('/configuracion/asistente')).toBe('settings.ai_assistant')
  })

  it('los textos del asistente existen en los tres idiomas', () => {
    const keys = Object.keys(es.ai).sort()
    expect(Object.keys(en.ai).sort()).toEqual(keys)
    expect(Object.keys(pt.ai).sort()).toEqual(keys)
  })
})
