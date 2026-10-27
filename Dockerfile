FROM php:8.3-apache

RUN docker-php-ext-install mysqli pdo_mysql \
    && a2enmod rewrite

WORKDIR /var/www/html
COPY . .

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
