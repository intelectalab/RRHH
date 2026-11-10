import type { Metadata, Viewport } from 'next'
import '@/ui/tokens.css'
import '@/ui/base.css'
import '@/ui/shell.css'
import { themeBootScript } from '@/lib/theme'

export const metadata: Metadata = {
  title: 'Ayni HR',
  icons: { icon: '/favicon.svg', apple: '/icon-192.png' },
  manifest: '/manifest.webmanifest',
  appleWebApp: { capable: true, title: 'Ayni HR', statusBarStyle: 'default' },
}

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  themeColor: [
    { media: '(prefers-color-scheme: light)', color: '#0E4F5C' },
    { media: '(prefers-color-scheme: dark)', color: '#0B3A44' },
  ],
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="es" data-theme="light" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeBootScript }} />
      </head>
      <body>{children}</body>
    </html>
  )
}
