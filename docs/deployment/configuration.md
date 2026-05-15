# Configuration Reference

Complete reference for all KMP deployment configuration options.

[← Back to Deployment Guide](README.md)

> Legacy note: this page documents self-hosted configuration for archived deployments. New environments should not rely on the retired installer flow.

## Environment Variables

### Required

| Variable | Description | Example |
|----------|-------------|---------|
| `DOMAIN` | Domain name for SSL certificate | `kmp.example.com` |
| `SECURITY_SALT` | Application security salt (hex string) | `openssl rand -hex 32` |
| `MYSQL_ROOT_PASSWORD` | Database root password | `openssl rand -base64 24` |
| `MYSQL_PASSWORD` | Database application user password | `openssl rand -base64 24` |

### Application

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | `KMP` | Application display name |
| `DEBUG` | `false` | Enable debug mode (`true`/`false`) |
| `KMP_IMAGE_TAG` | `latest` | Docker image tag (version or channel) |
| `KMP_DEPLOY_PROVIDER` | `docker` | Deployment provider identifier (`docker`, `vpc`, `railway`, `fly`, `aws`, `azure`, `shared`) |
| `DEPLOYMENT_PROVIDER` | `docker` | App runtime provider override (falls back to `KMP_DEPLOY_PROVIDER` when unset) |

### Database

| Variable | Default | Description |
|----------|---------|-------------|
| `MYSQL_HOST` | `db` | Database hostname |
| `MYSQL_DB_NAME` | `kmp` | Database name |
| `MYSQL_USERNAME` | `kmpuser` | Database username |
| `MYSQL_PASSWORD` | — | Database password (required) |
| `MYSQL_ROOT_PASSWORD` | — | Database root password (required for VPC) |

### Multi-Tenant Databases

Managed multi-tenant deployments use a separate platform datastore plus one database per tenant.

| Variable | Default | Description |
|----------|---------|-------------|
| `PLATFORM_DB_HOST` | `localhost` | Platform registry database host |
| `PLATFORM_DB_PORT` | `3306` | Platform registry database port |
| `PLATFORM_DB_USERNAME` | `root` | Platform registry database username |
| `PLATFORM_DB_PASSWORD` | — | Platform registry database password |
| `PLATFORM_DB_DATABASE` | `kmp_platform` | Platform registry database name |
| `PLATFORM_DATABASE_URL` | — | Optional complete DSN for the platform registry |
| `TENANT_DB_HOST` | falls back to legacy DB host | Optional default/template tenant database host before a tenant is resolved |
| `TENANT_DB_DATABASE` | falls back to legacy DB name | Optional default/template tenant database name for local development and `tenant:create` defaults |
| `TENANT_DATABASE_URL` | falls back to `DATABASE_URL` | Optional default/template tenant DSN for local development |

`PLATFORM_*` settings must identify a datastore that is separate from every tenant database. The application intentionally does not fall back from `platform` to `DB_DATABASE`, `MYSQL_DB_NAME`, or `DATABASE_URL`.

`TENANT_DB_*` settings are not one setting per tenant. They are only a generic default connection used before tenant resolution and as defaults for provisioning commands. Each real tenant's database metadata is stored in the platform datastore (`tenant_database_configs`) and can point at a per-tenant secret reference such as `env:ANSTEORRA_DB_PASSWORD`, `env:OUTLANDS_DB_PASSWORD`, or an external vault key.

The same rule applies to tenant-specific email and storage values. Shared `.env` keys such as `EMAIL_SMTP_PASSWORD`, `AZURE_STORAGE_CONNECTION_STRING`, or `AWS_SECRET_ACCESS_KEY` are deployment defaults. Tenant production values should be stored as `tenant_service_configs` metadata in the platform datastore with tenant-specific secret references such as `env:ANSTEORRA_SMTP_PASSWORD` or `env:ANSTEORRA_AZURE_STORAGE_CONNECTION_STRING`.

If PostgreSQL is fronted by PgBouncer, follow the validation and rollout guidance in [PgBouncer Validation (CakePHP + Multi-Tenant PostgreSQL)](pgbouncer-validation.md) before enabling transaction pooling.

