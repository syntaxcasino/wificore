package models

import "time"

// Router represents a MikroTik router
type Router struct {
	ID          string    `json:"id"`
	Name        string    `json:"name"`
	IPAddress   string    `json:"ip_address"`
	VPNIPAddress string   `json:"vpn_ip"`
	Username    string    `json:"username"`
	Password    string    `json:"password"`
	SSHPort     int       `json:"ssh_port"`
	TenantID    string    `json:"tenant_id"`
	CreatedAt   time.Time `json:"created_at"`
	UpdatedAt   time.Time `json:"updated_at"`
}

// ProvisionRequest represents a provisioning request
type ProvisionRequest struct {
	RouterID      string                 `json:"router_id" binding:"required"`
	Configuration map[string]interface{} `json:"configuration" binding:"required"`
	TenantID      string                 `json:"tenant_id" binding:"required"`
}

// ProvisionResponse represents a provisioning response
type ProvisionResponse struct {
	Success bool                   `json:"success"`
	Message string                 `json:"message"`
	Data    map[string]interface{} `json:"data,omitempty"`
	Error   string                 `json:"error,omitempty"`
}

// LiveDataRequest represents a live data fetch request
type LiveDataRequest struct {
	RouterID string `json:"router_id" binding:"required"`
	Context  string `json:"context"` // "live", "provisioning", "details"
	TenantID string `json:"tenant_id" binding:"required"`
}

// LiveDataResponse represents live data response
type LiveDataResponse struct {
	Success    bool                   `json:"success"`
	RouterID   string                 `json:"router_id"`
	Data       map[string]interface{} `json:"data,omitempty"`
	Error      string                 `json:"error,omitempty"`
	FetchedAt  time.Time              `json:"fetched_at"`
}

// ExecuteCommandRequest represents a command execution request
type ExecuteCommandRequest struct {
	RouterID string   `json:"router_id" binding:"required"`
	Commands []string `json:"commands" binding:"required"`
	TenantID string   `json:"tenant_id" binding:"required"`
}

// ExecuteCommandResponse represents command execution response
type ExecuteCommandResponse struct {
	Success bool                   `json:"success"`
	Results []CommandResult        `json:"results"`
	Error   string                 `json:"error,omitempty"`
}

// CommandResult represents a single command result
type CommandResult struct {
	Command  string `json:"command"`
	Output   string `json:"output"`
	Error    string `json:"error,omitempty"`
	Duration int64  `json:"duration_ms"`
}

// HealthResponse represents health check response
type HealthResponse struct {
	Status    string            `json:"status"`
	Timestamp time.Time         `json:"timestamp"`
	Version   string            `json:"version"`
	Uptime    int64             `json:"uptime_seconds"`
	Metrics   map[string]int64  `json:"metrics"`
}
