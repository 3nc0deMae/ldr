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

# Ensure exactly ONE Apache MPM is enabled at build time. The official
# php:*-apache image ships with mpm_prefork (required by mod_php); force it
# here defensively so no duplicate MPM survives into the image.
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true; a2enmod mpm_prefork

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

# Railway injects an extra Apache MPM at container start, which makes Apache
# abort with "AH00534: Configuration error: More than one MPM loaded."
# Disable every MPM, then re-enable only mpm_prefork (required by mod_php)
# immediately before starting Apache. This runs at container start so it
# overrides whatever Railway's runtime does.
a2dismod mpm_event  2>/dev/null || true
a2dismod mpm_worker 2>/dev/null || true
a2dismod mpm_prefork 2>/dev/null || true
a2enmod mpm_prefork

# Silence the harmless "AH00558: Could not reliably determine the server's
# fully qualified domain name" warning with a global ServerName.
echo "ServerName localhost" > /etc/apache2/conf-available/server-name.conf
a2enconf server-name 2>/dev/null || true

# Listen on the Railway-injected $PORT (what Railway's proxy probes) AND on
# port 80 (fallback for setups that expect the default Apache port). Replace
# ports.conf entirely so the Listen lines are deterministic (a plain sed on
# "Listen 80" silently does nothing if the line format differs).
printf 'Listen %s\nListen 80\n' "$PORT" > /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Fail fast with a visible config error instead of booting on the wrong port.
apache2ctl -t
echo "== Apache configured. Listening on: $(grep '^Listen' /etc/apache2/ports.conf | tr '\n' ' ') =="
exec /usr/local/bin/apache2-foreground
EOF
RUN chmod +x /usr/local/bin/port-entrypoint.sh

ENV PORT=3000
# Railway health-checks the port declared by EXPOSE (it uses the last EXPOSE
# line). The entrypoint listens on $PORT AND port 80, so we must declare an
# EXPOSE port that Apache actually binds to, otherwise Railway marks the
# container unhealthy and stops it ~5s after start.
EXPOSE 80
CMD ["/usr/local/bin/port-entrypoint.sh"]
