FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev libicu-dev zlib1g-dev libxml2-dev libpq-dev git unzip \
    && docker-php-ext-install pdo_sqlite pdo_pgsql sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . /var/www/html

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress
RUN echo "error_reporting = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED" > /usr/local/etc/php/conf.d/error-reporting.ini

EXPOSE 8080 8087

CMD ["sh", "-c", "php -S 0.0.0.0:8080 -t /var/www/html >/tmp/ercsocket-web.log 2>&1 & exec php /var/www/html/server.php"]
