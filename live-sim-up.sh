#!/bin/bash
# Start isolated "live deployment" simulation on port 8081 by default.
# Usage: ./live-sim-up.sh [--build] [--target <live-root>] [--package <release-zip>]

set -euo pipefail

cd "$(dirname "$0")"

PROJECT_NAME="${KMP_LIVE_PROJECT:-kmp-live-sim}"
LIVE_ROOT="${KMP_LIVE_ROOT:-/tmp/kmp-live-sim}"
HTTP_PORT="${KMP_LIVE_HTTP_PORT:-8081}"
PACKAGE_PATH="${KMP_LIVE_PACKAGE:-}"
TARGET_WAS_SET=0

BUILD_FLAG=""

usage() {
    echo "Usage: ./live-sim-up.sh [--build] [--target <live-root>] [--package <release-zip>]"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --build)
            BUILD_FLAG="--build"
            shift
            ;;
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
            echo "❌ Unknown argument: $1"
            usage
            exit 1
            ;;
    esac
done

if [ "$TARGET_WAS_SET" -eq 1 ] || [ -z "${KMP_LIVE_APP_PATH:-}" ]; then
    export KMP_LIVE_APP_PATH="$LIVE_ROOT/current/app"
fi
export KMP_LIVE_ROOT="$LIVE_ROOT"

if [ ! -f "$KMP_LIVE_APP_PATH/config/app.php" ]; then
    echo "ℹ️  No live simulation app tree found at $KMP_LIVE_APP_PATH"
    if [ -n "$PACKAGE_PATH" ]; then
        ./live-sim-reset.sh --target "$LIVE_ROOT" --package "$PACKAGE_PATH"
    else
        ./live-sim-reset.sh --target "$LIVE_ROOT"
    fi
fi

echo "🚀 Starting live deployment simulation ($PROJECT_NAME)..."
docker compose -f docker-compose.live-sim.yml -p "$PROJECT_NAME" up -d $BUILD_FLAG

echo "⏳ Waiting for app on port $HTTP_PORT..."
max_wait=120
waited=0
while ! curl -sf "http://localhost:$HTTP_PORT/" >/dev/null 2>&1; do
    if [ $waited -ge $max_wait ]; then
        echo "⚠️  App not responding after ${max_wait}s. Check logs:"
        echo "   docker compose -f docker-compose.live-sim.yml -p $PROJECT_NAME logs app"
        exit 1
    fi
    sleep 2
    waited=$((waited + 2))
done

echo ""
echo "✅ Live deployment simulation is running."
echo "   Application: http://localhost:$HTTP_PORT"
echo "   Installer:   http://localhost:$HTTP_PORT/install"
echo "   Mailpit:     http://localhost:${KMP_LIVE_MAILPIT_WEB_PORT:-8026}"
echo "   Live root:   $LIVE_ROOT"
echo ""
echo "Stop with: ./live-sim-down.sh --target \"$LIVE_ROOT\""
