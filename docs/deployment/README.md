# Archived Self-Hosted Deployment Reference

KMP is moving to a managed multi-tenant hosting model. The standalone installer is retired for new deployments, but the self-hosted deployment knowledge is preserved here for legacy operators and maintainers.

## Status

| Area | Current status |
|------|----------------|
| Managed multi-tenant hosting | Primary deployment path |
| `kmp install` | Retired for new deployments |
| `bin/cake kmp_install` | Retired for new deployments |
| Self-hosted guides below | Archived reference |

## Legacy Self-Hosted Platforms

| Platform | Type | Database | SSL | Difficulty |
|----------|------|----------|-----|------------|
| Docker (Local/VPC) | Self-hosted | Bundled MariaDB or BYO | Caddy (auto Let's Encrypt) | ⭐ Easy |
| Fly.io | PaaS | Fly Postgres | Automatic | ⭐ Easy |
| Railway | PaaS | Managed MySQL / Redis (optional) | Automatic (Railway edge TLS, no extra proxy required) | ⭐ Easy |
| Azure | Cloud | Azure DB for MySQL | Automatic | ⭐⭐ Moderate |
| AWS | Cloud | RDS MySQL | ALB/ACM | ⭐⭐ Moderate |
| VPS (SSH) | Self-hosted | Bundled MariaDB | Caddy | ⭐⭐ Moderate |
| Shared hosting (no root) | Traditional web host | Provider-managed or external | Provider-managed | ⭐⭐ Moderate |

## Legacy Lifecycle Commands

| Command | Description |
|---------|-------------|
| `kmp install` | Retired; retained as historical reference only |
| `kmp update` | Legacy self-hosted maintenance |
| `kmp status` | Legacy self-hosted health and version checks |
| `kmp logs [-f]` | Legacy self-hosted log access |
| `kmp backup` | Legacy self-hosted database backup |
| `kmp restore <id>` | Legacy self-hosted restore |
| `kmp rollback` | Legacy self-hosted rollback |
| `kmp config` | Legacy self-hosted deployment configuration |
| `kmp self-update` | Legacy tool maintenance |

## Self-Hosted Image Channels

| Channel | Stability | Use Case |
|---------|-----------|----------|
| `release` | Stable | Production deployments (default) |
| `beta` | Pre-release | Testing upcoming features |
| `dev` | Development | Latest main branch |
| `nightly` | Nightly build | Bleeding edge |

## Legacy Self-Hosted Architecture

The KMP deployment system uses pre-built Docker images:

```
GitHub Releases → ghcr.io/jhandel/kmp:{tag} → Your infrastructure
```

Every app image release is:
- Multi-architecture (amd64 + arm64)
- Smoke-tested in CI before publishing
- Tagged with version, channel, and SHA digest
- Immutable once published

## Archived Guides

- [Docker/VPC Quick Start](quickstart-vpc.md)
- [Fly.io Quick Start](quickstart-fly.md)
- [Railway Quick Start](quickstart-railway.md)
- [Azure Quick Start](quickstart-azure.md)
- [AWS Quick Start](quickstart-aws.md)
- [Updating & Rollback](updating.md)
- [Backup & Restore](backup-restore.md)
- [Configuration Reference](configuration.md)
- [Troubleshooting](troubleshooting.md)
