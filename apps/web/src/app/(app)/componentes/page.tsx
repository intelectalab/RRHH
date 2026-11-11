import { notFound } from 'next/navigation'
import { ComponentCatalog } from '@/modules/ui-catalog/ComponentCatalog'

/**
 * UI-3 · Catálogo de componentes. Disponible en desarrollo y pruebas; en producción solo si se
 * publica a propósito con NEXT_PUBLIC_UI_CATALOG=1 (no es una pantalla para clientes).
 */
export default function ComponentesPage() {
  if (process.env.NODE_ENV === 'production' && process.env.NEXT_PUBLIC_UI_CATALOG !== '1') notFound()
  return <ComponentCatalog />
}
