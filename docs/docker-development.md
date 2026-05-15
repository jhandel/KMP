# Docker Development Environment

This project includes a multi-container Docker setup optimized for local development and agentic/hosted development environments.

Use this workflow when an agent such as GitHub Copilot App edits the repository from the host worktree. The host owns the git checkout and source edits; Docker owns PHP, Composer dependencies, Node dependencies, browser binaries, MariaDB, and Mailpit. This gives most of the devcontainer runtime benefits without requiring the editor or agent to run inside a VS Code Dev Container.

## Quick Start

```bash
# Start the development environment
./dev-up.sh

# Stop the environment (preserves database)
./dev-down.sh

# Reset database to clean state
./dev-reset-db.sh

# Reset database and load seed data
./dev-reset-db.sh --seed

# Run checks inside the app container
./dev-test.sh build
./dev-test.sh js
./dev-test.sh php
./dev-test.sh ui-smoke
```

## Architecture

The setup uses Docker Compose with three separate containers:

| Service | Container | Purpose | Port |
|---------|-----------|---------|------|
| `app` | kmp-app | PHP 8.3 + Apache | 8080 |
| `db` | kmp-db | MariaDB 11 | 3306 |
| `mailpit` | kmp-mailpit | Email testing | 8025 (UI), 1025 (SMTP) |

### Benefits of Multi-Container Setup

- **Agentic-friendly**: Each service can be inspected/managed independently
- **Fast iteration**: Code changes reflect immediately (volume mounted)
- **Clean host machine**: `vendor`, `node_modules`, npm cache, Composer cache, and Playwright browsers live in Docker volumes
- **Persistent data**: Database survives container restarts
- **Clean separation**: Easy to debug service-specific issues
- **Quick rebuilds**: Only rebuild what changed

### Agentic Development vs. Dev Containers

KMP supports two container-based development modes:

| Mode | Use when | How it works |
|------|----------|--------------|
| **Docker Compose agentic workflow** | You are using GitHub Copilot App, Copilot CLI, another host-based agent, or any editor that should operate on the local worktree | The repo stays on the host. `docker compose` mounts the repo into containers and all runtime/test commands execute in `kmp-app`. |
| **VS Code Dev Container** | You are developing interactively inside VS Code and want the editor, terminal, language tools, database, and services all in one containerized environment | VS Code reopens the workspace inside `.devcontainer`; commands run from inside that container. |

For agentic work, prefer the Docker Compose workflow. Dev Containers are still useful for full IDE integration, but host-based agents cannot attach to the `.devcontainer` as their working environment.

## Usage

### Starting the Environment

```bash
# First time or after pulling changes
./dev-up.sh --build

# Normal startup (uses cached images)
./dev-up.sh
```

### Accessing the Application

- **Web App**: http://localhost:8080
- **Mailpit UI**: http://localhost:8025 (view sent emails)
- **MySQL**: localhost:3306 (user: `KMPSQLDEV`, password: `P@ssw0rd`)

### Running Commands and Tests in the Container

Prefer `dev-test.sh` for repeatable checks:

```bash
# Shell into the app container
./dev-test.sh shell

# Standard checks
./dev-test.sh build      # Vite build
./dev-test.sh js         # Jest
./dev-test.sh php        # PHPUnit
./dev-test.sh cs         # PHPCS on changed PHP files
./dev-test.sh stan       # PHPStan, matching the repo baseline behavior

# Browser checks
./dev-test.sh ui-smoke   # Seeded DB reset + Playwright smoke lane
./dev-test.sh ui         # Seeded DB reset + full Playwright UAT lane

# Reset database
./dev-test.sh reset-db --seed
```

You can still run raw commands when needed:

```bash
# CakePHP commands
docker compose exec app bin/cake migrations status
docker compose exec app bin/cake migrations migrate

# Composer and npm commands run in the container
docker compose exec app composer require some/package
docker compose exec app npm install some-package --save-dev

# Build assets
docker compose exec app npm run build
```

The app container uses lockfile hashes to refresh `vendor` and `node_modules` when `composer.lock` or `package-lock.json` changes.

### Database Management

```bash
# Reset to clean state (runs migrations)
./dev-reset-db.sh

# Reset and load seed data
./dev-reset-db.sh --seed

# Connect to MySQL directly
docker compose exec db mysql -uKMPSQLDEV -pP@ssw0rd KMP_DEV

# View database logs
docker compose logs db
```

### Stopping the Environment

