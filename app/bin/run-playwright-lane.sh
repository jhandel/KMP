#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$APP_DIR"

if [ "$#" -gt 0 ]; then
    lane="$1"
    shift
else
    lane="uat"
fi

case "$lane" in
    smoke)
        specs=(
            "tests/ui/gen/@auth/UserLogin.feature.spec.js"
            "tests/ui/gen/@workflows/workflow-admin.feature.spec.js"
        )
        ;;
    uat|full)
        specs=()
        ;;
    *)
        echo "Usage: bash bin/run-playwright-lane.sh [smoke|uat] [playwright args...]" >&2
        exit 1
        ;;
esac

npx bddgen test

if [[ "${PLAYWRIGHT_RESET_DB:-1}" != "0" ]]; then
    bash ../reset_dev_database.sh
fi

if [ "$lane" = "smoke" ]; then
    npx playwright test "${specs[@]}" "$@"
else
    npx playwright test "$@"
fi
