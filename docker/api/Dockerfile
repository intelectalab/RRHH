FROM php:8.4-cli-alpine

# Instalar herramientas básicas del sistema y extensiones de PHP
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libpq \
    linux-headers \
    python3 \
    py3-yaml

# Instalar extensiones mediante el instalador oficial de extensiones PHP
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions \
    pdo_pgsql \
    pgsql \
    bcmath \
    redis \
    intl \
    pcntl \
    opcache \
    zip

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configurar directorio de trabajo
WORKDIR /var/www/html

# Exponer puerto 8000
EXPOSE 8000

# Comando por defecto: servidor de desarrollo de Laravel
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

