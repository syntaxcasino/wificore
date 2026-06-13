package ssh

import (
	"fmt"
	"strings"
	"time"

	gossh "golang.org/x/crypto/ssh"
)

// Client provides binary-API-first router transport with SSH fallback.
type Client struct {
	host     string
	port     int
	username string
	password string
	timeout  time.Duration

	binary *binaryAPIClient
	ssh    *gossh.Client

	connected   bool
	stagedFiles map[string]string
}

// NewClient creates a new router transport client.
func NewClient(host string, port int, username, password string, timeout time.Duration) *Client {
	return &Client{
		host:        host,
		port:        port,
		username:    username,
		password:    password,
		timeout:     timeout,
		stagedFiles: make(map[string]string),
	}
}

// Connect establishes a router connection. Binary API is attempted first.
func (c *Client) Connect() error {
	if c.binary == nil {
		binary := newBinaryAPIClient(c.host, c.binaryPortCandidates(), c.username, c.password, c.timeout)
		if err := binary.connect(); err == nil {
			c.binary = binary
			c.connected = true
			return nil
		}
	}

	if err := c.connectSSH(); err != nil {
		return err
	}
	c.connected = true
	return nil
}

func (c *Client) binaryPortCandidates() []int {
	candidates := []int{8728, 8729}
	if c.port > 0 && c.port != 22 && c.port != 80 && c.port != 443 && c.port != 8291 {
		candidates = append([]int{c.port}, candidates...)
	}

	seen := map[int]struct{}{}
	filtered := make([]int, 0, len(candidates))
	for _, port := range candidates {
		if _, ok := seen[port]; ok {
			continue
		}
		seen[port] = struct{}{}
		filtered = append(filtered, port)
	}
	return filtered
}

func (c *Client) sshPortCandidate() int {
	if c.port > 0 && c.port != 8728 && c.port != 8729 {
		return c.port
	}
	return 22
}

func (c *Client) connectSSH() error {
	if c.ssh != nil {
		return nil
	}

	config := &gossh.ClientConfig{
		User:            c.username,
		Auth:            []gossh.AuthMethod{gossh.Password(c.password)},
		HostKeyCallback: gossh.InsecureIgnoreHostKey(),
		Timeout:         c.timeout,
	}

	addr := fmt.Sprintf("%s:%d", c.host, c.sshPortCandidate())
	conn, err := gossh.Dial("tcp", addr, config)
	if err != nil {
		return fmt.Errorf("failed to connect to %s: %w", addr, err)
	}

	c.ssh = conn
	return nil
}

// Execute runs a command on the router. Binary API is preferred.
func (c *Client) Execute(command string) (string, error) {
	if c.binary != nil {
		output, err := c.binary.execute(command)
		if err == nil {
			c.connected = true
			return output, nil
		}
		if err != errUnsupportedCommand && !isBinaryFallbackWorthy(err) {
			return "", err
		}
	}

	if err := c.connectSSH(); err != nil {
		return "", err
	}

	session, err := c.ssh.NewSession()
	if err != nil {
		return "", fmt.Errorf("failed to create session: %w", err)
	}
	defer session.Close()

	output, err := session.CombinedOutput(command)
	if err != nil {
		return string(output), fmt.Errorf("command failed: %w", err)
	}

	c.connected = true
	return string(output), nil
}

func isBinaryFallbackWorthy(err error) bool {
	if err == nil {
		return false
	}
	msg := strings.ToLower(err.Error())
	needles := []string{
		"connection refused",
		"no route to host",
		"i/o timeout",
		"socket closed",
		"binary api connection failed",
		"binary api socket",
		"binary api login failed",
	}
	for _, needle := range needles {
		if strings.Contains(msg, needle) {
			return true
		}
	}
	return false
}

// ExecuteMultiple runs multiple commands.
func (c *Client) ExecuteMultiple(commands []string) ([]string, error) {
	results := make([]string, 0, len(commands))
	for _, cmd := range commands {
		output, err := c.Execute(cmd)
		if err != nil {
			return results, fmt.Errorf("command '%s' failed: %w", cmd, err)
		}
		results = append(results, output)
	}
	return results, nil
}

// ExecuteScript runs a full RouterOS script via the binary API.
func (c *Client) ExecuteScript(script string) (string, error) {
	if c.binary != nil {
		output, err := c.binary.executeScript(script)
		if err == nil {
			c.connected = true
			return output, nil
		}
		return "", err
	}

	return "", fmt.Errorf("binary api not connected")
}

// Close closes any active transport connections.
func (c *Client) Close() error {
	c.connected = false
	c.stagedFiles = make(map[string]string)

	if c.binary != nil {
		_ = c.binary.close()
		c.binary = nil
	}
	if c.ssh != nil {
		_ = c.ssh.Close()
		c.ssh = nil
	}
	return nil
}

// IsConnected checks whether any transport is active.
func (c *Client) IsConnected() bool {
	return c.binary != nil || c.ssh != nil
}
