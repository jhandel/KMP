#!/usr/bin/env bash
# Run install-first UI smoke tests:
# 1) reset to blank DB/installer state
# 2) complete installer flow
# 3) run fresh-install smoke checks
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${ROOT_DIR}/app"

echo "[install-first] Resetting installer + blank DB..."
"${ROOT_DIR}/dev-reset-installer.sh" --no-archive

echo "[install-first] Running installer flow..."
node "${ROOT_DIR}/install_test.js"

echo "[install-first] Configuring post-install test data..."
(
  cd "${APP_DIR}"
  php bin/cake.php configure_install_test_data
)

echo "[install-first] Generating Playwright-BDD specs..."
(
  cd "${APP_DIR}"
  npx bddgen
)

echo "[install-first] Running fresh-install smoke suite..."
(
  cd "${APP_DIR}"
  npx playwright test \
    --config playwright.install-smoke.config.js \
    "$@"

  npx playwright test \
    tests/ui/gen/@auth/UserLogin.feature.spec.js \
    "$@"

  npx playwright test \
    tests/ui/gen/@activities/@mode:serial/RequestAndReceiveAuth.feature.spec.js \
    --grep "Request authorization for an activity" \
    "$@"
)
