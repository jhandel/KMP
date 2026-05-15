
# KingdomMangementPortal
![CodeRabbit Pull Request Reviews](https://img.shields.io/coderabbit/prs/github/jhandel/KMP?utm_source=oss&utm_medium=github&utm_campaign=jhandel%2FKMP&labelColor=171717&color=FF570A&link=https%3A%2F%2Fcoderabbit.ai&label=CodeRabbit+Reviews)

Membership management system for SCA Kingdoms.

Please review the wiki for solution details https://github.com/Ansteorra/KMP/wiki

## Deployment

KMP is moving to a managed multi-tenant hosting model. The standalone installer is retired for new environments and kept in the repository as archived reference only.

- 📖 [Archived self-hosted deployment reference](docs/deployment/README.md)
- 🛠️ [Legacy installer implementation notes](installer/README.md)

## Development Login Credentials

The seeded platform admin account for `/platform-admin` is:

- **Email:** `platform-admin@localhost.test`
- **Password:** `TestPassword`
- **Role:** Break-glass platform administrator

Platform admin accounts are separate from tenant users. Platform-admin login, MFA, and privileged-action verification emails are captured in Mailpit during development.

The seeded tenant super user account is:

- **Email:** `admin@amp.ansteorra.org`
- **Password:** `TestPassword`
- **Role:** System super user

All seeded dev tenant users use the password `TestPassword`:

| Email | Seeded role |
| --- | --- |
| `admin@amp.ansteorra.org` | System super user |
| `agatha@ampdemo.com` | Local MoAS |
| `bryce@ampdemo.com` | Local Seneschal |
| `caroline@ampdemo.com` | Regional Seneschal |
| `devon@ampdemo.com` | Regional Armored |
| `eirik@ampdemo.com` | Kingdom Seneschal |
| `garun@ampdemo.com` | Kingdom Rapier |
| `haylee@ampdemo.com` | Kingdom MoAS |
| `iris@ampdemo.com` | Basic User |
| `jael@ampdemo.com` | Principality Coronet |
| `kal@ampdemo.com` | Local Landed Nobility with a Canton |
| `forest@ampdemo.com` | Crown |
| `leonard@ampdemo.com` | Local Landed Nobility with Stronghold |
| `mel@ampdemo.com` | Local Exchequer and Kingdom Social Media |

Development email, including MFA and verification codes, is delivered to Mailpit instead of real inboxes. After an action sends a code, open the Mailpit UI at http://localhost:8025 and use the newest message for the login email address. The local app runs at http://localhost:8080 when started with `./dev-up.sh`.

If you seed or reset another platform admin with `bin/cake platform_admin:seed` or `bin/cake platform_admin:reset_password`, the command prints the initial password and subsequent verification emails are captured in Mailpit.

## Utility Scripts

### fix_permissions.sh
Fixes file permissions for Apache web server access. Run this if you encounter permission errors with logs, tmp, or images directories:
```bash
./fix_permissions.sh
```

### reset_dev_database.sh
Resets the development database to a clean state with seed data:
```bash
./reset_dev_database.sh
```

### load_test.sh
Runs performance sizing benchmarks (route latency, concurrency, and DB query profile) against the application:
```bash
./load_test.sh
```

Optional environment overrides:
```bash
KMP_BASE_URL=http://127.0.0.1:8080 \
KMP_LOGIN_EMAIL=admin@amp.ansteorra.org \
KMP_LOGIN_PASSWORD=TestPassword \
KMP_CONCURRENCY_LEVELS=1,5,10,20 \
KMP_CPU_TARGET_UTIL_PCT=70 \
KMP_MEMORY_TARGET_UTIL_PCT=80 \
./load_test.sh
```

### security-checker.sh
Runs security checks on the application:
```bash
./security-checker.sh
```

### create_erd.sh
Generates Entity Relationship Diagrams for the database schema:
```bash
./create_erd.sh
```

### make_amp_seed_db.sh
Creates a seed database for the application:
```bash
./make_amp_seed_db.sh
```

### merge_from_upstream.sh
Merges changes from the upstream repository:
```bash
./merge_from_upstream.sh
```
