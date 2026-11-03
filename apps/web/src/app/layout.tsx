import type { Metadata } from 'next'
import '@/ui/tokens.css'

export const metadata: Metadata = {
  title: 'Ayni HR',
  description: 'Sistema de Gestión de Recursos Humanos',
}

export default function RootLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <html lang="es">
      <body>{children}</body>
    </html>
  )
}

