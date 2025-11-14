FROM php:8.2-cli

WORKDIR /app

RUN apt-get update \
  && apt-get install -y libzip-dev unzip git \
  && docker-php-ext-install mysqli pdo_mysql \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . .

RUN git lfs install \
  && git lfs pull \
  && composer install --no-dev --optimize-autoloader

CMD ["php", "-S", "0.0.0.0:80", "index.php"]
