FROM php:8.1-apache

# 1) Estensioni necessarie 
RUN docker-php-ext-install pdo_mysql && a2enmod rewrite

# 2) Timezone e opcache (opzionale ma utile)
RUN set -eux; \
    echo "date.timezone=Europe/Rome" > /usr/local/etc/php/conf.d/zz-timezone.ini; \
    docker-php-ext-install opcache; \
    { \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=0"; \
      echo "opcache.jit=1255"; \
      echo "opcache.jit_buffer_size=64M"; \
      echo "opcache.memory_consumption=128"; \
      echo "opcache.interned_strings_buffer=16"; \
      echo "opcache.max_accelerated_files=10000"; \
    } > /usr/local/etc/php/conf.d/zz-opcache.ini

# 3) Porta (Cloud Run usa 8080)
ENV PORT 8080
RUN sed -ri "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf \
 && sed -ri "s/:80/:${PORT}/g" /etc/apache2/sites-available/*.conf

# 4) Sorgenti
COPY src/ /var/www/html/
# oppure, se api.php sta in root:
# COPY api.php /var/www/html/api.php

EXPOSE 8080
CMD ["apache2-foreground"]
