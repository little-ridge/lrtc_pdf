FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs

FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd mbstring zip \
    && rm -rf /var/lib/apt/lists/* \
    && { \
        echo "display_errors=Off"; \
        echo "log_errors=On"; \
        echo "memory_limit=512M"; \
    } > /usr/local/etc/php/conf.d/lrtc.ini

WORKDIR /app
COPY --from=vendor /app/vendor /app/vendor
COPY composer.json composer.lock presets.json ./
COPY public ./public
COPY src ./src
COPY fonts ./fonts
COPY templates ./templates
RUN echo "expose_php=Off" >> /usr/local/etc/php/conf.d/lrtc.ini

EXPOSE 8080
USER www-data
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
