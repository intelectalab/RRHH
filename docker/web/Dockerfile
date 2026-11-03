FROM node:22-alpine

WORKDIR /app

RUN apk add --no-cache libc6-compat

EXPOSE 3000

ENV PORT=3000
ENV HOSTNAME="0.0.0.0"

CMD ["npm", "run", "dev"]

