package main

import (
	"log"
	"os"

	"github.com/jhandel/KMP/installer/internal/updater"
)

func main() {
	cfg := updater.Config{
		ComposeDir:     envOrDefault("COMPOSE_DIR", "/deploy"),
		AppServiceName: envOrDefault("APP_SERVICE_NAME", "app"),
		HealthURL:      envOrDefault("HEALTH_URL", "http://kmp-app/health"),
		ListenAddr:     envOrDefault("LISTEN_ADDR", ":8484"),
		ImageRepo:      envOrDefault("IMAGE_REPO", "ghcr.io/jhandel/kmp"),
	}

	log.Printf("kmp-updater starting on %s (compose: %s, service: %s)",
		cfg.ListenAddr, cfg.ComposeDir, cfg.AppServiceName)

	server := updater.NewServer(cfg)
	if err := server.Run(); err != nil {
		log.Fatalf("updater server error: %v", err)
	}
}

func envOrDefault(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
