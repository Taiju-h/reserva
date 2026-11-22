# Isolated PHP runtime for hanamaru; host Apache and other PHP sites remain unchanged.
FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev libonig-dev \
    && if ! php -r 'exit(extension_loaded("mbstring") ? 0 : 1);'; then docker-php-ext-install mbstring; fi \
    && php -r 'foreach (["pdo_sqlite","sqlite3","mbstring","curl"] as $ext) { if (!extension_loaded($ext)) {fwrite(STDERR, "Missing PHP extension: $ext\\n"); exit(1);} }' \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers setenvif \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' 'SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on' > /etc/apache2/conf-available/hanamaru-proxy-https.conf \
    && a2enconf hanamaru-proxy-https

WORKDIR /var/www/html
