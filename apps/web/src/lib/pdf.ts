/**
 * PDF de una sola página con una imagen JPEG (ORG-6: exportar el organigrama). Sin librerías: el
 * formato admite incrustar un JPEG tal cual (filtro DCTDecode), así que basta con armar los objetos
 * y la tabla de referencias cruzadas.
 */

const enc = new TextEncoder()

/**
 * @param jpeg   bytes del JPEG
 * @param width  ancho de la imagen en píxeles
 * @param height alto de la imagen en píxeles
 * @param maxPt  lado mayor de la página en puntos (la imagen se escala para entrar)
 */
export function imagePdf(jpeg: Uint8Array, width: number, height: number, maxPt = 1684): Uint8Array {
  const scale = Math.min(1, maxPt / Math.max(width, height)) * 0.75
  const pw = Math.max(1, Math.round(width * scale * 100) / 100)
  const ph = Math.max(1, Math.round(height * scale * 100) / 100)
  const content = `q ${pw} 0 0 ${ph} 0 0 cm /Im0 Do Q`

  const chunks: Uint8Array[] = []
  const offsets: number[] = []
  let length = 0
  const push = (part: string | Uint8Array) => {
    const bytes = typeof part === 'string' ? enc.encode(part) : part
    chunks.push(bytes)
    length += bytes.length
  }
  const obj = (n: number, body: string) => {
    offsets[n] = length
    push(`${n} 0 obj\n${body}\nendobj\n`)
  }

  push('%PDF-1.4\n%âãÏÓ\n')
  obj(1, '<< /Type /Catalog /Pages 2 0 R >>')
  obj(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>')
  obj(3, `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pw} ${ph}] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>`)
  offsets[4] = length
  push(`4 0 obj\n<< /Type /XObject /Subtype /Image /Width ${width} /Height ${height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpeg.length} >>\nstream\n`)
  push(jpeg)
  push('\nendstream\nendobj\n')
  obj(5, `<< /Length ${enc.encode(content).length} >>\nstream\n${content}\nendstream`)

  const xref = length
  let table = 'xref\n0 6\n0000000000 65535 f \n'
  for (let i = 1; i <= 5; i++) table += `${String(offsets[i]).padStart(10, '0')} 00000 n \n`
  push(`${table}trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`)

  const out = new Uint8Array(length)
  let at = 0
  for (const c of chunks) {
    out.set(c, at)
    at += c.length
  }
  return out
}

/** Bytes de un data URL base64 (canvas.toDataURL). */
export function dataUrlBytes(dataUrl: string): Uint8Array {
  const b64 = dataUrl.slice(dataUrl.indexOf(',') + 1)
  const bin = atob(b64)
  const out = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i)
  return out
}
