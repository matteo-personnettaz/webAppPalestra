# ===== STAGE 1: dipendenze PHP con Composer =====
# FROM composer:2 AS deps
FROM php:8.3-cli AS deps
WORKDIR /app

# Copio solo i file Composer per sfruttare la cache
COPY src/composer.json src/composer.lock* ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader

# ===== STAGE 2: runtime PHP + Apache (AGGIORNATO A 8.3) =====
FROM php:8.3-apache

# 1) Estensioni necessarie
RUN apt-get update && apt-get install -y libzip-dev \
 && docker-php-ext-install pdo_mysql opcache zip \
 && a2enmod rewrite headers

# 2) Timezone e opcache
RUN set -eux; \
    echo "date.timezone=Europe/Rome" > /usr/local/etc/php/conf.d/zz-timezone.ini; \
    { \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=0"; \
      echo "opcache.jit=1255"; \
      echo "opcache.jit_buffer_size=64M"; \
      echo "opcache.memory_consumption=128"; \
      echo "opcache.interned_strings_buffer=16"; \
      echo "opcache.max_accelerated_files=10000"; \
    } > /usr/local/etc/php/conf.d/zz-opcache.ini

# 3) Cloud Run usa PORT
ENV PORT 8080
RUN sed -ri "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf \
 && sed -ri "s/:80/:${PORT}/g" /etc/apache2/sites-available/*.conf

# 4) Document root + copy
WORKDIR /var/www/html
RUN mkdir -p /var/www/html/secure && chown -R www-data:www-data /var/www/html

# Vendor dalla stage deps + sorgenti
COPY --from=deps /app/vendor /var/www/html/vendor
COPY src/ /var/www/html/

# Permessi
RUN chown -R www-data:www-data /var/www/html

EXPOSE 8080
CMD ["apache2-foreground"]
