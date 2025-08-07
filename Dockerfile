# Use official PHP runtime
FROM php:8.2-fpm-alpine

# Install estensioni e composer
RUN apk add --no-cache \
    nginx \
    supervisor \
    libpng-dev \
    libzip-dev \
    oniguruma-dev \
    zip \
    unzip \
  && docker-php-ext-install pdo pdo_mysql mbstring zip

# Copia codice e imposta working dir
WORKDIR /app
COPY . /app

# Installa Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

# Configura Nginx
COPY infra/nginx.conf /etc/nginx/nginx.conf

# Espone porta e avvia php-fpm + nginx
EXPOSE 8080
CMD ["sh", "-c", "php-fpm -D && nginx -g 'daemon off;'"]
