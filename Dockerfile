# usa un’immagine ufficiale PHP con Apache
FROM php:8.1-apache

# copia il tuo codice nell’host di Apache
COPY . /var/www/html/

# (opzionale) abilita estensioni PDO/MySQL o PDO/Postgres come serve
RUN docker-php-ext-install pdo pdo_mysql

# assegna permessi se serve
RUN chown -R www-data:www-data /var/www/html

# espone la porta 80
EXPOSE 80

# comando di avvio è già apache2-foreground
