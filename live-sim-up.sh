#!/bin/bash
# Start isolated "live deployment" simulation on port 8081 by default.
# Usage: ./live-sim-up.sh [--build]

set -euo pipefail

cd "$(dirname "$0")"

PROJECT_NAME="${KMP_LIVE_PROJECT:-kmp-live-sim}"
LIVE_ROOT="${KMP_LIVE_ROOT:-/tmp/kmp-live-sim}"
export KMP_LIVE_APP_PATH="${KMP_LIVE_APP_PATH:-$LIVE_ROOT/current/app}"
HTTP_PORT="${KMP_LIVE_HTTP_PORT:-8081}"

BUILD_FLAG=""
if [ "${1:-}" = "--build" ]; then
    BUILD_FLAG="--build"
fi

if [ ! -f "$KMP_LIVE_APP_PATH/config/app.php" ]; then
    echo "ℹ️  No live simulation app tree found at $KMP_LIVE_APP_PATH"
    ./live-sim-reset.sh "${KMP_LIVE_PACKAGE:-}"
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
echo ""
echo "Stop with: ./live-sim-down.sh"