```bash
# Stop containers (preserves database volume)
./dev-down.sh

# Stop and DELETE all data (fresh start)
./dev-down.sh --volumes
```

## Configuration

### Environment Variables

Docker Compose sets the application runtime environment from `docker-compose.yml`. Defaults can be overridden from your shell or from a repo-root `.env` file:

```bash
cp docker/.env.example .env
```

This root `.env` file is read by Docker Compose for variable substitution such as `${MYSQL_USERNAME:-KMPSQLDEV}`. It is not the same as CakePHP's `app/config/.env`.

The Docker app service sets `APP_NAME=KMP_DOCKER`, so CakePHP intentionally skips `app/config/.env` loading and reads container environment variables directly through `docker/app_local.php`.

Available variables:

| Variable | Default | Description |
|----------|---------|-------------|
| `MYSQL_ROOT_PASSWORD` | rootpassword | MySQL root password |
| `MYSQL_DB_NAME` | KMP_DEV | Database name |
| `MYSQL_USERNAME` | KMPSQLDEV | Database user |
| `MYSQL_PASSWORD` | P@ssw0rd | Database password |
| `PLATFORM_DB_DATABASE` | KMP_PLATFORM | Platform registry database name |

Do not put Docker Compose development secrets in `app/config/.env`; use the root `.env` file or shell environment variables for this workflow.

### Xdebug

Xdebug is pre-configured for step debugging:

- Mode: `debug,develop`
- Port: 9003
- Host: `host.docker.internal`
- Start mode: `yes`

For VS Code host-worktree debugging:

1. Install the recommended `xdebug.php-debug` extension from `.vscode/extensions.json`.
2. Start the Docker stack with `./dev-up.sh --build`.
3. Set breakpoints in files under `app/`.
4. In VS Code, run **Listen for Xdebug (Docker app)** and wait for the debug toolbar/listener to appear.
5. Open `http://localhost:8080` or run **Launch KMP - Chrome with Xdebug**.

The app image uses `xdebug.start_with_request=yes`, so every PHP request tries to connect to the VS Code listener. That makes multi-page debugging work without appending `?XDEBUG_TRIGGER=VSCODE` to each URL. The PHP launch configuration binds to `0.0.0.0:9003` so the container can connect back to VS Code, and maps `/var/www/html` in the container to `${workspaceFolder}/app` on the host so breakpoints bind to the local source folder. **Debug KMP Docker app in Chrome** is available as a convenience compound, but starting the PHP listener first is more reliable because compounds can open the browser before the listener is ready.

## Troubleshooting

### Container won't start

```bash
# Check logs
docker compose logs app
docker compose logs db

# Rebuild from scratch
docker compose down -v
./dev-up.sh --build
```

### Database connection issues

```bash
# Verify database is healthy
docker compose ps

# Check database logs
docker compose logs db

# Try connecting directly
docker compose exec db mysql -uroot -prootpassword -e "SHOW DATABASES;"
```

### Permission issues

```bash
# Fix permissions inside container
docker compose exec app chown -R www-data:www-data /var/www/html/logs /var/www/html/tmp
docker compose exec app chmod -R 775 /var/www/html/logs /var/www/html/tmp
```

### Clear all caches

```bash
docker compose exec app bin/cake cache clear_all
```

## Comparison with Devcontainer

| Feature | Docker Compose agentic workflow | VS Code Dev Container |
|---------|----------------------------------|-----------------------|
| Best for | Host-based agents and any editor | VS Code remote-container development |
| Editor/agent location | Host worktree | Inside container |
| Runtime location | Docker containers | Devcontainer |
| PHP/Node dependencies | Docker volumes (`vendor`, `node_modules`) | Container filesystem |
| Browser test binaries | Docker volume (`playwright-cache`) | Container cache |
| App/database/mail services | Separate compose services | Services inside devcontainer |
| Config source | `docker-compose.yml` + root `.env` | `.devcontainer/devcontainer.json` + generated `app/config/.env` |
| Agentic Dev | Excellent | Limited for host-based agents |

## File Structure

```
docker/
├── Dockerfile.app      # PHP/Apache container
├── apache-vhost.conf   # Apache configuration
├── app_local.php       # CakePHP config for Docker
├── entrypoint.sh       # Container initialization script
└── .env.example        # Environment variable template

docker-compose.yml      # Service definitions
dev-up.sh              # Start environment
dev-down.sh            # Stop environment
dev-reset-db.sh        # Reset database
dev-test.sh            # Run checks inside the app container
```
