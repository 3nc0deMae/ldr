# LDB-FRAS - Railway deployment image
# PHP 8.3 + Apache with pdo_mysql enabled and .htaccess support.

FROM php:8.3-apache

# PHP extensions needed by the app + Apache modules used by .htaccess
# (mbstring for mb_* in security/search, zip for Excel import readers)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libzip-dev \
    && docker-php-ext-install pdo_mysql mysqli mbstring zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Let the app's .htaccess rules (security / rewrites) take effect
RUN sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Composer dependencies (vendor is excluded from the app COPY via .dockerignore)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist

# Application code (tracks the repo; excludes heavy/non-runtime dirs)
COPY . /var/www/html/

# Drop the stock Apache welcome page (index.php in repo takes precedence)
RUN rm -f /var/www/html/index.html

# Listen on $PORT (Railway injects PORT at runtime; default 3000)
RUN cat > /usr/local/bin/port-entrypoint.sh <<'EOF'
#!/usr/bin/env bash
set -e
PORT="${PORT:-3000}"
sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/" /etc/apache2/sites-available/000-default.conf
exec /usr/local/bin/apache2-foreground
EOF
RUN chmod +x /usr/local/bin/port-entrypoint.sh

ENV PORT=3000
EXPOSE 3000
CMD ["/usr/local/bin/port-entrypoint.sh"]
