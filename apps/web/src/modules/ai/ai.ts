/** IAR-9 · IAR-10 · Disponibilidad del asistente de ayuda para el usuario actual (la decide la API). */
export type AssistantAvailability = {
  available: boolean
  notice: string | null
  question_limit: number | null
  limit_period: 'day' | 'week' | 'month' | null
  remaining: number | null
}

/** IAR-9 · Respuesta del asistente: solo con artículos visibles, citados, y con explicación. */
export type AssistantAnswer = {
  id: string
  question: string
  answered: boolean
  answer: string | null
  explanation: string
  citations: Array<{ id: string; slug: string | null; title: string; source: 'product' | 'organization' }>
  escalation: { offered: boolean; available: boolean }
  provider_is_fake: boolean
  remaining: number | null
}

/** IAR-10 · Activación de un caso de uso de IA en la organización. */
export type AiUseCaseSetting = {
  id: string
  use_case: string
  enabled: boolean
  role_ids: string[]
  question_limit: number | null
  limit_period: 'day' | 'week' | 'month'
  notice: string | null
  plan_allows: boolean
}

/** IAR-11 · Calidad del asistente de ayuda. */
export type AssistantQuality = {
  days: number
  questions: number
  answered: number
  unanswered: number
  rated_up: number
  rated_down: number
  unanswered_questions: Array<{ query_text: string; times: number; last_at: string }>
  badly_rated: Array<{ interaction_id: string; cited_articles: Array<{ id: string; title: string | null }>; comment: string | null; rated_at: string }>
}

/** IAR-2 · Fila de la bitácora de IA (metadatos, sin contenido). */
export type AiUsageLog = {
  id: string
  user_id: string | null
  user_name: string | null
  use_case: string
  provider: string | null
  model: string | null
  data_scopes: string[]
  input_tokens: number | null
  output_tokens: number | null
  was_blocked: boolean
  block_reason: string | null
  citations: string[]
  content_purged: boolean
  occurred_at: string
}
