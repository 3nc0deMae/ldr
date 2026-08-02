# LDB-FRAS - Railway deployment image
# PHP 8.3 + Apache with pdo_mysql enabled and .htaccess support.

FROM php:8.3-apache

# PHP extensions needed by the app + Apache modules used by .htaccess
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libzip-dev \
    && docker-php-ext-install pdo_mysql mysqli mbstring zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
    
RUN docker-php-ext-install pdo_mysql mysqli mbstring zip
# Clean MPM state at build time
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load && a2enmod mpm_prefork

# Let the app's .htaccess rules take effect
RUN sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Composer dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist

# Application code
COPY . /var/www/html/

# Drop the stock Apache welcome page
RUN rm -f /var/www/html/index.html

# Listen on $PORT entrypoint script
RUN cat > /usr/local/bin/port-entrypoint.sh <<'EOF'
#!/usr/bin/env bash
set -e
PORT="${PORT:-3000}"

# Purge ALL MPM modules from mods-enabled before starting
rm -f /etc/apache2/mods-enabled/mpm_*.load
rm -f /etc/apache2/mods-enabled/mpm_*.conf
a2enmod mpm_prefork

# Silence ServerName warning
echo "ServerName localhost" > /etc/apache2/conf-available/server-name.conf
a2enconf server-name 2>/dev/null || true

# Configure listening ports
printf 'Listen %s\nListen 80\n' "$PORT" > /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Test configuration and start
apache2ctl -t
echo "== Apache configured. Listening on: $(grep '^Listen' /etc/apache2/ports.conf | tr '\n' ' ') =="
exec /usr/local/bin/apache2-foreground
EOF

RUN chmod +x /usr/local/bin/port-entrypoint.sh

ENV PORT=3000
EXPOSE 80
CMD ["/usr/local/bin/port-entrypoint.sh"]
