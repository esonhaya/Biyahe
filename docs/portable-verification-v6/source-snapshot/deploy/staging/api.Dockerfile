FROM php:8.3.35-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends gosu libicu-dev libonig-dev libpq-dev libxml2-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" dom intl mbstring pdo_pgsql pgsql xml xmlreader xmlwriter zip opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8.12 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY api/ ./

RUN composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --classmap-authoritative \
    && APP_ENV=production APP_KEY= php artisan package:discover --ansi \
    && APP_ENV=production APP_KEY= php artisan filament:upgrade --ansi \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && sed -ri -e 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/biyahe-public.conf \
    && a2enconf biyahe-public \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && mkdir -p /var/run/apache2 /var/lock/apache2 \
    && chown -R www-data:www-data storage bootstrap/cache /var/run/apache2 /var/lock/apache2 /var/log/apache2

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
COPY deploy/staging/api-entrypoint.sh /usr/local/bin/biyahe-entrypoint
RUN chmod 0755 /usr/local/bin/biyahe-entrypoint

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/biyahe-entrypoint"]
CMD ["apache2-foreground"]
