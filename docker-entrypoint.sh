#!/bin/sh
set -eu

: "${PORT:=3000}"
: "${BIND_ADDRESS:=0.0.0.0}"

echo 'ServerName localhost' >/etc/apache2/conf-available/servername.conf
a2enconf servername

printf 'Listen %s:%s\n' "$BIND_ADDRESS" "$PORT" >/etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost ${BIND_ADDRESS}:${PORT}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p /var/www/html/data/gallery /var/www/html/data/locks
chown -R www-data:www-data /var/www/html/data

php init-db.php
exec apache2-foreground
