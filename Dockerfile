# LDB-FRAS - Render deployment image
# PHP 8.3 + Apache with pdo_mysql enabled and .htaccess support.

FROM php:8.3-apache

# PHP extensions needed by the app + Apache modules used by .htaccess
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mysqli mbstring zip gd \
    && a2enmod rewrite headers access_compat \
    && rm -rf /var/lib/apt/lists/*

# Clean MPM state at build time
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load && a2enmod mpm_prefork

# Let the app's .htaccess rules take effect
RUN sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# PHP settings (belt-and-braces alongside .htaccess php_value directives)
RUN printf 'upload_max_filesize = 10M\npost_max_size = 12M\nmax_execution_time = 300\nmax_input_time = 300\nmemory_limit = 256M\ndate.timezone = Asia/Manila\nexpose_php = Off\n' \
    > /usr/local/etc/php/conf.d/ldb-fras.ini

# Keep Apache prefork within a small instance's RAM budget (512MB-1GB)
RUN printf '<IfModule mpm_prefork_module>\n    StartServers 2\n    MinSpareServers 1\n    MaxSpareServers 3\n    MaxRequestWorkers 25\n    MaxConnectionsPerChild 500\n</IfModule>\n' \
    > /etc/apache2/conf-available/ldb-mpm.conf \
    && a2enconf ldb-mpm

# Composer dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist

# Application code
COPY . /var/www/html/

# TiDB Serverless TLS root CA (ISRG Root X1) - referenced by DB_SSL_CA
RUN mkdir -p /etc/ldb-fras
COPY certs/isrg-root-x1.pem /etc/ldb-fras/tidb-ca.pem

# The app writes to uploads/ (avatars, faces, logs) as www-data at runtime
RUN chown -R www-data:www-data /var/www/html/uploads

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
