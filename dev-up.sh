#!/bin/bash
# Start KMP development environment
# Usage: ./dev-up.sh [--build] [--no-cleanup]
#
# Options:
#   --build       Force rebuild of containers
#   --no-cleanup  Do not remove stale/conflicting KMP containers first

set -e

cd "$(dirname "$0")"

echo "🚀 Starting KMP Development Environment..."

BUILD=0
CLEANUP=1
for arg in "$@"; do
    case "$arg" in
        --build)
            BUILD=1
            ;;
        --no-cleanup)
            CLEANUP=0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            echo "Usage: ./dev-up.sh [--build] [--no-cleanup]" >&2
            exit 1
            ;;
    esac
done

container_label() {
    docker inspect -f "{{ index .Config.Labels \"$2\" }}" "$1" 2>/dev/null || true
}

remove_container() {
    local id="$1"
    local name
    name=$(docker inspect -f '{{ .Name }}' "$id" 2>/dev/null | sed 's|^/||')
    echo "  Removing stale/conflicting container: $name"
    docker rm -f "$id" >/dev/null
}

cleanup_named_containers() {
    local current_dir="$PWD"
    local names=(kmp-app kmp-db kmp-mailpit)

    for name in "${names[@]}"; do
        local id
        id=$(docker ps -aq --filter "name=^/${name}$" | head -n 1)
        if [ -z "$id" ]; then
            continue
        fi

        local working_dir
        working_dir=$(container_label "$id" "com.docker.compose.project.working_dir")
        if [ "$working_dir" = "$current_dir" ]; then
            continue
        fi

        remove_container "$id"
    done
}

cleanup_port_conflicts() {
    local current_dir="$PWD"
    local ports=(3306 8080 8025 1025)

    for port in "${ports[@]}"; do
        while read -r id; do
            if [ -z "$id" ]; then
                continue
            fi

            local name working_dir project
            name=$(docker inspect -f '{{ .Name }}' "$id" 2>/dev/null | sed 's|^/||')
            working_dir=$(container_label "$id" "com.docker.compose.project.working_dir")
            project=$(container_label "$id" "com.docker.compose.project")

            if [ "$working_dir" = "$current_dir" ]; then
                continue
            fi

            if [[ "$name" == kmp-* || "$project" == *KMP* || "$working_dir" == *"/KMP/"* ]]; then
                remove_container "$id"
                continue
            fi

            echo "❌ Port $port is already used by non-KMP container '$name'." >&2
            echo "   Stop it or rerun after freeing the port." >&2
            exit 1
        done < <(docker ps -aq --filter "publish=$port")
    done
}

if [ "$CLEANUP" -eq 1 ]; then
    echo "Cleaning up stale KMP containers and port conflicts..."
    cleanup_named_containers
    cleanup_port_conflicts
fi

if [ "$BUILD" -eq 1 ]; then
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
echo "   🔐 Platform:     http://admin.localhost:8080/platform-admin"
echo "                   (http://localhost:8080/platform-admin redirects here)"
echo "   🏷️  Dev tenant:   localdev (localhost, 127.0.0.1, kmp.localhost)"
echo "   📧 Mailpit:      http://localhost:8025"
echo "   🗄️  MySQL:        localhost:3306"
echo ""
echo "Useful commands:"
echo "   docker compose logs -f app    # Follow app logs"
echo "   docker compose exec app bash  # Shell into app container"
echo "   ./dev-reset-db.sh             # Reset database"
echo "   ./dev-down.sh                 # Stop environment"
