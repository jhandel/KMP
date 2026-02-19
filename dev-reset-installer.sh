#!/bin/bash
# Reset development environment for installer walkthrough testing.
# Usage: ./dev-reset-installer.sh [--no-archive]
#
# Default behavior:
#   1) Archive current dev config and database snapshot
#   2) Drop/recreate KMP_DEV and KMP_DEV_test as blank databases
#   3) Remove installer lock/state so /install is available again
#
# --no-archive:
#   Skip archiving and only reset databases + installer lock/state.
#
# Execution modes:
#   - docker mode: uses docker compose db/app services
#   - local mode: uses local mysql/mysqldump (devcontainer-friendly)

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${ROOT_DIR}/app"
cd "${ROOT_DIR}"

ARCHIVE_CURRENT=true
if [ "${1:-}" == "--no-archive" ]; then
    ARCHIVE_CURRENT=false
elif [ "${1:-}" == "--help" ]; then
    echo "Usage: ./dev-reset-installer.sh [--no-archive]"
    exit 0
fi

# Load DB env vars from app/config/.env when not already provided
ENV_FILE="${APP_DIR}/config/.env"
for v in MYSQL_USERNAME MYSQL_PASSWORD MYSQL_DB_NAME; do
    if [ -z "${!v-}" ] && [ -f "$ENV_FILE" ]; then
        # shellcheck disable=SC1090
        . "$ENV_FILE"
        break
    fi
done

RUN_MODE="local"
if command -v docker >/dev/null 2>&1; then
    if docker compose ps >/dev/null 2>&1 && docker compose ps --status running | grep -q "kmp-db"; then
        RUN_MODE="docker"
    fi
fi

