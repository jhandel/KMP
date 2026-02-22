package providers

import (
	"fmt"
	"io"
	"os/exec"
	"regexp"
	"strings"

	"github.com/jhandel/KMP/installer/internal/config"
	"github.com/jhandel/KMP/installer/internal/health"
)

// RailwayProvider deploys KMP to Railway with managed MySQL.
type RailwayProvider struct {
	cfg *config.Deployment
}

// NewRailwayProvider creates a new Railway provider.
func NewRailwayProvider(cfg *config.Deployment) *RailwayProvider {
	return &RailwayProvider{cfg: cfg}
}

func (r *RailwayProvider) Name() string { return "Railway" }

func (r *RailwayProvider) Detect() bool {
	return commandExists("railway")
}

func (r *RailwayProvider) Prerequisites() []Prerequisite {
	cliInstalled := commandExists("railway")

	authenticated := false
	if cliInstalled {
		_, err := runCommand("railway", "whoami")
		authenticated = err == nil
	}

	return []Prerequisite{
		{
			Name:        "Railway CLI",
			Description: "railway CLI must be installed",
			Met:         cliInstalled,
			InstallHint: "Install: npm install -g @railway/cli",
		},
		{
			Name:        "Railway authentication",
			Description: "Must be logged in to Railway",
			Met:         authenticated,
			InstallHint: "Run: railway login",
		},
	}
}

func (r *RailwayProvider) Install(cfg *DeployConfig) error {
	projectName := railwayProjectName(cfg)
	useManagedMySQL := strings.TrimSpace(cfg.DatabaseDSN) == ""
	useManagedRedis := cfg.CacheEngine == "redis" && strings.TrimSpace(cfg.RedisURL) == ""

	// Initialize/link project.
	if err := runRailwayVariants(
		[][]string{
			{"init", "--name", projectName, "--yes"},
			{"project", "create", projectName},
		},
		"failed to initialize Railway project",
	); err != nil {
		return err
	}

	// Provision managed MySQL service when no external DATABASE_URL was provided.
	if useManagedMySQL {
		if err := runRailwayVariants(
			[][]string{
				{"add", "mysql", "--yes"},
				{"service", "create", "mysql", "--yes"},
			},
			"failed to provision Railway MySQL service",
		); err != nil {
			return err
		}
	}
	if useManagedRedis {
		if err := runRailwayVariants(
			[][]string{
				{"add", "redis", "--yes"},
				{"service", "create", "redis", "--yes"},
			},
			"failed to provision Railway Redis service",
		); err != nil {
			return err
		}
	}

	requireHTTPS := "true"
	baseURL := "https://localhost"
	if cfg.Domain == "" || cfg.Domain == "localhost" {
		requireHTTPS = "false"
		baseURL = "http://localhost"
	} else {
		baseURL = "https://" + cfg.Domain
	}

	image := fmt.Sprintf("%s:%s", cfg.Image, cfg.ImageTag)
	cacheEngine := "apcu"
	if cfg.CacheEngine == "redis" {
		cacheEngine = "redis"
	}

	variables := []string{
		"APP_NAME=KMP",
		"DEBUG=false",
		"REQUIRE_HTTPS=" + requireHTTPS,
		"APP_FULL_BASE_URL=" + baseURL,
		"KMP_DEPLOY_PROVIDER=railway",
		"SECURITY_SALT=" + generateRandomString(32),
		"CACHE_ENGINE=" + cacheEngine,
	}
	if useManagedMySQL {
		variables = append(
			variables,
			"MYSQL_HOST=${{MySQL.MYSQLHOST}}",
			"MYSQL_DB_NAME=${{MySQL.MYSQLDATABASE}}",
			"MYSQL_USERNAME=${{MySQL.MYSQLUSER}}",
			"MYSQL_PASSWORD=${{MySQL.MYSQLPASSWORD}}",
			"DATABASE_URL=mysql://${{MySQL.MYSQLUSER}}:${{MySQL.MYSQLPASSWORD}}@${{MySQL.MYSQLHOST}}:${{MySQL.MYSQLPORT}}/${{MySQL.MYSQLDATABASE}}",
		)
	} else {
		variables = append(variables, "DATABASE_URL="+cfg.DatabaseDSN)
		if cfg.MySQLSSL {
			variables = append(variables, "MYSQL_SSL=true")
		}
	}
	if cfg.CacheEngine == "redis" && cfg.RedisURL != "" {
		variables = append(variables, "REDIS_URL="+cfg.RedisURL)
	} else if useManagedRedis {
		variables = append(variables, "REDIS_URL=redis://${{Redis.REDISUSER}}:${{Redis.REDISPASSWORD}}@${{Redis.REDISHOST}}:${{Redis.REDISPORT}}")
	}

	varArgs := append([]string{"variables", "set"}, variables...)
	if _, err := runCommand("railway", varArgs...); err != nil {
		return fmt.Errorf("failed setting Railway environment variables: %w", err)
	}

	// Deploy pre-built image.
	if err := runRailwayVariants(
		[][]string{
			{"up", "--detach", "--image", image},
			{"up", "--image", image},
			{"up", "--detach"},
			{"up"},
		},
		"failed to deploy Railway service",
	); err != nil {
		return err
	}

	return r.saveDeployment(cfg)
}

