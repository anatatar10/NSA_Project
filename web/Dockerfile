FROM php:8.1-apache
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli
RUN pecl install redis && docker-php-ext-enable redis
COPY . /var/www/html/
