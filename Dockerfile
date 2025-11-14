FROM php:8.2-cli

WORKDIR /app

RUN apt-get update \
  && apt-get install -y libzip-dev unzip git git-lfs \
  && docker-php-ext-install mysqli pdo_mysql \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . .

RUN git lfs install \
  && git lfs pull \
  && composer install --no-dev --optimize-autoloader

CMD ["bash", "-lc", "php -S 0.0.0.0: index.php"]
