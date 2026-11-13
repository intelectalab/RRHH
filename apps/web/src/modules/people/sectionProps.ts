/**
 * Props comunes de las secciones COL-6/7/8. Son autónomas: la ficha 360 las monta como pestañas pasando la
 * relación laboral, la persona y su aviso (toast); no dependen de ninguna pantalla concreta.
 */
export type PeopleSectionProps = {
  employmentId: string
  /** Persona de la relación; los derechohabientes y cuentas son de la persona, pero se consultan desde la relación. */
  personId: string
  onToast: (text: string, tone?: 'ok' | 'error') => void
}
