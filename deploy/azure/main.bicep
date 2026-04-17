// =============================================================================
// KMP Nightly — Azure infrastructure (Postgres + encrypted-backup seeding)
//
// Deploys:
//   - Log Analytics workspace             (Container Apps logs)
//   - Azure Container Registry            (Basic SKU; nightly image mirror from GHCR)
//   - User-Assigned Managed Identity      (ACR pull + Key Vault read)
//   - Azure Key Vault                     (RBAC; app secrets incl. backup encryption key)
//   - Azure Database for PostgreSQL Flex  (B1ms, PG 16, public w/ Allow Azure services)
//   - Container Apps Environment          (Consumption)
//   - Container App: <prefix>-web         (ingress external, scale 1→3)
//   - Container Apps Job: migrate         (manual, one-shot)
//   - Container Apps Job: reset           (manual, full drop + seed-from-backup)
//   - Container Apps Job: queue           (cron */5 minutes)
//   - Container Apps Job: sync            (cron 15 07 * * *)
//
// Seeding model: the image ships /opt/kmp/seed/nightly-seed.kmpbackup (an
// encrypted dev-data snapshot produced by `deploy/azure/seed/bake-seed.sh`).
// The reset job decrypts it with BACKUP_ENCRYPTION_KEY (fetched from Key
// Vault) and restores via `bin/cake backup restore`. See deploy/azure/seed/
// for the full workflow.
// =============================================================================

@description('Azure region for all resources.')
param location string = resourceGroup().location

@minLength(3)
@maxLength(11)
@description('Short lowercase-alphanumeric prefix used to name all resources.')
param namePrefix string = 'kmpnightly'

@description('Container image reference (without tag) in the internal ACR. Typically "<acr-login-server>/kmp".')
param imageRepository string

@description('Image tag to deploy (e.g. "nightly" or "nightly-2026-04-17").')
param imageTag string = 'nightly'

@description('Postgres admin login name.')
param postgresAdminUser string = 'kmpadmin'

@secure()
@description('Postgres admin password (also used as the application credential for nightly — no separate app role).')
param postgresAdminPassword string

@description('Postgres application database name.')
param postgresDatabaseName string = 'kmp_nightly'

@secure()
@description('CakePHP Security.salt value (generate with `openssl rand -hex 32`).')
param securitySalt string

@secure()
@description('Backup encryption key. Must match the key used to bake the nightly .kmpbackup file. Keep this in sync with deploy/azure/seed/.')
param backupEncryptionKey string

@description('SMTP host for outbound mail (e.g. Mailpit reused from UAT).')
param emailSmtpHost string

@description('SMTP port for outbound mail.')
param emailSmtpPort int = 1025

@description('SMTP username (leave empty for unauthenticated Mailpit).')
param emailSmtpUsername string = ''

@secure()
@description('SMTP password (leave empty for unauthenticated Mailpit).')
param emailSmtpPassword string = ''

@description('Whether SMTP uses TLS.')
param emailSmtpTls bool = false

@description('From address for outgoing mail.')
param emailFrom string

@description('Object ID of the principal that should have Key Vault Secrets Officer access for initial secret population (usually the deployer).')
param deployerPrincipalId string

// =============================================================================
// Names (derived)
// =============================================================================
@description('ACR name. Must be pre-computed by the bootstrap script so that the image can be imported before the rest of the deployment runs.')
param acrName string

var suffix = uniqueString(resourceGroup().id)
var lawName = '${namePrefix}-law'
var kvName = take('${namePrefix}-kv-${take(suffix, 6)}', 24)
var pgName = '${namePrefix}-pg-${take(suffix, 6)}'
var uamiName = '${namePrefix}-id'
var acaEnvName = '${namePrefix}-acaenv'
var webAppName = '${namePrefix}-web'
var migrateJobName = '${namePrefix}-migrate'
var queueJobName = '${namePrefix}-queue'
var syncJobName = '${namePrefix}-sync'
var resetJobName = '${namePrefix}-reset'

