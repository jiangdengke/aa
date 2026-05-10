FROM php:8.3-apache

WORKDIR /var/www/html

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

COPY . /var/www/html

RUN mkdir -p /var/www/html/data/gallery /var/www/html/data/locks \
    && chown -R www-data:www-data /var/www/html/data

ENV PORT=3000
ENV BIND_ADDRESS=0.0.0.0

EXPOSE 3000

CMD ["sh", "/var/www/html/docker-entrypoint.sh"]
