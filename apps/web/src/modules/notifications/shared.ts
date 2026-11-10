export type AppNotification = {
  id: string
  category: string
  group: string
  title: string
  body: string | null
  action_url: string | null
  priority: 'low' | 'normal' | 'high'
  read: boolean
  created_at: string | null
}

export type NotificationPage = { data: AppNotification[]; meta: { per_page: number; next_cursor: string | null; unread: number } }

/** Avisa a la campana que cambió el estado de lectura (se recalcula sin recargar). */
export const NOTIFICATIONS_CHANGED = 'ayni:notifications-changed'

export function notifyNotificationsChanged(): void {
  window.dispatchEvent(new Event(NOTIFICATIONS_CHANGED))
}

export function categoryLabel(t: (k: string) => string, code: string): string {
  const key = `ncat.${code.replace(/\./g, '_')}`
  const label = t(key)
  return label === key ? code : label
}
