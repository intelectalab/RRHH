/**
 * Nombre del producto en el logo. Es nombre propio (no se traduce) y vive en configuración para la
 * marca blanca (ADR 0014): ningún componente lo escribe como texto literal.
 */
export const BRAND = { prefix: 'Gestia', suffix: 'HR' } as const

export function BrandName() {
  return <b>{BRAND.prefix}<span>{BRAND.suffix}</span></b>
}
