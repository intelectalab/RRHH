import type { Metadata } from 'next'
import '@/ui/tokens.css'
import '@/ui/base.css'
import '@/ui/shell.css'

export const metadata: Metadata = {
  title: 'Ayni HR',
  icons: { icon: '/favicon.svg' },
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

