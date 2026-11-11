import { encode } from 'uqr'

/** Código QR dibujado como SVG (sin HTML inyectado). Colores fijos (--qr-claro / --qr-oscuro) para que la cámara lo lea también en modo oscuro. */
export function QrCode({ value, size = 184, label }: { value: string; size?: number; label: string }) {
  const { data, size: n } = encode(value, { border: 2 })
  const cells: string[] = []
  data.forEach((row, y) => row.forEach((on, x) => { if (on) cells.push(`M${x} ${y}h1v1h-1z`) }))
  return (
    <svg className="qr" width={size} height={size} viewBox={`0 0 ${n} ${n}`} role="img" aria-label={label} shapeRendering="crispEdges">
      <rect width={n} height={n} style={{ fill: 'var(--qr-claro)' }} />
      <path d={cells.join('')} style={{ fill: 'var(--qr-oscuro)' }} />
    </svg>
  )
}
