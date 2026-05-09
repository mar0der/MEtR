FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        bash \
        curl \
        icu-dev \
        libzip-dev \
        nodejs \
        npm \
        oniguruma-dev \
        sqlite-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        bcmath \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        pdo_sqlite \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY ./php.ini /usr/local/etc/php/conf.d/99-custom.ini
COPY ./php-fpm-www.conf /usr/local/etc/php-fpm.d/zz-metr-sync-www.conf
COPY ./docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