DB_NAME="${MYSQL_DB_NAME:-KMP_DEV}"
TEST_DB_NAME="${DB_NAME}_test"
DB_USER="${MYSQL_USERNAME:-KMPSQLDEV}"
DB_HOST="${MYSQL_HOST:-localhost}"
DB_PORT="${MYSQL_PORT:-3306}"
DB_PASS="${MYSQL_PASSWORD:-P@ssw0rd}"
DB_ROOT_PASS="${MYSQL_ROOT_PASSWORD:-rootpassword}"
INSTALLER_LOCK_FILE_DOCKER="${INSTALLER_LOCK_FILE:-/var/www/html/tmp/installer/install.lock}"
INSTALLER_LOCK_FILE_LOCAL="${INSTALLER_LOCK_FILE:-${APP_DIR}/tmp/installer/install.lock}"
if [[ "${INSTALLER_LOCK_FILE_LOCAL}" == /var/www/html/* ]]; then
    INSTALLER_LOCK_FILE_LOCAL="${APP_DIR}${INSTALLER_LOCK_FILE_LOCAL#/var/www/html}"
fi

if [ "$RUN_MODE" = "local" ]; then
    if ! command -v mysql >/dev/null 2>&1 || ! command -v mysqldump >/dev/null 2>&1; then
        echo "❌ mysql/mysqldump not found. Install them or run with docker compose services."
        exit 1
    fi
fi

TS_UTC="$(date -u +%Y%m%dT%H%M%SZ)"
TS_DB="$(date -u +%Y%m%d_%H%M%S)"
ARCHIVE_DIR=""
ARCHIVE_DB="${DB_NAME}_archive_${TS_DB}"

db_query_scalar() {
    local sql="$1"
    if [ "$RUN_MODE" = "docker" ]; then
        docker compose exec -T db mariadb -uroot -p"${DB_ROOT_PASS}" -N -e "$sql" | tr -d '\r'
    else
        mysql -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -N -e "$sql" | tr -d '\r'
    fi
}

db_query_to_file() {
    local sql="$1"
    local output_file="$2"
    if [ "$RUN_MODE" = "docker" ]; then
        docker compose exec -T db mariadb -uroot -p"${DB_ROOT_PASS}" -N -e "$sql" > "$output_file"
    else
        mysql -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -N -e "$sql" > "$output_file"
    fi
}

db_exec() {
    local sql="$1"
    if [ "$RUN_MODE" = "docker" ]; then
        docker compose exec -T db mariadb -uroot -p"${DB_ROOT_PASS}" -e "$sql"
    else
        mysql -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "$sql"
    fi
}

db_clone() {
    local source_db="$1"
    local target_db="$2"
    db_exec "DROP DATABASE IF EXISTS \`${target_db}\`; CREATE DATABASE \`${target_db}\` COLLATE utf8_unicode_ci;"
    if [ "$RUN_MODE" = "docker" ]; then
        docker compose exec -T db mariadb-dump -uroot -p"${DB_ROOT_PASS}" --single-transaction --routines --triggers --events "${source_db}" \
            | docker compose exec -T db mariadb -uroot -p"${DB_ROOT_PASS}" "${target_db}"
    else
        mysqldump -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USER" -p"$DB_PASS" --single-transaction --routines --triggers --events "${source_db}" \
            | mysql -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "${target_db}"
    fi
}

db_recreate_blank() {
    local sql="
DROP DATABASE IF EXISTS \`${DB_NAME}\`;
CREATE DATABASE \`${DB_NAME}\` COLLATE utf8_unicode_ci;
DROP DATABASE IF EXISTS \`${TEST_DB_NAME}\`;
CREATE DATABASE \`${TEST_DB_NAME}\` COLLATE utf8_unicode_ci;
"

    if [ "$RUN_MODE" = "docker" ]; then
        sql="${sql}
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
GRANT ALL PRIVILEGES ON \`${TEST_DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
"
    fi

    db_exec "$sql"
}

clear_installer_state() {
    if [ "$RUN_MODE" = "docker" ]; then
        docker compose exec -T app sh -lc "
mkdir -p \"\$(dirname \"${INSTALLER_LOCK_FILE_DOCKER}\")\" &&
rm -f \"${INSTALLER_LOCK_FILE_DOCKER}\" &&
rm -rf /var/www/html/tmp/installer/state/* &&
find /var/www/html/tmp/sessions -mindepth 1 -delete 2>/dev/null || true
"
    else
        if ! mkdir -p "$(dirname "${INSTALLER_LOCK_FILE_LOCAL}")" "${APP_DIR}/tmp/installer/state" 2>/dev/null; then
            echo "⚠️  Could not clear installer lock/state due to filesystem permissions."
            echo "   Run ./fix_permissions.sh, then rerun this script."
            return
        fi
        rm -f "${INSTALLER_LOCK_FILE_LOCAL}" || true
        find "${APP_DIR}/tmp/installer/state" -mindepth 1 -delete 2>/dev/null || true
        # Clear PHP sessions so stale wizard data can't contaminate the fresh install
        find "${APP_DIR}/tmp/sessions" -mindepth 1 -delete 2>/dev/null || true
    fi
}

echo "🔧 Running installer reset in ${RUN_MODE} mode ..."

if [ "$ARCHIVE_CURRENT" = true ]; then
    ARCHIVE_DIR="test-results/install-archive-${TS_UTC}"
    mkdir -p "${ARCHIVE_DIR}/config" "${ARCHIVE_DIR}/db"

    echo "📦 Archiving current configuration to ${ARCHIVE_DIR}/config ..."
    for config_file in app/config/.env app/config/.env.example app/config/app_local.php app/config/app.php app/config/routes.php app/tmp/installer/install.lock; do
        if [ -f "$config_file" ]; then
            cp -a "$config_file" "${ARCHIVE_DIR}/config/"
        fi
    done

    echo "🗄️  Cloning ${DB_NAME} to archive database ${ARCHIVE_DB} ..."
    SOURCE_TABLE_COUNT="$(db_query_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';")"
    db_query_to_file "SELECT table_name FROM information_schema.tables WHERE table_schema='${DB_NAME}' ORDER BY table_name;" "${ARCHIVE_DIR}/db/${DB_NAME}_tables_before.txt"
    db_clone "${DB_NAME}" "${ARCHIVE_DB}"
    ARCHIVE_TABLE_COUNT="$(db_query_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${ARCHIVE_DB}';")"
fi

echo "🧹 Recreating blank databases: ${DB_NAME}, ${TEST_DB_NAME} ..."
db_recreate_blank

echo "🔓 Clearing installer lock/state ..."
clear_installer_state

NEW_TABLE_COUNT="$(db_query_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';")"

echo ""
echo "✅ Installer test reset complete."
echo "   Mode:      ${RUN_MODE}"
echo "   Source DB: ${DB_NAME} (now blank, ${NEW_TABLE_COUNT} tables)"
echo "   Test DB:   ${TEST_DB_NAME} (blank)"
if [ "$ARCHIVE_CURRENT" = true ]; then
    echo "   Archive DB: ${ARCHIVE_DB} (${ARCHIVE_TABLE_COUNT} tables)"
    cat > "${ARCHIVE_DIR}/db/archive-summary.txt" <<SUMMARY
archive_dir=${ARCHIVE_DIR}
run_mode=${RUN_MODE}
source_db=${DB_NAME}
source_table_count_before=${SOURCE_TABLE_COUNT}
archive_db=${ARCHIVE_DB}
archive_table_count=${ARCHIVE_TABLE_COUNT}
new_source_table_count=${NEW_TABLE_COUNT}
created_at_utc=${TS_UTC}
SUMMARY
    echo "   Archive dir: ${ARCHIVE_DIR}"
fi
echo ""
echo "Next step: open http://localhost:8080/install and walk the installer."
