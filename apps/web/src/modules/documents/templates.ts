import type { Schema } from '@/lib/api'
import type { StatusTone } from '@/ui/components/Status'

/** DOC-4 · Plantilla (GET /document-templates/{id}); body es el borrador si existe, si no la versión publicada. */
export type DocumentTemplate = Schema<'DocumentTemplate'>
export type TemplateStatus = DocumentTemplate['status']

/** DOC-5 · Lote de generación con su avance (GET /document-generation-batches/{id}). */
export type GenerationBatch = Schema<'DocumentGenerationBatch'>
export type BatchItem = { employment_id: string; status: 'pending' | 'generated' | 'failed'; document_id?: string; version?: number; person_name?: string; error?: string }

/** DOC-3 · Solicitud de documento (GET /document-requests). */
export type DocumentRequestRow = Schema<'DocumentRequest'>
export type RequestStatus = DocumentRequestRow['status']

/** Variable del catálogo seguro (GET /document-templates/variables). */
export type TemplateVariable = { name: string; group: string; label: string; example: string; sensitive: boolean; permission: string | null; allowed: boolean }

/** Vista previa resuelta por el servidor (POST /document-templates/{id}/preview). */
export type TemplatePreview = { title: string; text: string; variables: string[]; unknown: string[]; withheld: string[]; syntax_errors: string[]; version: number | null }

export const TEMPLATE_KINDS = ['certificate', 'letter', 'offer', 'policy', 'other', 'contract', 'amendment'] as const

export const TEMPLATE_TONE: Record<TemplateStatus, StatusTone> = { draft: 'gr', active: 'ok', inactive: 'wa' }

export const REQUEST_TONE: Record<RequestStatus, StatusTone> = { requested: 'in', submitted: 'wa', approved: 'ok', rejected: 'er', cancelled: 'gr' }

export const BATCH_TONE: Record<GenerationBatch['status'], StatusTone> = { queued: 'gr', running: 'in', completed: 'ok', completed_with_errors: 'wa', failed: 'er' }

export function batchFinished(b: GenerationBatch): boolean {
  return b.status === 'completed' || b.status === 'completed_with_errors' || b.status === 'failed'
}

/** Inserta la variable en la posición del cursor del editor y devuelve el texto y la nueva posición. */
export function insertVariable(body: string, start: number, end: number, name: string): { body: string; caret: number } {
  const tag = `{{${name}}}`
  return { body: body.slice(0, start) + tag + body.slice(end), caret: start + tag.length }
}
