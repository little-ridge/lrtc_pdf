FROM php:8.4-cli
RUN docker-php-ext-install mbstring
RUN apt-get update && apt-get install -y libfreetype6-dev
RUN composer require mpdf/mpdf
COPY . /app
WORKDIR /app
CMD ["php", "service.php"]

