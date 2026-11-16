import type { Metadata, Viewport } from 'next'
import '@/ui/tokens.css'
import '@/ui/base.css'
import '@/ui/shell.css'
import { themeBootScript } from '@/lib/theme'
import { ServiceWorker } from '@/modules/pwa/ServiceWorker'

export const metadata: Metadata = {
  title: 'GestiaHR',
  icons: { icon: '/favicon.svg', apple: '/icon-192.png' },
  manifest: '/manifest.webmanifest',
  appleWebApp: { capable: true, title: 'GestiaHR', statusBarStyle: 'default' },
}

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  themeColor: [
    { media: '(prefers-color-scheme: light)', color: '#0A3A73' },
    { media: '(prefers-color-scheme: dark)', color: '#071A33' },
  ],
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="es" data-theme="light" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeBootScript }} />
      </head>
      <body>
        {children}
        <ServiceWorker />
      </body>
    </html>
  )
}
