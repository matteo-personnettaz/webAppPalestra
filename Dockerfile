# ===== STAGE 1: dipendenze PHP con Composer =====
FROM composer:2 AS deps
WORKDIR /app

# Copia solo i file Composer per sfruttare la cache Docker
COPY src/composer.json src/composer.lock* ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction

# ===== STAGE 2: runtime PHP + Apache =====
FROM php:8.1-apache

# 1) Estensioni necessarie
RUN docker-php-ext-install pdo_mysql opcache && a2enmod rewrite

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

# 4) Copia vendor dall'immagine deps (Composer) e poi i sorgenti
WORKDIR /var/www/html
COPY --from=deps /app/vendor /var/www/html/vendor
COPY src/ /var/www/html/

# (facoltativo) Permessi più sicuri
RUN chown -R www-data:www-data /var/www/html

EXPOSE 8080
CMD ["apache2-foreground"]
