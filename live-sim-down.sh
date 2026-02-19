#!/bin/bash
# Stop isolated "live deployment" simulation.
# Usage: ./live-sim-down.sh [--volumes] [--target <live-root>]

set -euo pipefail

cd "$(dirname "$0")"

PROJECT_NAME="${KMP_LIVE_PROJECT:-kmp-live-sim}"
LIVE_ROOT="${KMP_LIVE_ROOT:-/tmp/kmp-live-sim}"
REMOVE_VOLUMES=0

usage() {
    echo "Usage: ./live-sim-down.sh [--volumes] [--target <live-root>]"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --volumes|-v)
            REMOVE_VOLUMES=1
            shift
            ;;
        --target|-t)
            if [ $# -lt 2 ]; then
                echo "❌ Missing value for $1"
                usage
                exit 1
            fi
            LIVE_ROOT="$2"
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

export KMP_LIVE_ROOT="$LIVE_ROOT"
export KMP_LIVE_APP_PATH="${KMP_LIVE_APP_PATH:-$LIVE_ROOT/current/app}"

if [ "$REMOVE_VOLUMES" -eq 1 ]; then
    echo "🛑 Stopping live simulation and removing volumes..."
    docker compose -f docker-compose.live-sim.yml -p "$PROJECT_NAME" down -v
else
    echo "🛑 Stopping live simulation..."
    docker compose -f docker-compose.live-sim.yml -p "$PROJECT_NAME" down
fi

echo "✅ Done."
