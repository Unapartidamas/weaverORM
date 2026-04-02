FROM php:8.4-cli-alpine

RUN apk add --no-cache \
        git \
        unzip \
        sqlite-dev \
        postgresql-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json phpunit.xml ./
RUN composer install --no-scripts --no-interaction --prefer-dist

COPY src/ ./src/
COPY tests/ ./tests/
COPY benchmark/ ./benchmark/

CMD ["php", "vendor/bin/phpunit", "--colors=never"]
