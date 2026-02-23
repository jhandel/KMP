package registry

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"
)

// Tag represents a container image tag from the registry.
type Tag struct {
	Name    string
	Channel string
}

// GHCRClient queries the GitHub Container Registry OCI Distribution API.
type GHCRClient struct {
	Image      string // e.g. "ghcr.io/jhandel/kmp"
	HTTPClient *http.Client
}

// NewGHCRClient creates a client for querying GHCR tags.
func NewGHCRClient() *GHCRClient {
	return &GHCRClient{
		Image:      defaultImage,
		HTTPClient: &http.Client{Timeout: 10 * time.Second},
	}
}

// GetTags fetches available image tags from the GHCR OCI Distribution API.
func (g *GHCRClient) GetTags() ([]Tag, error) {
	parts := strings.SplitN(g.Image, "/", 2)
	if len(parts) != 2 {
		return nil, fmt.Errorf("invalid image reference: %s", g.Image)
	}
	host := parts[0]
	path := parts[1]

	url := fmt.Sprintf("https://%s/v2/%s/tags/list", host, path)
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")

	resp, err := g.HTTPClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("GHCR tag fetch failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("GHCR API returned %d", resp.StatusCode)
	}

	var result struct {
		Tags []string `json:"tags"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return nil, err
	}

	var tags []Tag
	for _, t := range result.Tags {
		// Filter out non-app tags: base images, digests, installer/updater releases
		if strings.HasPrefix(t, "php") || strings.HasPrefix(t, "sha256-") ||
			strings.HasPrefix(t, "sha-") || strings.HasPrefix(t, "installer-") ||
			strings.HasPrefix(t, "updater-") {
			continue
		}
		tags = append(tags, Tag{
			Name:    t,
			Channel: classifyTag(t),
		})
	}

	return tags, nil
}

// GetLatestTagByChannel returns the most recent tag for a channel from GHCR.
func (g *GHCRClient) GetLatestTagByChannel(channel string) (string, error) {
	tags, err := g.GetTags()
	if err != nil {
		return "", err
	}

	// For "release" channel, prefer "latest" tag or highest semver
	for _, t := range tags {
		if t.Channel == channel {
			return t.Name, nil
		}
	}
	return "", fmt.Errorf("no tags found for channel %q", channel)
}

func classifyTag(tag string) string {
	lower := strings.ToLower(tag)
	if strings.Contains(lower, "nightly") {
		return "nightly"
	}
	if strings.Contains(lower, "dev") {
		return "dev"
	}
	if strings.Contains(lower, "beta") || strings.Contains(lower, "rc") || strings.Contains(lower, "alpha") {
		return "beta"
	}
	if tag == "latest" {
		return "release"
	}
	return "release"
}