// =============================================================================
// Log Analytics workspace
// =============================================================================
resource law 'Microsoft.OperationalInsights/workspaces@2023-09-01' = {
  name: lawName
  location: location
  properties: {
    sku: { name: 'PerGB2018' }
    retentionInDays: 30
  }
}

// =============================================================================
// Azure Container Registry (Basic)
// =============================================================================
resource acr 'Microsoft.ContainerRegistry/registries@2023-11-01-preview' = {
  name: acrName
  location: location
  sku: { name: 'Basic' }
  properties: {
    adminUserEnabled: false
  }
}

// =============================================================================
// User-Assigned Managed Identity (shared by web + jobs)
// =============================================================================
resource uami 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: uamiName
  location: location
}

// AcrPull role on the ACR
resource acrPullRole 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(acr.id, uami.id, 'acrpull')
  scope: acr
  properties: {
    principalId: uami.properties.principalId
    principalType: 'ServicePrincipal'
    // AcrPull
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', '7f951dda-4ed3-4680-a7ca-43fe172d538d')
  }
}

// =============================================================================
// Key Vault (RBAC mode) with secrets
// =============================================================================
resource kv 'Microsoft.KeyVault/vaults@2023-07-01' = {
  name: kvName
  location: location
  properties: {
    tenantId: subscription().tenantId
    sku: { family: 'A', name: 'standard' }
    enableRbacAuthorization: true
    enableSoftDelete: true
    softDeleteRetentionInDays: 7
    enablePurgeProtection: null
    publicNetworkAccess: 'Enabled'
  }
}

// UAMI -> Key Vault Secrets User (read)
resource kvSecretsUserToUami 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(kv.id, uami.id, 'secretsuser')
  scope: kv
  properties: {
    principalId: uami.properties.principalId
    principalType: 'ServicePrincipal'
    // Key Vault Secrets User
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', '4633458b-17de-408a-b874-0445c86b69e6')
  }
}

// Deployer -> Key Vault Secrets Officer (read/write, for subsequent rotations)
resource kvSecretsOfficerToDeployer 'Microsoft.Authorization/roleAssignments@2022-04-01' = if (!empty(deployerPrincipalId)) {
  name: guid(kv.id, deployerPrincipalId, 'secretsofficer')
  scope: kv
  properties: {
    principalId: deployerPrincipalId
    principalType: 'User'
    // Key Vault Secrets Officer
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', 'b86a8fe4-44ce-4948-aee5-eccb2c155cd7')
  }
}

// =============================================================================
// Azure Database for PostgreSQL — Flexible Server (B1ms, PG 16)
// Nightly uses admin credentials directly (no separate app role).
// =============================================================================
resource pg 'Microsoft.DBforPostgreSQL/flexibleServers@2024-08-01' = {
  name: pgName
  location: location
  sku: {
    name: 'Standard_B1ms'
    tier: 'Burstable'
  }
  properties: {
    version: '16'
    administratorLogin: postgresAdminUser
    administratorLoginPassword: postgresAdminPassword
    storage: {
      storageSizeGB: 32
      autoGrow: 'Enabled'
    }
    backup: {
      backupRetentionDays: 7
      geoRedundantBackup: 'Disabled'
    }
    highAvailability: { mode: 'Disabled' }
    network: {
      publicNetworkAccess: 'Enabled'
    }
  }
}

// Firewall rule: allow all Azure services (0.0.0.0 - 0.0.0.0 is the Azure "special" rule)
resource pgFwAzure 'Microsoft.DBforPostgreSQL/flexibleServers/firewallRules@2024-08-01' = {
  parent: pg
  name: 'AllowAzureServices'
  properties: {
    startIpAddress: '0.0.0.0'
    endIpAddress: '0.0.0.0'
  }
}

// Application database
resource pgDb 'Microsoft.DBforPostgreSQL/flexibleServers/databases@2024-08-01' = {
  parent: pg
  name: postgresDatabaseName
  properties: {
    charset: 'UTF8'
    collation: 'en_US.utf8'
  }
}

