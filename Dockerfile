FROM php:8.4-apache AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libonig-dev libsqlite3-dev libxml2-dev unzip git \
    && docker-php-ext-install bcmath intl mbstring pdo_sqlite pdo_mysql pcntl dom \
    && a2enmod rewrite headers \
    && sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/pixelforge.conf \
    && a2enconf pixelforge && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
FROM node:22-alpine AS frontend
WORKDIR /build
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build
FROM runtime AS app
COPY . .
RUN touch .env && composer install --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/database storage/framework/cache storage/framework/sessions storage/framework/views \
    && chown -R www-data:www-data storage bootstrap/cache
COPY --from=frontend /build/public/build ./public/build
COPY docker/start /usr/local/bin/start
ENTRYPOINT ["start"]
CMD ["apache2-foreground"]
