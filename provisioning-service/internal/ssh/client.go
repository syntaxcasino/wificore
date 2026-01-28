package ssh

import (
	"fmt"
	"time"

	"golang.org/x/crypto/ssh"
)

// Client represents an SSH client for MikroTik routers
type Client struct {
	host       string
	port       int
	username   string
	password   string
	timeout    time.Duration
	connection *ssh.Client
}

// NewClient creates a new SSH client
func NewClient(host string, port int, username, password string, timeout time.Duration) *Client {
	return &Client{
		host:     host,
		port:     port,
		username: username,
		password: password,
		timeout:  timeout,
	}
}

// Connect establishes SSH connection
func (c *Client) Connect() error {
	config := &ssh.ClientConfig{
		User: c.username,
		Auth: []ssh.AuthMethod{
			ssh.Password(c.password),
		},
		HostKeyCallback: ssh.InsecureIgnoreHostKey(), // TODO: Implement proper host key verification
		Timeout:         c.timeout,
	}

	addr := fmt.Sprintf("%s:%d", c.host, c.port)
	conn, err := ssh.Dial("tcp", addr, config)
	if err != nil {
		return fmt.Errorf("failed to connect to %s: %w", addr, err)
	}

	c.connection = conn
	return nil
}

// Execute runs a command on the router
func (c *Client) Execute(command string) (string, error) {
	if c.connection == nil {
		return "", fmt.Errorf("not connected")
	}

	session, err := c.connection.NewSession()
	if err != nil {
		return "", fmt.Errorf("failed to create session: %w", err)
	}
	defer session.Close()

	output, err := session.CombinedOutput(command)
	if err != nil {
		return string(output), fmt.Errorf("command failed: %w", err)
	}

	return string(output), nil
}

// ExecuteMultiple runs multiple commands
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

// Close closes the SSH connection
func (c *Client) Close() error {
	if c.connection != nil {
		return c.connection.Close()
	}
	return nil
}

// IsConnected checks if connection is active
func (c *Client) IsConnected() bool {
	return c.connection != nil
}
