package updater

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

// runUpdate executes the full update sequence:
// 1. Record previous tag
// 2. Pull new image
// 3. Update .env with new tag
// 4. Recreate app container
// 5. Wait for health check
// 6. Auto-rollback on failure
func (s *Server) runUpdate(targetTag string) {
	imageRef := fmt.Sprintf("%s:%s", s.cfg.ImageRepo, targetTag)

	// Determine current tag from .env
	previousTag := s.readCurrentTag()

	s.mu.Lock()
	s.state.TargetTag = targetTag
	s.state.PreviousTag = previousTag
	s.mu.Unlock()

	// Step 1: Pull new image
	s.setState("pulling", fmt.Sprintf("Pulling %s...", imageRef), 10)
	if err := s.dockerCompose("pull", s.cfg.AppServiceName); err != nil {
		s.setState("failed", fmt.Sprintf("Pull failed: %v", err), 0)
		return
	}

	// Step 2: Update .env
	s.setState("stopping", "Updating image tag...", 30)
	if err := s.updateEnvTag(targetTag); err != nil {
		s.setState("failed", fmt.Sprintf("Failed to update .env: %v", err), 0)
		return
	}

	// Step 3: Recreate app container with new image
	s.setState("starting", "Recreating app container...", 50)
	if err := s.dockerCompose("up", "-d", "--no-deps", s.cfg.AppServiceName); err != nil {
		log.Printf("Failed to start new container, rolling back to %s", previousTag)
		s.rollbackTag(previousTag)
		return
	}

	// Step 4: Wait for health check
	s.setState("health_check", "Waiting for health check...", 70)
	if err := s.waitForHealthy(120 * time.Second); err != nil {
		log.Printf("Health check failed, rolling back to %s: %v", previousTag, err)
		s.setState("rolling_back", "Health check failed, rolling back...", 80)
		s.rollbackTag(previousTag)
		return
	}

	s.setState("completed", fmt.Sprintf("Updated to %s", targetTag), 100)
}

// rollbackTag reverts to a previous image tag.
func (s *Server) rollbackTag(tag string) {
	if err := s.updateEnvTag(tag); err != nil {
		s.setState("failed", fmt.Sprintf("Rollback .env update failed: %v", err), 0)
		return
	}
	if err := s.dockerCompose("up", "-d", "--no-deps", s.cfg.AppServiceName); err != nil {
		s.setState("failed", fmt.Sprintf("Rollback container restart failed: %v", err), 0)
		return
	}
	s.setState("failed", fmt.Sprintf("Rolled back to %s after update failure", tag), 0)
}

// dockerCompose runs a docker compose command in the compose directory.
func (s *Server) dockerCompose(args ...string) error {
	if s.dockerComposeFn != nil {
		return s.dockerComposeFn(args...)
	}

	fullArgs := append([]string{"compose"}, args...)
	cmd := exec.Command("docker", fullArgs...)
	cmd.Dir = s.cfg.ComposeDir
	cmd.Env = append(os.Environ(), fmt.Sprintf("COMPOSE_PROJECT_NAME=kmp"))

	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s: %s", err, strings.TrimSpace(string(out)))
	}
	return nil
}

// readCurrentTag reads the current image tag from .env or docker inspect.
func (s *Server) readCurrentTag() string {
	if s.readCurrentTagFn != nil {
		return s.readCurrentTagFn()
	}

	envPath := filepath.Join(s.cfg.ComposeDir, ".env")
	data, err := os.ReadFile(envPath)
	if err != nil {
		return "unknown"
	}

	for _, line := range strings.Split(string(data), "\n") {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "KMP_IMAGE_TAG=") {
			return strings.TrimPrefix(line, "KMP_IMAGE_TAG=")
		}
	}
	return "unknown"
}

// updateEnvTag updates the KMP_IMAGE_TAG in .env to the given tag.
func (s *Server) updateEnvTag(tag string) error {
	if s.updateEnvTagFn != nil {
		return s.updateEnvTagFn(tag)
	}

	envPath := filepath.Join(s.cfg.ComposeDir, ".env")
	data, err := os.ReadFile(envPath)
	if err != nil {
		return fmt.Errorf("reading .env: %w", err)
	}

	lines := strings.Split(string(data), "\n")
	found := false
	for i, line := range lines {
		if strings.HasPrefix(strings.TrimSpace(line), "KMP_IMAGE_TAG=") {
			lines[i] = "KMP_IMAGE_TAG=" + tag
			found = true
			break
		}
	}
	if !found {
		lines = append(lines, "KMP_IMAGE_TAG="+tag)
	}

	return os.WriteFile(envPath, []byte(strings.Join(lines, "\n")), 0644)
}

// waitForHealthy polls the health endpoint until it returns healthy or timeout.
func (s *Server) waitForHealthy(timeout time.Duration) error {
	if s.waitForHealthyFn != nil {
		return s.waitForHealthyFn(timeout)
	}

	client := &http.Client{Timeout: 5 * time.Second}
	deadline := time.Now().Add(timeout)

	for time.Now().Before(deadline) {
		resp, err := client.Get(s.cfg.HealthURL)
		if err == nil {
			defer resp.Body.Close()
			if resp.StatusCode == http.StatusOK {
				var health struct {
					Status string `json:"status"`
					DB     bool   `json:"db"`
					Cache  bool   `json:"cache"`
				}
				if json.NewDecoder(resp.Body).Decode(&health) == nil {
					if health.Status == "ok" && health.DB {
						return nil
					}
				}
			}
		}
		time.Sleep(3 * time.Second)
	}

	return fmt.Errorf("health check timed out after %s", timeout)
}
