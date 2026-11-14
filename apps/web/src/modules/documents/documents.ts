import type { Schema } from '@/lib/api'
import type { StatusTone } from '@/ui/components/Status'

/** DOC-1 · DOC-2 · Documento del legajo (GET /documents/{id}); «por vencer» y los días los calcula el servidor. */
export type LegajoDocument = Schema<'Document'> & { employee_code?: string | null }

export type DocumentStatus = LegajoDocument['status']
export type Classification = LegajoDocument['classification']

/** Referencia a un documento que guarda otro módulo (sustento de una ausencia), sin copiar el archivo. */
export type LinkedDocument = {
  ref: string
  source: string
  id: string
  folder_code: string
  title: string
  subtitle: string | null
  issued_on: string | null
  file_name: string
  mime_type: string
  size_bytes: number
  uploaded_at: string
  sensitive: boolean
  link: string | null
}

export type LegajoFolder = {
  id: string
  code: string
  name: string
  is_system: boolean
  count: number
  expiring: number
  expired: number
  documents: LegajoDocument[]
  linked: LinkedDocument[]
}

export type Legajo = { employment_id: string; folders: LegajoFolder[] }

/** Carpeta o tipo documental (GET /document-categories). */
export type DocumentType = {
  id: string
  code: string
  name: string
  parent_id: string | null
  description: string | null
  default_classification: Classification
  required_permission_code: string | null
  scope: 'employee' | 'company'
  visible_to_employee: boolean
  requires_review: boolean
  requires_expiry: boolean
  expiry_notice_days: number | null
  is_system: boolean
  sort_order: number
  status: 'active' | 'archived'
  allowed_actions: string[]
}

export type DocumentFolder = DocumentType & { types: DocumentType[] }

export type DocumentVersionRow = {
  version: number
  is_current: boolean
  file_name: string
  mime_type: string
  size_bytes: number
  issued_on: string | null
  expires_on: string | null
  notes: string | null
  content_hash: string
  uploaded_by_name: string | null
  uploaded_at: string
}

export const DOC_STATUSES = ['draft', 'pending_approval', 'active', 'archived', 'expired', 'rejected', 'requested'] as const satisfies readonly DocumentStatus[]
export const CLASSIFICATIONS = ['public', 'internal', 'confidential', 'restricted'] as const satisfies readonly Classification[]

export const STATUS_TONE: Record<DocumentStatus, StatusTone> = {
  draft: 'gr', pending_approval: 'wa', active: 'ok', archived: 'gr', expired: 'er', rejected: 'er', requested: 'in',
}

/** Acciones del documento que se ofrecen como botón, en orden; las valida el servidor (allowed_actions). */
export const DOC_ACTIONS = ['approve', 'reject', 'new_version', 'update', 'archive', 'restore', 'delete'] as const
export type DocAction = (typeof DOC_ACTIONS)[number]

export function documentButtons(doc: Pick<LegajoDocument, 'allowed_actions'>): DocAction[] {
  return DOC_ACTIONS.filter((a) => doc.allowed_actions.includes(a))
}

/** Tono del aviso de vencimiento según lo que informa el servidor. */
export function expiryTone(doc: Pick<LegajoDocument, 'status' | 'expiring' | 'days_to_expiry'>): StatusTone | null {
  if (doc.status === 'expired' || (doc.days_to_expiry !== null && doc.days_to_expiry !== undefined && doc.days_to_expiry < 0)) return 'er'
  if (doc.expiring) return 'wa'
  return null
}

export const isConfidential = (c: Classification) => c === 'confidential' || c === 'restricted'

/** Tamaño legible (KB/MB) sin cálculo de negocio. */
export function fileSize(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