// =============================================================================
// Key Vault secrets (after Postgres resource so we can compose DATABASE_URL).
// DATABASE_URL is stored as a single secret so the container entrypoint can
// consume it directly via secretRef — no in-container composition needed.
// =============================================================================
var databaseUrlValue = 'postgres://${postgresAdminUser}:${postgresAdminPassword}@${pg.properties.fullyQualifiedDomainName}:5432/${postgresDatabaseName}?sslmode=require'

resource secretSecuritySalt 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = {
  parent: kv
  name: 'security-salt'
  properties: { value: securitySalt }
}
resource secretDatabaseUrl 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = {
  parent: kv
  name: 'database-url'
  properties: { value: databaseUrlValue }
}
resource secretPostgresAdmin 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = {
  parent: kv
  name: 'postgres-admin-password'
  properties: { value: postgresAdminPassword }
}
resource secretBackupKey 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = {
  parent: kv
  name: 'backup-encryption-key'
  properties: { value: backupEncryptionKey }
}
resource secretSmtpPassword 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = {
  parent: kv
  name: 'email-smtp-password'
  properties: { value: empty(emailSmtpPassword) ? 'unused' : emailSmtpPassword }
}

// =============================================================================
// Container Apps Environment
// =============================================================================
resource acaEnv 'Microsoft.App/managedEnvironments@2024-03-01' = {
  name: acaEnvName
  location: location
  properties: {
    appLogsConfiguration: {
      destination: 'log-analytics'
      logAnalyticsConfiguration: {
        customerId: law.properties.customerId
        sharedKey: law.listKeys().primarySharedKey
      }
    }
    zoneRedundant: false
  }
}

// =============================================================================
// Common container env for web + jobs
// =============================================================================
var commonEnv = [
  // entrypoint.prod.sh parses DATABASE_URL to auto-detect engine + compose
  // config/app_local.php. postgres:// prefix triggers Postgres behaviour
  // (pg_isready probe, sslmode=require honoured by the PDO driver).
  { name: 'DATABASE_URL', secretRef: 'database-url' }
  { name: 'SECURITY_SALT', secretRef: 'security-salt' }
  { name: 'BACKUP_ENCRYPTION_KEY', secretRef: 'backup-encryption-key' }
  { name: 'DEBUG', value: 'false' }
  { name: 'REQUIRE_HTTPS', value: 'true' }
  { name: 'TRUST_PROXY', value: 'true' }
  { name: 'EMAIL_DRIVER', value: 'smtp' }
  { name: 'EMAIL_SMTP_HOST', value: emailSmtpHost }
  { name: 'EMAIL_SMTP_PORT', value: string(emailSmtpPort) }
  { name: 'EMAIL_SMTP_USERNAME', value: emailSmtpUsername }
  { name: 'EMAIL_SMTP_PASSWORD', secretRef: 'email-smtp-password' }
  { name: 'EMAIL_SMTP_TLS', value: string(emailSmtpTls) }
  { name: 'EMAIL_FROM', value: emailFrom }
  { name: 'RELEASE_CHANNEL', value: 'nightly' }
]

// Secrets (pulled from Key Vault via UAMI)
var commonSecrets = [
  {
    name: 'database-url'
    keyVaultUrl: secretDatabaseUrl.properties.secretUri
    identity: uami.id
  }
  {
    name: 'security-salt'
    keyVaultUrl: secretSecuritySalt.properties.secretUri
    identity: uami.id
  }
  {
    name: 'backup-encryption-key'
    keyVaultUrl: secretBackupKey.properties.secretUri
    identity: uami.id
  }
  {
    name: 'email-smtp-password'
    keyVaultUrl: secretSmtpPassword.properties.secretUri
    identity: uami.id
  }
]

var commonRegistries = [
  {
    server: acr.properties.loginServer
    identity: uami.id
  }
]

var fullImage = '${imageRepository}:${imageTag}'

