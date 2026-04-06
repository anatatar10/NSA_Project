FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    msmtp msmtp-mta ca-certificates \
    && docker-php-ext-install mysqli \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY index.php /var/www/html/index.php

RUN mkdir -p /sessions \
    && chown -R www-data:www-data /sessions /var/www/html

RUN printf "sendmail_path = /usr/sbin/sendmail -t -i\n" > /usr/local/etc/php/conf.d/mail.ini

RUN printf "defaults\n\
auth off\n\
tls off\n\
logfile /tmp/msmtp.log\n\
account default\n\
host mailserver\n\
port 1025\n\
from noreply@works-on-my-machine.rip\n" > /etc/msmtprc \
    && chmod 644 /etc/msmtprc

EXPOSE 80