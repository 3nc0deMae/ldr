#!/bin/bash
# ============================================================
# LDB-FRAS Server Setup Script
#
# Run on a fresh Ubuntu/Debian server
# Usage: sudo bash deploy/setup.sh
# ============================================================

set -e

echo "============================================"
echo " LDB-FRAS Server Setup"
echo " Initial Server Configuration"
echo "============================================"
echo ""

# Check root
if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Please run as root (sudo bash deploy/setup.sh)"
    exit 1
fi

# Step 1: System update
echo "[1/7] Updating system packages..."
apt-get update -qq
apt-get upgrade -y -qq
echo "  Done."

# Step 2: Install Apache
echo "[2/7] Installing Apache web server..."
apt-get install -y -qq apache2
systemctl enable apache2
systemctl start apache2
echo "  Done."

# Step 3: Install PHP 8.x
echo "[3/7] Installing PHP 8.x and extensions..."
apt-get install -y -qq software-properties-common
add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
apt-get update -qq
apt-get install -y -qq \
    php8.2 \
    php8.2-cli \
    php8.2-common \
    php8.2-mysql \
    php8.2-curl \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-zip \
    php8.2-gd \
    php8.2-intl \
    libapache2-mod-php8.2
echo "  Done."

# Step 4: Install MySQL
echo "[4/7] Installing MySQL server..."
apt-get install -y -qq mysql-server
systemctl enable mysql
systemctl start mysql
echo "  Done."
echo ""
echo "  IMPORTANT: Run 'mysql_secure_installation' to secure MySQL."
echo ""

# Step 5: Install Python 3 and face recognition dependencies
echo "[5/7] Installing Python 3 and face recognition dependencies..."
apt-get install -y -qq \
    python3 \
    python3-pip \
    python3-venv \
    build-essential \
    cmake \
    libopenblas-dev \
    liblapack-dev \
    libx11-dev \
    libgtk-3-dev
echo "  Done."

# Step 6: Enable Apache modules
echo "[6/7] Enabling Apache modules..."
a2enmod rewrite
a2enmod headers
a2enmod expires
a2enmod ssl
a2enmod deflate
systemctl restart apache2
echo "  Done."

# Step 7: Create application directories
echo "[7/7] Creating application directories..."
mkdir -p /var/www/ldb-fras/uploads/faces
mkdir -p /var/log/php
chown -R www-data:www-data /var/www/ldb-fras
chown -R www-data:www-data /var/log/php
echo "  Done."

# Firewall configuration
echo ""
echo "Configuring firewall..."
ufw allow 'Apache Full' 2>/dev/null || true
ufw allow 5000/tcp 2>/dev/null || true  # Python face API
ufw --force enable 2>/dev/null || true

echo ""
echo "============================================"
echo " Server Setup Complete!"
echo "============================================"
echo ""
echo " Installed Components:"
echo "   Apache: $(apache2 -v 2>/dev/null | head -1)"
echo "   PHP:    $(php -v 2>/dev/null | head -1)"
echo "   MySQL:  $(mysql --version 2>/dev/null)"
echo "   Python: $(python3 --version 2>/dev/null)"
echo ""
echo " Next Steps:"
echo "   1. Run: sudo mysql_secure_installation"
echo "   2. Copy project files to /var/www/ldb-fras/"
echo "   3. Run: sudo bash deploy/deploy.sh"
echo "   4. Install SSL: sudo apt install certbot python3-certbot-apache"
echo "      Then: sudo certbot --apache -d your-domain.com"
echo ""
