FROM php:8.5.10-apache-bookworm AS runtime

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends gosu libpq-dev libzip-dev libicu-dev libjpeg62-turbo-dev libpng-dev libwebp-dev tesseract-ocr tesseract-ocr-eng tesseract-ocr-nld \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql zip intl pcntl gd exif \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && a2enmod rewrite \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && rm -rf /var/lib/apt/lists/*

COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/fotoarchief.ini
COPY deploy/entrypoint.sh /usr/local/bin/fotoarchief-entrypoint

FROM runtime AS dependencies

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --no-progress \
    --prefer-dist

FROM runtime AS application

COPY --from=dependencies /var/www/html/vendor ./vendor
COPY . .

RUN mkdir -p \
        bootstrap/cache \
        storage/app \
        storage/framework/cache/data \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data bootstrap/cache storage \
    && chmod +x /usr/local/bin/fotoarchief-entrypoint

EXPOSE 8080

ENTRYPOINT ["fotoarchief-entrypoint"]
CMD ["apache2-foreground"]
