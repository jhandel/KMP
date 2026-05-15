#!/usr/bin/env bash
# Reset the development database, load seed data, then apply any new migrations.
#
# Engine-aware: inspects DATABASE_URL (and falls back to MYSQL_* vars) to decide
# whether to run the MySQL flow (cleaned MariaDB dump) or the Postgres flow
# (seed chain, since there is no Postgres dump to load).
#
# Steps (MySQL):
# 1. Drop & recreate via Cake `resetDatabase`.
# 2. Load raw seed data from dev_seed_clean.sql (MariaDB dump).
# 3. Run pending migrations (catches newer migrations not in the dump).
# 4. Update database (plugin migrations).
# 5. Reset all member passwords to TestPassword.
#
# Steps (Postgres):
# 1. Drop & recreate via Cake `resetDatabase` (drops all tables in public schema).
# 2. Run migrations (core schema + init/backfill data seeded by migrations).
# 3. Update database (plugin migrations).
# 4. Seed DevLoad fixtures via `bin/cake migrations seed --seed DevLoadSeed`.
# 5. Reset all member passwords to TestPassword.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$ROOT_DIR/app"
SEED_SQL="$ROOT_DIR/dev_seed_clean.sql"

# Strategy to avoid duplicate dotenv loading:
# The bootstrap only loads .env if APP_NAME is not set. We set a dummy APP_NAME early
# then (if needed) source the file ourselves ONLY when vars are missing.
export APP_NAME="KMP_DEV_APP"

ENV_FILE="$APP_DIR/config/.env"
if [ -f "$ENV_FILE" ] && [ -z "${DATABASE_URL-}" ] && [ -z "${MYSQL_USERNAME-}" ]; then
	echo "[reset_dev_database] Loading env vars from $ENV_FILE"
	# shellcheck disable=SC1090
	. "$ENV_FILE"
fi

echo "[reset_dev_database] Starting reset process..."

# Decide engine. Prefer TENANT_DATABASE_URL/DATABASE_URL when set.
DB_ENGINE="mysql"
TENANT_URL="${TENANT_DATABASE_URL:-${DATABASE_URL-}}"
if [ -n "${TENANT_URL-}" ]; then
	case "$(echo "${TENANT_URL}" | tr '[:upper:]' '[:lower:]')" in
		postgres*|pgsql*)
			DB_ENGINE="postgres"
			;;
	esac
fi

cd "$APP_DIR"

if [ "$DB_ENGINE" = "postgres" ]; then
	echo "[reset_dev_database] Engine: PostgreSQL (DATABASE_URL=${DATABASE_URL})"

	echo "[1/4] Resetting database schema (bin/cake resetDatabase)..."
	bin/cake resetDatabase

	echo "[2/4] Running core migrations (bin/cake migrations migrate)..."
	bin/cake migrations migrate

	echo "[3/4] Running plugin migrations (bin/cake updateDatabase)..."
	bin/cake updateDatabase

	echo "[4/4] Seeding DevLoad fixtures (bin/cake migrations seed --seed DevLoadSeed)..."
	bin/cake migrations seed --seed DevLoadSeed
else
	echo "[reset_dev_database] Engine: MySQL/MariaDB"

	if [ ! -f "$SEED_SQL" ]; then
		echo "Seed SQL file not found: $SEED_SQL" >&2
		exit 1
	fi

	# Ensure required env vars are present (these are referenced in app_local.php)
	: "${MYSQL_USERNAME:?Environment variable MYSQL_USERNAME is required}"
	: "${MYSQL_PASSWORD:?Environment variable MYSQL_PASSWORD is required}"
	: "${MYSQL_DB_NAME:?Environment variable MYSQL_DB_NAME is required}"

	DB_USER="${TENANT_DB_USERNAME:-$MYSQL_USERNAME}"
	DB_PASS="${TENANT_DB_PASSWORD:-$MYSQL_PASSWORD}"
	DB_NAME="${TENANT_DB_DATABASE:-$MYSQL_DB_NAME}"
	DB_HOST="${TENANT_DB_HOST:-${MYSQL_HOST:-localhost}}"

	echo "[1/4] Resetting database schema (bin/cake resetDatabase)..."
	bin/cake resetDatabase

	echo "[2/4] Loading seed SQL dump ($SEED_SQL) into $DB_NAME..."
	MYSQL_CMD=(mysql -h "$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME")

	# Disable foreign key checks during import for safety (dump likely already handles this)
	"${MYSQL_CMD[@]}" < "$SEED_SQL"

	echo "[3/4] Applying any new migrations (bin/cake migrations migrate)..."
	bin/cake migrations migrate

	echo "[4/4] Updating database (bin/cake updateDatabase)..."
	bin/cake updateDatabase
fi