// =============================================================================
// Container App — web
// =============================================================================
resource web 'Microsoft.App/containerApps@2024-03-01' = {
  name: webAppName
  location: location
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: { '${uami.id}': {} }
  }
  properties: {
    managedEnvironmentId: acaEnv.id
    configuration: {
      activeRevisionsMode: 'Single'
      ingress: {
        external: true
        targetPort: 80
        transport: 'auto'
        allowInsecure: false
        traffic: [
          { latestRevision: true, weight: 100 }
        ]
      }
      registries: commonRegistries
      secrets: commonSecrets
    }
    template: {
      containers: [
        {
          name: 'web'
          image: fullImage
          resources: { cpu: json('0.5'), memory: '1Gi' }
          env: commonEnv
          probes: [
            {
              type: 'Liveness'
              httpGet: { path: '/health', port: 80 }
              initialDelaySeconds: 30
              periodSeconds: 30
              failureThreshold: 3
            }
            {
              type: 'Readiness'
              httpGet: { path: '/health', port: 80 }
              initialDelaySeconds: 10
              periodSeconds: 10
              failureThreshold: 3
            }
          ]
        }
      ]
      scale: {
        minReplicas: 1
        maxReplicas: 3
        rules: [
          {
            name: 'http'
            http: { metadata: { concurrentRequests: '50' } }
          }
        ]
      }
    }
  }
  dependsOn: [
    acrPullRole
    kvSecretsUserToUami
    pgDb
  ]
}

// =============================================================================
// Common job env — disable cron + migrations inside jobs
// =============================================================================
var jobEnvQueueAndSync = concat(commonEnv, [
  { name: 'KMP_SKIP_CRON', value: 'true' }
  { name: 'KMP_SKIP_MIGRATIONS', value: 'true' }
])

var jobEnvMigrate = concat(commonEnv, [
  { name: 'KMP_SKIP_CRON', value: 'true' }
  // migrate job keeps migrations enabled — that's the whole point
])

// Reset job: force local backup storage adapter so the restore reads the
// bundled .kmpbackup file from ${ROOT}/backups/ instead of Azure Blob.
var jobEnvReset = concat(commonEnv, [
  { name: 'KMP_SKIP_CRON', value: 'true' }
  // Reset script drives its own migrations; skip the entrypoint's auto-migrate
  // so it can't race with the explicit resetDatabase.
  { name: 'KMP_SKIP_MIGRATIONS', value: 'true' }
  // Force local adapter regardless of Documents.storage defaults.
  { name: 'DOCUMENT_STORAGE_ADAPTER', value: 'local' }
])

// =============================================================================
// Container Apps Job — migrate (manual, one-shot)
// =============================================================================
resource jobMigrate 'Microsoft.App/jobs@2024-03-01' = {
  name: migrateJobName
  location: location
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: { '${uami.id}': {} }
  }
  properties: {
    environmentId: acaEnv.id
    configuration: {
      triggerType: 'Manual'
      replicaTimeout: 1800
      replicaRetryLimit: 1
      manualTriggerConfig: {
        parallelism: 1
        replicaCompletionCount: 1
      }
      registries: commonRegistries
      secrets: commonSecrets
    }
    template: {
      containers: [
        {
          name: 'migrate'
          image: fullImage
          resources: { cpu: json('0.5'), memory: '1Gi' }
          env: jobEnvMigrate
          // Run entrypoint (which applies migrations) then exit cleanly.
          // entrypoint.prod.sh finishes with `exec "$@"`, so passing `true`
          // lets it complete migrations and then succeed.
          command: [ '/usr/local/bin/docker-entrypoint.sh' ]
          args: [ '/bin/true' ]
        }
      ]
    }
  }
  dependsOn: [
    acrPullRole
    kvSecretsUserToUami
    pgDb
  ]
}