### Email (SMTP)

| Variable | Default | Description |
|----------|---------|-------------|
| `EMAIL_SMTP_HOST` | — | SMTP server hostname |
| `EMAIL_SMTP_PORT` | `587` | SMTP server port |
| `EMAIL_SMTP_USERNAME` | — | SMTP authentication username |
| `EMAIL_SMTP_PASSWORD` | — | SMTP authentication password |

### Document Storage

| Variable | Default | Description |
|----------|---------|-------------|
| `DOCUMENT_STORAGE_ADAPTER` | `local` | Storage backend: `local`, `azure`, or `s3` |
| `AZURE_STORAGE_CONNECTION_STRING` | — | Azure Blob Storage connection string |
| `AWS_ACCESS_KEY_ID` | — | AWS access key for S3 storage |
| `AWS_SECRET_ACCESS_KEY` | — | AWS secret key for S3 storage |
| `AWS_REGION` | `us-east-1` | AWS region for S3 |
| `AWS_BUCKET` | — | S3 bucket name |

## Config File (`~/.kmp/config.yaml`)

Legacy self-hosted deployments store management-tool configuration in `~/.kmp/config.yaml`. Existing environments may still use this file, and maintainers can also create it manually when reconstructing an archived self-hosted install.

```yaml
# Example config.yaml
provider: docker
domain: kmp.example.com
channel: release
image: ghcr.io/jhandel/kmp:latest
backup:
  enabled: true
  schedule: "0 3 * * *"
  retention_days: 30
  upload: local          # local, s3, or azure
```

### Config Commands

```bash
kmp config               # View current configuration
```

## Database Configuration

### MySQL (Default)

KMP uses MySQL/MariaDB as its primary database. The bundled VPC stack includes MariaDB with tuned settings (`mariadb.cnf`):

- `innodb_buffer_pool_size = 256M`
- `max_connections = 100`
- Character set: `utf8mb4` with `utf8mb4_unicode_ci` collation

### Bring Your Own Database

To use an external MySQL database, set the `MYSQL_HOST`, `MYSQL_DB_NAME`, `MYSQL_USERNAME`, and `MYSQL_PASSWORD` environment variables to point to your managed database instance.

Requirements:
- MySQL 5.7+ or MariaDB 10.2+
- `utf8mb4` character set support
- A dedicated database and user for KMP

## Email / SMTP Setup

KMP requires SMTP for sending email notifications (password resets, warrant notices, etc.).

**Example for common providers:**

```bash
# Gmail (use App Password, not your Google password)
EMAIL_SMTP_HOST=smtp.gmail.com
EMAIL_SMTP_PORT=587
EMAIL_SMTP_USERNAME=your-email@gmail.com
EMAIL_SMTP_PASSWORD=your-app-password

# Amazon SES
EMAIL_SMTP_HOST=email-smtp.us-east-1.amazonaws.com
EMAIL_SMTP_PORT=587
EMAIL_SMTP_USERNAME=your-ses-smtp-username
EMAIL_SMTP_PASSWORD=your-ses-smtp-password

# Mailgun
EMAIL_SMTP_HOST=smtp.mailgun.org
EMAIL_SMTP_PORT=587
EMAIL_SMTP_USERNAME=postmaster@your-domain.com
EMAIL_SMTP_PASSWORD=your-mailgun-password
```

## Security Settings

| Setting | Recommendation |
|---------|---------------|
| `DEBUG` | Always `false` in production |
| `SECURITY_SALT` | Unique per deployment, at least 64 hex characters |
| SSL/TLS | Always enabled — Caddy (VPC) or platform-managed (PaaS) |
| Database passwords | Randomly generated, at least 24 characters |

## Storage Adapter Configuration

### Local Storage (Default)

Files are stored in the container's filesystem. Suitable for development and small deployments.

### Azure Blob Storage

See the [existing Azure Blob Storage documentation](../8-deployment.md#84-azure-blob-storage-configuration) for detailed setup instructions.

### Amazon S3

Set `DOCUMENT_STORAGE_ADAPTER=s3` and configure the `AWS_*` environment variables listed above.
