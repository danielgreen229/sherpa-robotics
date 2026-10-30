FROM php:8.3-fpm-bookworm AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev librabbitmq-dev \
    && rm -rf /var/lib/apt/lists/*
# DOM, XML, XMLReader, XMLWriter, SimpleXML, mbstring and curl are already built in.
# Rebuilding DOM/XML in parallel breaks PHP's internal header dependencies.
RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j2 pdo_pgsql bcmath gd zip pcntl
RUN pecl install amqp-2.1.2 && docker-php-ext-enable amqp
RUN php -r 'foreach (["pdo_pgsql", "pdo_sqlite", "bcmath", "gd", "zip", "pcntl", "amqp", "mbstring", "dom", "xml", "xmlreader", "xmlwriter", "SimpleXML", "curl"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: " . $extension . PHP_EOL); exit(1); } }'
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/catalog.ini
WORKDIR /app

FROM php-base AS dependencies
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY backend/ ./
RUN composer dump-autoload --optimize

FROM php-base AS runtime
COPY --from=dependencies --chown=www-data:www-data /app /app
COPY --chown=www-data:www-data examples /examples
COPY docker/entrypoint.sh /usr/local/bin/catalog-entrypoint
RUN chmod +x /usr/local/bin/catalog-entrypoint && mkdir -p /data/uploads /data/media /app/var \
    && chown -R www-data:www-data /data /app/var
ENV STORAGE_PATH=/data
USER www-data
ENTRYPOINT ["catalog-entrypoint"]
CMD ["php-fpm", "-F"]
