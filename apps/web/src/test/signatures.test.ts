import { describe, expect, it } from 'vitest'
import { cleanCode, signatureTone, signedCount, sortMine, type SignatureRequest } from '@/modules/documents/signatures'

const req = (over: Partial<SignatureRequest>): SignatureRequest => ({
  id: over.id ?? 'x', document_id: 'd', title: 'Doc', signing_order: 'sequential', signature_kind: 'electronic', signature_level: 'advanced',
  status: 'sent', signers: [], ...over,
})

describe('DOC-6 · flujo de firma en la interfaz', () => {
  it('cuenta las firmas hechas y el total', () => {
    const r = req({ signers: [
      { id: '1', user_id: 'a', name: 'A', role: null, order: 1, status: 'signed', viewed_at: null, signed_at: '2026-11-20', declined_reason: null, is_turn: false },
      { id: '2', user_id: 'b', name: 'B', role: null, order: 2, status: 'sent', viewed_at: null, signed_at: null, declined_reason: null, is_turn: true },
    ] })
    expect(signedCount(r)).toEqual({ signed: 1, total: 2 })
  })

  it('pone primero lo abierto y, dentro, lo que me toca firmar', () => {
    const sorted = sortMine([req({ id: 'done', status: 'completed' }), req({ id: 'wait', status: 'in_progress' }), req({ id: 'mine', status: 'sent', my_turn: true })])
    expect(sorted.map((r) => r.id)).toEqual(['mine', 'wait', 'done'])
  })

  it('el código solo admite seis dígitos', () => {
    expect(cleanCode('12 34-56 7')).toBe('123456')
    expect(signatureTone('declined')).toBe('er')
    expect(signatureTone('completed')).toBe('ok')
  })
})
