import { redirect } from 'next/navigation'

/** La sección no tiene portada propia: lleva a su primera pantalla del menú (sin 404 si se escribe la dirección). */
export default function DocumentosPage() {
  redirect('/documentos/firmas')
}
