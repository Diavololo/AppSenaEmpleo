FROM php:8.2-apache

WORKDIR /var/www/html

RUN apt-get update \
  && apt-get install -y libzip-dev unzip git git-lfs \
  && docker-php-ext-install mysqli pdo_mysql \
  && a2enmod rewrite headers expires \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
  && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html

RUN composer install --no-dev --optimize-autoloader || true \
  && chown -R www-data:www-data /var/www/html

EXPOSE 80

ENV APACHE_DOCUMENT_ROOT=/var/www/html

COPY <<'APACHE_CONF' /etc/apache2/conf-available/serve-healthz.conf
Alias /healthz /var/www/html/healthz.php
<Location /healthz>
  Require all granted
</Location>
APACHE_CONF

RUN a2enconf serve-healthz

RUN printf "<?php echo 'OK '.date(DATE_ATOM);" > /var/www/html/healthz.php

CMD ["apache2-foreground"]
