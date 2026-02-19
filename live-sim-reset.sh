#!/bin/bash
# Prepare an isolated "live deployment" app tree outside the workspace.
# Usage: ./live-sim-reset.sh [--target <live-root>] [--package <release-zip>]

set -euo pipefail

cd "$(dirname "$0")"

LIVE_ROOT="${KMP_LIVE_ROOT:-/tmp/kmp-live-sim}"
LIVE_OWNER="${KMP_LIVE_OWNER:-www-data:www-data}"
PACKAGE_PATH="${KMP_LIVE_PACKAGE:-}"
TARGET_WAS_SET=0

usage() {
    echo "Usage: ./live-sim-reset.sh [--target <live-root>] [--package <release-zip>]"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --target|-t)
            if [ $# -lt 2 ]; then
                echo "❌ Missing value for $1"
                usage
                exit 1
            fi
            LIVE_ROOT="$2"
            TARGET_WAS_SET=1
            shift 2
            ;;
        --package|-p)
            if [ $# -lt 2 ]; then
                echo "❌ Missing value for $1"
                usage
                exit 1
            fi
            PACKAGE_PATH="$2"
            shift 2
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            if [ -z "$PACKAGE_PATH" ]; then
                PACKAGE_PATH="$1"
                shift
            else
                echo "❌ Unknown argument: $1"
                usage
                exit 1
            fi
            ;;
    esac
done

if [ "$TARGET_WAS_SET" -eq 1 ] || [ -z "${KMP_LIVE_APP_PATH:-}" ]; then
    export KMP_LIVE_APP_PATH="$LIVE_ROOT/current/app"
fi
export KMP_LIVE_ROOT="$LIVE_ROOT"

LIVE_APP_PATH="$KMP_LIVE_APP_PATH"
EXTRACT_DIR="$LIVE_ROOT/.extract"

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
echo "Next: ./live-sim-up.sh --target \"$LIVE_ROOT\""
