#!/usr/bin/env bash
# Run KMP development checks inside the Docker app container.

set -euo pipefail

cd "$(dirname "$0")"

usage() {
    cat <<'EOF'
Usage: ./dev-test.sh <check> [extra args...]

Checks:
  all       Run app/bin/verify.sh
  php       Run PHPUnit
  js        Run Jest
  build     Run Vite development build
  cs        Run PHPCS on changed PHP files
  stan      Run PHPStan
  default-conn  Run guardrail against unsafe ConnectionManager::get('default')
  ui-smoke  Reset DB, then run the Playwright smoke lane in the app container
  ui        Reset DB, then run the full Playwright UAT lane in the app container
  shell     Open a shell in the app container
  reset-db  Reset the Docker development database
EOF
}

ensure_running() {
    if ! docker compose ps --status running app | grep -q "kmp-app"; then
        echo "App container is not running. Start it with: ./dev-up.sh" >&2
        exit 1
    fi
}

exec_app() {
    ensure_running
    docker compose exec -T app "$@"
}

exec_app_ui() {
    ensure_running
    docker compose exec -T \
        -e PLAYWRIGHT_BASE_URL=http://127.0.0.1 \
        -e PLAYWRIGHT_MAILPIT_URL=http://mailpit:8025 \
        -e PLAYWRIGHT_WEB_SERVER_COMMAND=true \
        -e PLAYWRIGHT_RESET_DB=0 \
        app "$@"
}

check="${1:-}"
if [ -n "$check" ]; then
    shift
fi

case "$check" in
    all)
        exec_app bash bin/verify.sh "$@"
        ;;
    php)
        exec_app composer test "$@"
        ;;
    js)
        exec_app npm run test:js -- "$@"
        ;;
    build)
        exec_app npm run dev -- "$@"
        ;;
    cs)
        exec_app bash -lc '
            CHANGED_PHP=$(cd /var/www && git diff --name-only --diff-filter=ACMR HEAD -- "app/src/**/*.php" "app/plugins/**/*.php" "app/tests/**/*.php" 2>/dev/null | sed "s|^app/||")
            if [ -z "$CHANGED_PHP" ]; then
                echo "No changed PHP files to check"
                exit 0
            fi
            echo "Checking changed files: $CHANGED_PHP"
            cd /var/www/html
            echo "$CHANGED_PHP" | xargs vendor/bin/phpcs --colors
        ' "$@"
        ;;
    stan)
        exec_app bash -lc '
            set +e
            OUTPUT=$(vendor/bin/phpstan analyse --no-progress --memory-limit=1G "$@" 2>&1)
            EXIT_CODE=$?
            set -e
            echo "$OUTPUT"
            if [ "$EXIT_CODE" -eq 0 ] || echo "$OUTPUT" | grep -q "No rules detected"; then
                exit 0
            fi
            exit "$EXIT_CODE"
        ' bash "$@"
        ;;
    default-conn)
        exec_app php bin/check-default-connection-usage.php "$@"
        ;;
    ui-smoke)
        ./dev-reset-db.sh --seed
        exec_app_ui npm run test:ui:smoke -- "$@"
        ;;
    ui)
        ./dev-reset-db.sh --seed
        exec_app_ui npm run test:ui -- "$@"
        ;;
    shell)
        ensure_running
        docker compose exec app bash
        ;;
    reset-db)
        ./dev-reset-db.sh "$@"
        ;;
    -h|--help|help|"")
        usage
        ;;
    *)
        echo "Unknown check: $check" >&2
        usage >&2
        exit 1
        ;;
esac
