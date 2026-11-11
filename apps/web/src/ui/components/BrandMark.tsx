/** Logo provisional de GestiaHR (ADR 0014): una «G» que rodea un punto, la persona al centro. Colores por tokens. */
export function BrandMark() {
  return (
    <svg className="mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
      <rect width="32" height="32" rx="8" className="mark-bg" />
      <path d="M21.66 10.34A8 8 0 1 0 24 16h-5" fill="none" className="mark-g" strokeWidth="3.4" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx="14.4" cy="16" r="2.2" className="mark-p" />
    </svg>
  )
}
