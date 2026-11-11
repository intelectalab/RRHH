import { api } from '@/lib/api'
import type { Person } from '@/ui/components/PersonPicker'

type UserRow = { id: string; display_name: string; email?: string | null; status: string }

/**
 * Búsqueda de personas para el selector (UI-3). Consulta /users con el permiso y el alcance de quien
 * pregunta: el servidor decide a quién puede ver; aquí solo se adapta la respuesta.
 */
export async function searchActiveUsers(q: string): Promise<Person[]> {
  const params = new URLSearchParams({ per_page: '10', status: 'active' })
  if (q !== '') params.set('q', q)
  const res = await api.get<{ data: UserRow[] }>(`/users?${params.toString()}`)
  return res.data.map((u) => ({ id: u.id, name: u.display_name, detail: u.email ?? undefined }))
}
