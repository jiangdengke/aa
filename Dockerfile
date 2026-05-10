FROM php:8.3-apache

WORKDIR /var/www/html

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

COPY . /var/www/html

RUN mkdir -p /var/www/html/data/gallery /var/www/html/data/locks \
    && chown -R www-data:www-data /var/www/html/data

ENV PORT=3000

EXPOSE 3000

CMD ["sh", "-lc", "echo 'ServerName localhost' >/etc/apache2/conf-available/servername.conf && a2enconf servername && sed -i 's/Listen 80/Listen 3000/' /etc/apache2/ports.conf && sed -i 's/<VirtualHost \\*:80>/<VirtualHost *:3000>/' /etc/apache2/sites-available/000-default.conf && mkdir -p /var/www/html/data/gallery /var/www/html/data/locks && chown -R www-data:www-data /var/www/html/data && php init-db.php && apache2-foreground"]
