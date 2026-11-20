/** DOC-6 · Tipos y ayudas de presentación del flujo de firma (el orden, el turno y la evidencia los decide la API). */
export type SignatureStatus = 'draft' | 'sent' | 'viewed' | 'in_progress' | 'completed' | 'declined' | 'expired' | 'cancelled'

export type Signer = {
  id: string
  user_id: string | null
  name: string
  role: string | null
  order: number
  status: 'pending' | 'sent' | 'viewed' | 'signed' | 'declined'
  viewed_at: string | null
  signed_at: string | null
  declined_reason: string | null
  is_turn: boolean
}

export type SignatureRequest = {
  id: string
  document_id: string
  document_title?: string | null
  category_name?: string | null
  title: string
  signing_order: 'sequential' | 'parallel'
  signature_kind: 'electronic' | 'certificate'
  signature_level: string
  status: SignatureStatus
  sent_at?: string | null
  expires_at?: string | null
  completed_at?: string | null
  final_hash?: string | null
  cancellation_reason?: string | null
  has_certificate?: boolean
  signers: Signer[]
  allowed_actions?: string[]
  my_turn?: boolean
  my_status?: string | null
}

export type EvidenceEvent = {
  id: string
  event: string
  signer_id: string | null
  document_hash: string
  ip: string | null
  user_agent: string | null
  otp_channel: string | null
  provider_evidence: Record<string, string> | null
  occurred_at: string
  event_hash: string
  valid: boolean
}

export type Evidence = { chain_valid: boolean; document_intact: boolean; document_hash: string; final_hash: string | null; events: EvidenceEvent[] }

type Tone = 'ok' | 'wa' | 'er' | 'in' | 'gr'
const TONES: Record<SignatureStatus, Tone> = {
  draft: 'gr', sent: 'in', viewed: 'in', in_progress: 'wa', completed: 'ok', declined: 'er', expired: 'er', cancelled: 'gr',
}

export function signatureTone(status: SignatureStatus): Tone {
  return TONES[status] ?? 'gr'
}

/** Avance «firmaron n de m» para la lista y el lote. */
export function signedCount(r: Pick<SignatureRequest, 'signers'>): { signed: number; total: number } {
  return { signed: r.signers.filter((s) => s.status === 'signed').length, total: r.signers.length }
}

/** Abiertas (esperan firmas) primero; dentro de ellas, las que me toca firmar. */
export function sortMine(rows: SignatureRequest[]): SignatureRequest[] {
  const open = (r: SignatureRequest) => (['sent', 'viewed', 'in_progress'].includes(r.status) ? 0 : 1)
  return [...rows].sort((a, b) => open(a) - open(b) || Number(!!b.my_turn) - Number(!!a.my_turn))
}

/** El código de firma es numérico de 6 dígitos: se limpia lo que no es dígito antes de enviarlo. */
export function cleanCode(value: string): string {
  return value.replace(/\D/g, '').slice(0, 6)
}
