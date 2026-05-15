#!/bin/bash
set -e

echo "=== KMP Application Container Starting ==="

APP_DIR="/var/www/html"

hash_file() {
    sha256sum "$1" | awk '{print $1}'
}

# Wait for database to be ready (belt and suspenders - compose healthcheck should handle this)
echo "Checking database connection..."
max_attempts=30
attempt=0
until mysql -h"$MYSQL_HOST" -u"$MYSQL_USERNAME" -p"$MYSQL_PASSWORD" -e "SELECT 1" &>/dev/null; do
    attempt=$((attempt + 1))
    if [ $attempt -ge $max_attempts ]; then
        echo "ERROR: Could not connect to database after $max_attempts attempts"
        exit 1
    fi
    echo "Waiting for database... (attempt $attempt/$max_attempts)"
    sleep 2
done
echo "Database connection established!"

# Create test database if it doesn't exist
echo "Ensuring test database exists..."
mysql -h"$MYSQL_HOST" -u"$MYSQL_USERNAME" -p"$MYSQL_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS ${MYSQL_DB_NAME}_test COLLATE utf8_unicode_ci;" 2>/dev/null || true

# Skip .env file generation - APP_NAME is set which tells CakePHP to use container env vars directly
# This avoids the "Key already defined" error from josegonzalez/dotenv
echo "Using container environment variables (APP_NAME=$APP_NAME)"

# Always copy Docker-specific app_local.php (uses correct service hostnames)
echo "Copying Docker app_local.php..."
cp /opt/docker/app_local.php "$APP_DIR/config/app_local.php"

# Install Composer dependencies into the Docker vendor volume when missing or stale.
COMPOSER_STAMP="$APP_DIR/vendor/.composer.lock.sha"
COMPOSER_LOCK="$APP_DIR/composer.lock"
if [ -f "$COMPOSER_LOCK" ]; then
    COMPOSER_LOCK_SHA="$(hash_file "$COMPOSER_LOCK")"
else
    COMPOSER_LOCK_SHA="no-lock"
fi
if [ ! -d "$APP_DIR/vendor" ] \
    || [ ! -f "$APP_DIR/vendor/autoload.php" ] \
    || [ ! -f "$COMPOSER_STAMP" ] \
    || [ "$(cat "$COMPOSER_STAMP" 2>/dev/null)" != "$COMPOSER_LOCK_SHA" ]; then
    echo "Installing Composer dependencies..."
    cd "$APP_DIR"
    mkdir -p vendor
    composer install --no-interaction --prefer-dist
    echo "$COMPOSER_LOCK_SHA" > "$COMPOSER_STAMP"
fi

# Install Node dependencies into the Docker node_modules volume when missing or stale.
NPM_STAMP="$APP_DIR/node_modules/.package-lock.sha"
PACKAGE_LOCK="$APP_DIR/package-lock.json"
if [ -f "$PACKAGE_LOCK" ]; then
    PACKAGE_LOCK_SHA="$(hash_file "$PACKAGE_LOCK")"
else
    PACKAGE_LOCK_SHA="no-lock"
fi
if [ ! -d "$APP_DIR/node_modules" ] \
    || [ ! -x "$APP_DIR/node_modules/.bin/vite" ] \
    || [ ! -f "$NPM_STAMP" ] \
    || [ "$(cat "$NPM_STAMP" 2>/dev/null)" != "$PACKAGE_LOCK_SHA" ]; then
    echo "Installing Node.js dependencies..."
    cd "$APP_DIR"
    mkdir -p node_modules
    if [ -f "$PACKAGE_LOCK" ]; then
        npm ci --no-audit
    else
        npm install --no-audit
    fi
    echo "$PACKAGE_LOCK_SHA" > "$NPM_STAMP"
fi

if [ -x "$APP_DIR/node_modules/.bin/playwright" ]; then
    echo "Ensuring Playwright Chromium browser is installed..."
    cd "$APP_DIR"
    npx playwright install chromium
fi

# Ensure proper directory structure and permissions
echo "Setting up directories and permissions..."
mkdir -p "$APP_DIR/logs" \
         "$APP_DIR/tmp/cache/models" \
         "$APP_DIR/tmp/cache/persistent" \
         "$APP_DIR/tmp/cache/views" \
         "$APP_DIR/tmp/sessions" \
         "$APP_DIR/tmp/tests" \
         "$APP_DIR/images/uploaded" \
         "$APP_DIR/images/cache"

chown -R www-data:www-data "$APP_DIR/logs" "$APP_DIR/tmp" "$APP_DIR/images" 2>/dev/null || true
chmod -R 775 "$APP_DIR/logs" "$APP_DIR/tmp" "$APP_DIR/images" 2>/dev/null || true

# Run migrations if database is empty (first-time setup)
TABLES=$(mysql -h"$MYSQL_HOST" -u"$MYSQL_USERNAME" -p"$MYSQL_PASSWORD" "$MYSQL_DB_NAME" -N -e "SHOW TABLES;" 2>/dev/null | wc -l)
if [ "$TABLES" -eq 0 ]; then
    echo "Empty database detected - running initial setup..."
    cd "$APP_DIR"
    if [ -f bin/cake ]; then
        # Check if resetDatabase command exists
        if bin/cake help resetDatabase &>/dev/null; then
            echo "Running resetDatabase..."
            bin/cake resetDatabase || true
        fi
        echo "Running migrations..."
        bin/cake migrations migrate || true
        echo "Running updateDatabase..."
        bin/cake updateDatabase || true
    fi
fi

# Setup cron for queue processing (runs every 2 minutes)
echo "Configuring cron job..."
CRON_JOB="*/2 * * * * cd $APP_DIR && bin/cake queue run --all-tenants --exit-when-empty --max-runtime 45 -q >> /var/log/cron.log 2>&1"
(crontab -l 2>/dev/null | grep -v "queue run" ; echo "$CRON_JOB") | crontab -

# Start cron in background
service cron start

echo "=== KMP Application Ready ==="
echo "  App:     http://localhost:8080"
echo "  Mailpit: http://localhost:8025"
echo "  MySQL:   localhost:3306"
echo ""

# Execute the main command (apache2-foreground)
exec "$@"
