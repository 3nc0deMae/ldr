#!/bin/bash
# ============================================================
# LDB-FRAS Deployment Script
#
# Usage: sudo bash deploy/deploy.sh
# ============================================================

set -e

APP_NAME="ldb-fras"
APP_DIR="/var/www/$APP_NAME"
DB_NAME="ldb_fras"
DB_USER="ldb_fras_user"
PYTHON_DIR="$APP_DIR/python"

echo "============================================"
echo " LDB-FRAS Deployment Script"
echo " Liceo de Baleno Facial Recognition System"
echo "============================================"
echo ""

# Check root
if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Please run as root (sudo bash deploy/deploy.sh)"
    exit 1
fi

# Step 1: Copy application files
echo "[1/8] Copying application files..."
mkdir -p $APP_DIR
cp -r ../admin $APP_DIR/
cp -r ../api $APP_DIR/
cp -r ../assets $APP_DIR/
cp -r ../gate $APP_DIR/
cp -r ../includes $APP_DIR/
cp -r ../python $APP_DIR/
cp -r ../teacher $APP_DIR/
cp -r ../uploads $APP_DIR/
cp ../config.php $APP_DIR/
cp ../database.sql $APP_DIR/
cp ../index.php $APP_DIR/
cp ../login.php $APP_DIR/
cp ../logout.php $APP_DIR/
cp ../register.php $APP_DIR/
cp ../forgot-password.php $APP_DIR/
cp ../unauthorized.php $APP_DIR/
echo "  Done."

# Step 2: Set production .htaccess
echo "[2/8] Installing production .htaccess..."
cp deploy/.htaccess.production $APP_DIR/.htaccess
echo "  Done."

# Step 3: Set file permissions
echo "[3/8] Setting file permissions..."
chown -R www-data:www-data $APP_DIR
find $APP_DIR -type d -exec chmod 755 {} \;
find $APP_DIR -type f -exec chmod 644 {} \;
chmod -R 775 $APP_DIR/uploads
chmod 600 $APP_DIR/config.php
echo "  Done."

# Step 4: Install Python dependencies
echo "[4/8] Installing Python dependencies..."
cd $PYTHON_DIR
pip3 install -r requirements.txt --quiet 2>/dev/null || echo "  Warning: pip install failed. Install manually."
cd -
echo "  Done."

# Step 5: Install systemd service
echo "[5/8] Installing Python API service..."
cp deploy/face-recognition.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable face-recognition.service
systemctl restart face-recognition.service
echo "  Done."

# Step 6: Install Apache virtual host
echo "[6/8] Configuring Apache virtual host..."
cp deploy/ldb-fras.conf /etc/apache2/sites-available/
a2ensite $APP_NAME.conf
a2enmod rewrite headers expires
systemctl reload apache2
echo "  Done."

# Step 7: Database setup reminder
echo "[7/8] Database setup..."
echo "  IMPORTANT: Import the database manually:"
echo "    mysql -u root -p < $APP_DIR/database.sql"
echo ""
echo "  Or create user and grant privileges:"
echo "    CREATE DATABASE $DB_NAME;"
echo "    CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';"
echo "    GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
echo "    FLUSH PRIVILEGES;"
echo "    USE $DB_NAME; SOURCE $APP_DIR/database.sql;"
echo ""
echo "  Then update config.php with production credentials:"
echo "    Edit $APP_DIR/includes/db.php"
echo "  Done."

# Step 8: Final checks
echo "[8/8] Running final checks..."
echo "  Apache status: $(systemctl is-active apache2)"
echo "  Python API:    $(systemctl is-active face-recognition.service)"
echo "  App directory: $APP_DIR"
echo "  Done."

echo ""
echo "============================================"
echo " Deployment Complete!"
echo "============================================"
echo ""
echo " Access the application at: http://$(hostname -I | awk '{print $1}')"
echo " Default admin login:"
echo "   Email:    admin@liceodebaleno.edu.ph"
echo "   Password: Admin@2026"
echo ""
echo " Next steps:"
echo "   1. Import the database (see step 7 above)"
echo "   2. Update database credentials in includes/db.php"
echo "   3. Configure SMTP and SMS in Admin > Settings"
echo "   4. Install SSL certificate and enable HTTPS"
echo "   5. Change the default admin password"
echo "   6. Remove tests/ and docs/ from production"
echo ""