echo "[post] Preparing platform tenant registry..."
TENANT_SLUG="${DEV_TENANT_SLUG:-localdev}"
TENANT_DISPLAY_NAME="${DEV_TENANT_DISPLAY_NAME:-Local Development}"
PLATFORM_ADMIN_SEED_EMAIL="${PLATFORM_ADMIN_SEED_EMAIL:-platform-admin@localhost.test}"
if [ "$PLATFORM_ADMIN_SEED_EMAIL" = "platform-admin@localhost" ]; then
	PLATFORM_ADMIN_SEED_EMAIL="platform-admin@localhost.test"
fi
TENANT_DB_NAME="${TENANT_DB_DATABASE:-${MYSQL_DB_NAME:-KMP_DEV}}"
TENANT_DB_HOST="${TENANT_DB_HOST:-${MYSQL_HOST:-localhost}}"
TENANT_DB_USER="${TENANT_DB_USERNAME:-${MYSQL_USERNAME:-}}"
export TENANT_DB_PASSWORD="${TENANT_DB_PASSWORD:-${DB_PASS:-${MYSQL_PASSWORD:-}}}"
export PLATFORM_DB_HOST="${PLATFORM_DB_HOST:-$TENANT_DB_HOST}"
export PLATFORM_DB_USERNAME="${PLATFORM_DB_USERNAME:-$TENANT_DB_USER}"
export PLATFORM_DB_PASSWORD="${PLATFORM_DB_PASSWORD:-$TENANT_DB_PASSWORD}"
export PLATFORM_DB_DATABASE="${PLATFORM_DB_DATABASE:-$TENANT_DB_NAME}"
TENANT_DB_DRIVER='Cake\Database\Driver\Mysql'
TENANT_CREATE_DB_ARGS=(--database-name="$TENANT_DB_NAME" --host="$TENANT_DB_HOST")
if [ "$DB_ENGINE" = "postgres" ]; then
	TENANT_DB_DRIVER='Cake\Database\Driver\Postgres'
	TENANT_CREATE_DB_ARGS=(--database-name="$TENANT_DB_NAME" --host="${TENANT_DB_HOST:-127.0.0.1}" --port="${TENANT_DB_PORT:-5432}")
	if [ -n "${TENANT_DATABASE_URL-}" ]; then
		TENANT_CREATE_DB_ARGS=(--database-url="$TENANT_DATABASE_URL")
	fi
fi

bin/cake platform:migrate
bin/cake platform_admin:seed \
	--email="$PLATFORM_ADMIN_SEED_EMAIL" \
	--password=TestPassword \
	--force \
	--no-require-change \
	--allow-weak-password
bin/cake tenant:create "$TENANT_SLUG" \
	--display-name="$TENANT_DISPLAY_NAME" \
	--primary-host=localhost \
	--alias=127.0.0.1 \
	--alias=kmp.localhost \
	--driver="$TENANT_DB_DRIVER" \
	"${TENANT_CREATE_DB_ARGS[@]}" \
	--username="$TENANT_DB_USER" \
	--secret-reference=env:TENANT_DB_PASSWORD \
	--email-config-json='{"transport":{"host":"localhost","port":1025,"username":"testuser","tls":false},"email":{"from":"noreply@localhost"}}' \
	--email-secret-reference=env:LOCALDEV_SMTP_PASSWORD \
	--storage-adapter=local \
	--storage-config-json='{"local":{"path":"tmp/tenant-storage/localdev"}}' \
	--activate

echo "[post] Setting ALL member passwords to TestPassword via ORM..."
php -r '
require "vendor/autoload.php";
require "config/bootstrap.php";
use Cake\ORM\TableRegistry;
$members = TableRegistry::getTableLocator()->get("Members");
$all = $members->find("all");
$count = 0; $errors = 0;
foreach ($all as $m) {
	$m->password = "TestPassword"; // triggers entity mutator for hashing
	if ($members->save($m)) {
		$count++;
	} else {
		$errors++;
		fwrite(STDERR, "Failed to save member ID {$m->id}\n");
	}
}
echo "Updated passwords for $count members. Errors: $errors\n";
if ($errors > 0) { exit(1); }
'

echo "[post] Clearing CakePHP caches..."
php -r '
require "vendor/autoload.php";
require "config/bootstrap.php";
use Cake\Cache\Cache;
$failed = [];
foreach (Cache::configured() as $config) {
	if (in_array($config, ["_cake_core_", "_cake_routes_"], true)) {
		continue;
	}
	try {
		if (!Cache::clear($config)) {
			$failed[] = "$config (clear returned false)";
		}
	} catch (Throwable $e) {
		$failed[] = $config . " (" . $e->getMessage() . ")";
	}
}
if (!empty($failed)) {
	fwrite(STDERR, "Warning: Failed to clear cache configs: " . implode(", ", $failed) . "\n");
}
'

echo "[reset_dev_database] Complete."
