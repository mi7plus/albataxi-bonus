FROM php:8.2-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libpng-dev libonig-dev libxml2-dev unzip \
 && docker-php-ext-install -j2 pdo_mysql mbstring gd zip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress
COPY . .
RUN sed -i 's|/var/www/html|/var/www/app/public|g' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/app/public>\nRequire all granted\nAllowOverride None\n</Directory>\n' > /etc/apache2/conf-available/alba.conf \
 && a2enconf alba \
 && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nupload_max_filesize=2M\npost_max_size=2M\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/alba.ini
CMD ["apache2-foreground"]
