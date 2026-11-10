FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install intl pdo_mysql zip opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/rihla.ini
COPY docker/php/entrypoint.sh /usr/local/bin/rihla-entrypoint
RUN chmod +x /usr/local/bin/rihla-entrypoint

WORKDIR /app

ENTRYPOINT ["rihla-entrypoint"]
CMD ["php-fpm"]