func (r *RailwayProvider) Update(version string) error {
	if r.cfg == nil {
		return fmt.Errorf("no existing Railway deployment config found")
	}

	imageRepo := r.cfg.Image
	if imageRepo == "" {
		imageRepo = "ghcr.io/jhandel/kmp"
	}
	image := fmt.Sprintf("%s:%s", imageRepo, version)

	if err := runRailwayVariants(
		[][]string{
			{"up", "--detach", "--image", image},
			{"up", "--image", image},
			{"up", "--detach"},
			{"up"},
		},
		"failed to update Railway deployment",
	); err != nil {
		return err
	}

	appCfg, err := config.Load()
	if err != nil {
		return err
	}
	if dep, ok := appCfg.Deployments["default"]; ok {
		dep.ImageTag = version
		if dep.Image == "" {
			dep.Image = imageRepo
		}
		return appCfg.Save()
	}

	return nil
}

func (r *RailwayProvider) Status() (*Status, error) {
	if r.cfg == nil {
		return nil, fmt.Errorf("no Railway deployment config found")
	}

	out, err := runCommand("railway", "status")
	if err != nil {
		return nil, fmt.Errorf("failed to read Railway status: %w", err)
	}

	running := false
	lower := strings.ToLower(out)
	for _, marker := range []string{"running", "deployed", "active", "healthy"} {
		if strings.Contains(lower, marker) {
			running = true
			break
		}
	}

	st := &Status{
		Running:  running,
		Version:  r.cfg.ImageTag,
		Channel:  r.cfg.Channel,
		Domain:   r.cfg.Domain,
		Provider: "Railway",
	}

	domain := strings.TrimSpace(r.cfg.Domain)
	if domain != "" {
		scheme := "https"
		if domain == "localhost" {
			scheme = "http"
		}
		healthResp, healthErr := health.Check(fmt.Sprintf("%s://%s", scheme, domain))
		if healthErr == nil {
			st.Healthy = healthResp.IsHealthy()
			st.DBConnected = healthResp.DB
			st.CacheOK = healthResp.Cache
			if healthResp.Version != "" {
				st.Version = healthResp.Version
			}
		}
	}

	return st, nil
}

func (r *RailwayProvider) Logs(follow bool) (io.ReadCloser, error) {
	args := []string{"logs"}
	if follow {
		args = append(args, "--follow")
	}

	cmd := exec.Command("railway", args...)
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return nil, err
	}
	cmd.Stderr = cmd.Stdout

	if err := cmd.Start(); err != nil {
		return nil, fmt.Errorf("starting railway logs: %w", err)
	}

	return stdout, nil
}

func (r *RailwayProvider) Backup() (*BackupResult, error) {
	// TODO: Implement Railway MySQL backup via plugin or dump
	return nil, fmt.Errorf("%s: not yet implemented — coming in a future release", r.Name())
}

func (r *RailwayProvider) Restore(backupID string) error {
	// TODO: Restore Railway MySQL from backup
	return fmt.Errorf("%s: not yet implemented — coming in a future release", r.Name())
}

func (r *RailwayProvider) Rollback() error {
	// TODO: Redeploy previous version via railway up
	return fmt.Errorf("%s: not yet implemented — coming in a future release", r.Name())
}

func (r *RailwayProvider) Destroy() error {
	// TODO: railway delete to tear down the project
	return fmt.Errorf("%s: not yet implemented — coming in a future release", r.Name())
}

func runRailwayVariants(variants [][]string, context string) error {
	var attempts []string
	for _, args := range variants {
		if len(args) == 0 {
			continue
		}
		if _, err := runCommand("railway", args...); err == nil {
			return nil
		} else {
			attempts = append(attempts, fmt.Sprintf("railway %s => %v", strings.Join(args, " "), err))
		}
	}

	if len(attempts) == 0 {
		return fmt.Errorf("%s: no command variants available", context)
	}

	return fmt.Errorf("%s:\n- %s", context, strings.Join(attempts, "\n- "))
}

var invalidRailwayNameChars = regexp.MustCompile(`[^a-z0-9-]`)

func railwayProjectName(cfg *DeployConfig) string {
	name := strings.ToLower(strings.TrimSpace(cfg.Name))
	if name == "" || name == "default" {
		name = strings.ToLower(strings.TrimSpace(cfg.Domain))
	}
	if name == "" || name == "localhost" {
		name = "kmp"
	}

	name = strings.ReplaceAll(name, ".", "-")
	name = invalidRailwayNameChars.ReplaceAllString(name, "-")
	name = strings.Trim(name, "-")
	if name == "" {
		name = "kmp"
	}
	if len(name) > 58 {
		name = strings.Trim(name[:58], "-")
	}

	return name
}

func (r *RailwayProvider) saveDeployment(cfg *DeployConfig) error {
	appCfg, err := config.Load()
	if err != nil {
		return err
	}

	name := cfg.Name
	if name == "" {
		name = "default"
	}

	storageConfig := make(map[string]string, len(cfg.StorageConfig))
	for k, v := range cfg.StorageConfig {
		storageConfig[k] = v
	}
	storageConfig["railway_project"] = railwayProjectName(cfg)

	appCfg.Deployments[name] = &config.Deployment{
		Provider:        "railway",
		Channel:         cfg.Channel,
		Domain:          cfg.Domain,
		Image:           cfg.Image,
		ImageTag:        cfg.ImageTag,
		DatabaseDSN:     cfg.DatabaseDSN,
		MySQLSSL:        cfg.MySQLSSL,
		LocalDBType:     cfg.LocalDBType,
		StorageType:     cfg.StorageType,
		StorageConfig:   storageConfig,
		CacheEngine:     cfg.CacheEngine,
		RedisURL:        cfg.RedisURL,
		BackupEnabled:   cfg.BackupConfig.Enabled,
		BackupSchedule:  cfg.BackupConfig.Schedule,
		BackupRetention: cfg.BackupConfig.RetentionDays,
	}

	return appCfg.Save()
}
