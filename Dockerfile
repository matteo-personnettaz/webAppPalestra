# usa un’immagine PHP + Apache
FROM php:8.1-apache

# copia tutto il codice nella webroot di Apache
COPY . /var/www/html/

# (opzionale) installa estensioni se ti servono, ad esempio mysql
# RUN docker-php-ext-install pdo pdo_mysql
