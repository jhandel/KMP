#!/bin/bash
# Start KMP development environment
# Usage: ./dev-up.sh [--build]
#
# Options:
#   --build    Force rebuild of containers

set -e

cd "$(dirname "$0")"

echo "🚀 Starting KMP Development Environment..."

KMP_CONTAINERS=("kmp-db" "kmp-mailpit" "kmp-app")
KMP_VOLUMES=("kmp-db-data" "kmp-composer-cache" "kmp-node-modules")

compose_project_name() {
    docker compose config --format json 2>/dev/null \
        | sed -n 's/^[[:space:]]*"name": "\([^"]*\)",[[:space:]]*$/\1/p' \
        | head -n 1
}

ensure_named_volumes() {
    for volume in "${KMP_VOLUMES[@]}"; do
        if ! docker volume inspect "$volume" >/dev/null 2>&1; then
            echo "Creating Docker volume: $volume"
            docker volume create "$volume" >/dev/null
        fi
    done
}

remove_stale_kmp_containers() {
    local current_project
    current_project="$(compose_project_name)"

    if [ -z "$current_project" ]; then
        echo "❌ Error: Unable to determine Docker Compose project name."
        exit 1
    fi

    for container in "${KMP_CONTAINERS[@]}"; do
        local container_id
        container_id="$(docker ps -aq --filter "name=^/${container}$" | head -n 1)"

        if [ -z "$container_id" ]; then
            continue
        fi

        local container_project
        container_project="$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}' "$container_id" 2>/dev/null || true)"

        if [ "$container_project" != "$current_project" ]; then
            if [ -z "$container_project" ] || [ "$container_project" = "<no value>" ]; then
                container_project="unmanaged"
            fi

            echo "🧹 Removing stale container $container from project '$container_project'..."
            docker rm -f "$container_id" >/dev/null
        fi
    done
}

ensure_named_volumes
remove_stale_kmp_containers

if [ "$1" == "--build" ]; then
    echo "Building containers..."
    docker compose build --no-cache
fi

docker compose up -d

echo ""
echo "⏳ Waiting for services to be healthy..."
sleep 5

# Wait for app to be ready
max_wait=120
waited=0
while ! curl -sf http://localhost:8080/ > /dev/null 2>&1; do
    if [ $waited -ge $max_wait ]; then
        echo "⚠️  App not responding after ${max_wait}s - check logs with: docker compose logs app"
        break
    fi
    sleep 2
    waited=$((waited + 2))
    echo "  Waiting for app... (${waited}s)"
done

echo ""
echo "✅ KMP Development Environment is running!"
echo ""
echo "   📱 Application:  http://localhost:8080"
echo "   📧 Mailpit:      http://localhost:8025"
echo "   🗄️  MySQL:        localhost:3306"
echo ""
echo "Useful commands:"
echo "   docker compose logs -f app    # Follow app logs"
echo "   docker compose exec app bash  # Shell into app container"
echo "   ./dev-reset-db.sh             # Reset database"
echo "   ./dev-down.sh                 # Stop environment"
