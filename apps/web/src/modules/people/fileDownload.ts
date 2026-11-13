import { api } from '@/lib/api'

/** Descarga un archivo de la API y lo ofrece al navegador con el nombre que sugiere el servidor. */
export async function saveFromApi(endpoint: string): Promise<void> {
  const { blob, filename } = await api.download(endpoint)
  const href = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = href
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(href)
}

/** Suma exacta de porcentajes con dos decimales (en centésimas), para avisar antes de enviar. */
export function percentTotal(values: Array<string | number>): number {
  return values.reduce<number>((sum, v) => sum + Math.round(Number(String(v).replace(',', '.')) * 100 || 0), 0) / 100
}
