#!/bin/bash
# Stop isolated "live deployment" simulation.
# Usage: ./live-sim-down.sh [--volumes]

set -euo pipefail

cd "$(dirname "$0")"

PROJECT_NAME="${KMP_LIVE_PROJECT:-kmp-live-sim}"

if [ "${1:-}" = "--volumes" ]; then
    echo "🛑 Stopping live simulation and removing volumes..."
    docker compose -f docker-compose.live-sim.yml -p "$PROJECT_NAME" down -v
else
    echo "🛑 Stopping live simulation..."
    docker compose -f docker-compose.live-sim.yml -p "$PROJECT_NAME" down
fi

echo "✅ Done."
