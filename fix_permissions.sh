#!/bin/bash
# Script to fix permissions for Apache web server access
# Run this script if logs, tmp, images, or webroot/img directories have permission issues

REPO_PATH="${REPO_PATH:-/workspaces/KMP}"
DEV_OWNER="${DEV_OWNER:-$(id -un)}"
WEB_GROUP="${WEB_GROUP:-www-data}"

if ! id -u "$DEV_OWNER" >/dev/null 2>&1; then
    DEV_OWNER="$(id -un)"
fi
if ! getent group "$WEB_GROUP" >/dev/null 2>&1; then
    WEB_GROUP="$(id -gn)"
fi

echo "Fixing permissions for app runtime + dev editing (${DEV_OWNER}:${WEB_GROUP})..."

# Fix logs directory
echo "  - Setting up logs directory..."
sudo mkdir -p "$REPO_PATH/app/logs"
sudo chmod -R 775 "$REPO_PATH/app/logs"
sudo chown -R "$DEV_OWNER:$WEB_GROUP" "$REPO_PATH/app/logs"

# Fix tmp directory and all subdirectories
echo "  - Setting up tmp directory..."
sudo mkdir -p "$REPO_PATH/app/tmp"
sudo mkdir -p "$REPO_PATH/app/tmp/cache"
sudo mkdir -p "$REPO_PATH/app/tmp/cache/models"
sudo mkdir -p "$REPO_PATH/app/tmp/cache/persistent"
sudo mkdir -p "$REPO_PATH/app/tmp/cache/views"
sudo mkdir -p "$REPO_PATH/app/tmp/sessions"
sudo mkdir -p "$REPO_PATH/app/tmp/tests"
sudo chmod -R 775 "$REPO_PATH/app/tmp"
sudo chown -R "$DEV_OWNER:$WEB_GROUP" "$REPO_PATH/app/tmp"

# Fix images directory
echo "  - Setting up images directory..."
sudo mkdir -p "$REPO_PATH/app/images/uploaded"
sudo mkdir -p "$REPO_PATH/app/images/cache"
sudo chmod -R 775 "$REPO_PATH/app/images"
sudo chown -R "$DEV_OWNER:$WEB_GROUP" "$REPO_PATH/app/images"

# Fix webroot/img directory (installer uploads and logo assets)
echo "  - Setting up webroot/img directory..."
sudo mkdir -p "$REPO_PATH/app/webroot/img/custom"
sudo chmod -R 775 "$REPO_PATH/app/webroot/img"
sudo chown -R "$DEV_OWNER:$WEB_GROUP" "$REPO_PATH/app/webroot/img"

# Fix config/.env writability (installer must write env vars during setup)
echo "  - Setting config/.env permissions..."
if [ -f "$REPO_PATH/app/config/.env" ]; then
    sudo chmod 664 "$REPO_PATH/app/config/.env"
    sudo chown "$DEV_OWNER:$WEB_GROUP" "$REPO_PATH/app/config/.env"
fi
sudo chmod 775 "$REPO_PATH/app/config"
sudo chown "$DEV_OWNER:$WEB_GROUP" "$REPO_PATH/app/config"

# Restart Apache to ensure changes take effect
echo "  - Restarting Apache..."
sudo apachectl restart

echo ""
echo "✓ Permissions fixed successfully!"
echo ""
echo "Directory ownership and permissions:"
ls -ld "$REPO_PATH/app/logs" "$REPO_PATH/app/tmp" "$REPO_PATH/app/images" "$REPO_PATH/app/webroot/img" "$REPO_PATH/app/config"
