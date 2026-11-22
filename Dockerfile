# Isolated PHP runtime for hanamaru; host Apache and other PHP sites remain unchanged.
FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo_sqlite sqlite3 mbstring curl \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers setenvif \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' 'SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on' > /etc/apache2/conf-available/hanamaru-proxy-https.conf \
    && a2enconf hanamaru-proxy-https

WORKDIR /var/www/html
