import type { NextConfig } from 'next'

const nextConfig: NextConfig = {
  // Strict mode para detectar efectos no deseados
  reactStrictMode: true,

  // La API corre en el container "api"; en local se expone en 8000
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: `${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/:path*`,
      },
    ]
  },
}

export default nextConfig

