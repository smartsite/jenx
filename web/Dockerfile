FROM php:8.2-apache-bookworm

RUN a2enmod rewrite

WORKDIR /var/www/html

COPY public/ /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
