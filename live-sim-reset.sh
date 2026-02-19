#!/bin/bash
# Prepare an isolated "live deployment" app tree outside the workspace.
# Usage: ./live-sim-reset.sh [path-to-release-zip]

set -euo pipefail

cd "$(dirname "$0")"

LIVE_ROOT="${KMP_LIVE_ROOT:-/tmp/kmp-live-sim}"
LIVE_APP_PATH="${KMP_LIVE_APP_PATH:-$LIVE_ROOT/current/app}"
LIVE_OWNER="${KMP_LIVE_OWNER:-www-data:www-data}"
EXTRACT_DIR="$LIVE_ROOT/.extract"

PACKAGE_PATH="${1:-}"
if [ -z "$PACKAGE_PATH" ]; then
    PACKAGE_PATH="$(ls -1t dist/kmp-full-v*.zip 2>/dev/null | head -n 1 || true)"
fi

if [ -z "$PACKAGE_PATH" ] || [ ! -f "$PACKAGE_PATH" ]; then
    echo "❌ Release zip not found. Provide one or build dist/kmp-full-v*.zip first."
    exit 1
fi

echo "📦 Preparing live simulation app tree..."
echo "   Package: $PACKAGE_PATH"
echo "   Target:  $LIVE_APP_PATH"

rm -rf "$EXTRACT_DIR"
mkdir -p "$EXTRACT_DIR" "$LIVE_ROOT/current"
unzip -q "$PACKAGE_PATH" -d "$EXTRACT_DIR"

SOURCE_APP_DIR=""
if [ -d "$EXTRACT_DIR/KMP/app" ]; then
    SOURCE_APP_DIR="$EXTRACT_DIR/KMP/app"
elif [ -d "$EXTRACT_DIR/app" ]; then
    SOURCE_APP_DIR="$EXTRACT_DIR/app"
fi

if [ -z "$SOURCE_APP_DIR" ]; then
    echo "❌ Could not locate app/ directory in release package."
    rm -rf "$EXTRACT_DIR"
    exit 1
fi

rm -rf "$LIVE_APP_PATH"
mkdir -p "$(dirname "$LIVE_APP_PATH")"
cp -a "$SOURCE_APP_DIR" "$LIVE_APP_PATH"

mkdir -p \
    "$LIVE_APP_PATH/tmp" \
    "$LIVE_APP_PATH/logs" \
    "$LIVE_APP_PATH/images/uploaded" \
    "$LIVE_APP_PATH/webroot/img/custom"

if command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then
    echo "🔐 Setting ownership to $LIVE_OWNER (sudo)..."
    sudo chown -R "$LIVE_OWNER" "$LIVE_APP_PATH"
else
    echo "⚠️  sudo unavailable/non-interactive; ownership unchanged."
fi

rm -rf "$EXTRACT_DIR"

echo ""
echo "✅ Live simulation app tree is ready."
echo "Next: ./live-sim-up.sh"
