import type { NextConfig } from 'next'

/**
 * WEB-1 · Sitio comercial gestiahr.com: aplicación aparte de la del cliente y de la consola. Solo habla con la
 * API pública de la plataforma (/api/v1/public/*) desde el servidor; nunca con la base de las organizaciones.
 */
const nextConfig: NextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
          { key: 'X-Frame-Options', value: 'DENY' },
        ],
      },
    ]
  },
}

export default nextConfig
