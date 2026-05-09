FROM php:8.3-cli-alpine

WORKDIR /app

RUN docker-php-ext-install pdo_mysql

COPY . /app

RUN mkdir -p /app/data/gallery /app/data/locks

ENV PORT=3000

EXPOSE 3000

CMD ["php", "-S", "0.0.0.0:3000", "router.php"]
