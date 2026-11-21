# QA-17 · Imagen de producción de las aplicaciones Next.js: APP=web (aplicación de las organizaciones, :3000)
# o APP=site (sitio comercial, :3001). Se construye con `next build` y corre con `next start`.
FROM node:22-alpine AS build
ARG APP=web
WORKDIR /app
RUN apk add --no-cache libc6-compat
COPY packages /packages
COPY apps/${APP}/package.json apps/${APP}/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY apps/${APP} ./
# El reenvío de /api a la API se fija al construir (next.config.ts · rewrites).
ARG NEXT_PUBLIC_API_URL=http://api:8000/api
# Sitio: robots.txt y sitemap se generan al construir; SITE_ENV=production es lo único que se indexa (WEB-1).
ARG SITE_ENV=staging
ARG SITE_URL=https://staging.gestiahr.com
ENV NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL} SITE_ENV=${SITE_ENV} SITE_URL=${SITE_URL} NODE_ENV=production NEXT_TELEMETRY_DISABLED=1
RUN npm run build && npm prune --omit=dev

FROM node:22-alpine
ARG APP=web
ARG PORT=3000
WORKDIR /app
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 PORT=${PORT} HOSTNAME=0.0.0.0
COPY --from=build --chown=node:node /app ./
USER node
EXPOSE ${PORT}
CMD ["sh", "-c", "exec node_modules/.bin/next start -p $PORT"]
