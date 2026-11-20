import type { Metadata, Viewport } from 'next'
import { notFound } from 'next/navigation'
import type { ReactNode } from 'react'
import '../site.css'
import '../extras.css'
import { LOCALES, isLocale } from '@/lib/i18n'
import { isIndexable } from '@/lib/seo'

export const metadata: Metadata = {
  icons: { icon: '/favicon.svg', apple: '/apple-touch-icon.png' },
  robots: isIndexable() ? undefined : { index: false, follow: false },
}

export const viewport: Viewport = { width: 'device-width', initialScale: 1, themeColor: '#0A3A73' }

export function generateStaticParams() {
  return LOCALES.map((lang) => ({ lang }))
}

export default async function RootLayout({ children, params }: { children: ReactNode; params: Promise<{ lang: string }> }) {
  const { lang } = await params
  if (!isLocale(lang)) notFound()
  return (
    <html lang={lang}>
      <head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
        {/* eslint-disable-next-line @next/next/no-page-custom-font */}
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" />
      </head>
      <body>{children}</body>
    </html>
  )
}