// =============================================================================
// Container Apps Job — reset (manual, full drop + restore-from-backup)
// Mirrors reset_dev_database.sh but uses the encrypted backup baked into the
// image instead of a MariaDB SQL dump — so the same file works on any engine.
// =============================================================================
resource jobReset 'Microsoft.App/jobs@2024-03-01' = {
  name: resetJobName
  location: location
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: { '${uami.id}': {} }
  }
  properties: {
    environmentId: acaEnv.id
    configuration: {
      triggerType: 'Manual'
      replicaTimeout: 3600
      replicaRetryLimit: 0
      manualTriggerConfig: {
        parallelism: 1
        replicaCompletionCount: 1
      }
      registries: commonRegistries
      secrets: commonSecrets
    }
    template: {
      containers: [
        {
          name: 'reset'
          image: fullImage
          resources: { cpu: json('1.0'), memory: '2Gi' }
          env: jobEnvReset
          command: [ '/usr/local/bin/docker-entrypoint.sh' ]
          args: [ '/opt/kmp/reset-and-seed.sh' ]
        }
      ]
    }
  }
  dependsOn: [
    acrPullRole
    kvSecretsUserToUami
    pgDb
  ]
}

// =============================================================================
// Container Apps Job — queue (every 5 min)
// =============================================================================
resource jobQueue 'Microsoft.App/jobs@2024-03-01' = {
  name: queueJobName
  location: location
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: { '${uami.id}': {} }
  }
  properties: {
    environmentId: acaEnv.id
    configuration: {
      triggerType: 'Schedule'
      replicaTimeout: 600
      replicaRetryLimit: 1
      scheduleTriggerConfig: {
        cronExpression: '*/5 * * * *'
        parallelism: 1
        replicaCompletionCount: 1
      }
      registries: commonRegistries
      secrets: commonSecrets
    }
    template: {
      containers: [
        {
          name: 'queue'
          image: fullImage
          resources: { cpu: json('0.5'), memory: '1Gi' }
          env: jobEnvQueueAndSync
          command: [ '/usr/local/bin/docker-entrypoint.sh' ]
          args: [ 'bin/cake', 'queue', 'run', '--max-runtime', '270', '-q' ]
        }
      ]
    }
  }
  dependsOn: [
    acrPullRole
    kvSecretsUserToUami
    pgDb
  ]
}

// =============================================================================
// Container Apps Job — sync active window statuses (nightly 07:15 UTC)
// =============================================================================
resource jobSync 'Microsoft.App/jobs@2024-03-01' = {
  name: syncJobName
  location: location
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: { '${uami.id}': {} }
  }
  properties: {
    environmentId: acaEnv.id
    configuration: {
      triggerType: 'Schedule'
      replicaTimeout: 900
      replicaRetryLimit: 1
      scheduleTriggerConfig: {
        cronExpression: '15 7 * * *'
        parallelism: 1
        replicaCompletionCount: 1
      }
      registries: commonRegistries
      secrets: commonSecrets
    }
    template: {
      containers: [
        {
          name: 'sync'
          image: fullImage
          resources: { cpu: json('0.5'), memory: '1Gi' }
          env: jobEnvQueueAndSync
          command: [ '/usr/local/bin/docker-entrypoint.sh' ]
          args: [ 'bin/cake', 'sync_active_window_statuses' ]
        }
      ]
    }
  }
  dependsOn: [
    acrPullRole
    kvSecretsUserToUami
    pgDb
  ]
}

// =============================================================================
// Outputs (consumed by bootstrap + deploy workflow)
// =============================================================================
output acrLoginServer string = acr.properties.loginServer
output acrName string = acr.name
output postgresFqdn string = pg.properties.fullyQualifiedDomainName
output postgresAdminUser string = postgresAdminUser
output postgresDatabaseName string = postgresDatabaseName
output keyVaultName string = kv.name
output uamiId string = uami.id
output uamiPrincipalId string = uami.properties.principalId
output webAppFqdn string = web.properties.configuration.ingress.fqdn
output webAppName string = web.name
output migrateJobName string = jobMigrate.name
output queueJobName string = jobQueue.name
output syncJobName string = jobSync.name
output resetJobName string = jobReset.name
output acaEnvName string = acaEnv.name
