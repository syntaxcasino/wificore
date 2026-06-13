package models

import "time"

// Router represents a MikroTik router
type Router struct {
	ID           string    `json:"id"`
	Name         string    `json:"name"`
	IPAddress    string    `json:"ip_address"`
	VPNIPAddress string    `json:"vpn_ip"`
	Username     string    `json:"username"`
	Password     string    `json:"password"`
	SSHPort      int       `json:"ssh_port"`
	TenantID     string    `json:"tenant_id"`
	CreatedAt    time.Time `json:"created_at"`
	UpdatedAt    time.Time `json:"updated_at"`
}

// ConnectionConfig represents router connection parameters supplied by Laravel.
type ConnectionConfig struct {
	IPAddress string `json:"ip_address"`
	VPNIP     string `json:"vpn_ip"`
	Username  string `json:"username"`
	Password  string `json:"password"`
	SSHPort   int    `json:"ssh_port"`
}

// TaskCallbackConfig represents a backend callback target for task status updates.
type TaskCallbackConfig struct {
	URL      string `json:"url"`
	APIKey   string `json:"api_key"`
	Terminal bool   `json:"terminal"`
	Stage    string `json:"stage,omitempty"`
	TenantID string `json:"tenant_id,omitempty"`
	RouterID string `json:"router_id,omitempty"`
}

const (
	CommandTypeDeployServiceConfig  = "deploy_service_config"
	CommandTypeApplyServiceConfigs  = "apply_service_configs"
	CommandTypeVerifyConnectivity   = "verify_connectivity"
	CommandTypeDiscoverInterfaces   = "discover_interfaces"
	CommandTypeRefreshVPNStatus     = "refresh_vpn_status"
	CommandTypeRefreshRouterStatus  = "refresh_router_status"
	CommandTypeRefreshLiveData      = "refresh_live_data"
	CommandTypeWaitVPNConnectivity  = "wait_vpn_connectivity"
	CommandTypeServiceControlAction = "service_control_action"
	CommandTypeComputeRouterMetrics = "compute_router_metrics"
)

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
	RouterID           string              `json:"router_id" binding:"required"`
	Context            string              `json:"context"`
	TenantID           string              `json:"tenant_id" binding:"required"`
	Connection         ConnectionConfig    `json:"connection" binding:"required"`
	FilterConfigurable bool                `json:"filter_configurable"`
	Callback           *TaskCallbackConfig `json:"callback"`
}

// LiveDataResponse represents live data response
type LiveDataResponse struct {
	Success   bool                   `json:"success"`
	RouterID  string                 `json:"router_id"`
	Data      map[string]interface{} `json:"data,omitempty"`
	Error     string                 `json:"error,omitempty"`
	FetchedAt time.Time              `json:"fetched_at"`
}

// ExecuteCommandRequest represents a command execution request
type ExecuteCommandRequest struct {
	RouterID   string              `json:"router_id" binding:"required"`
	Commands   []string            `json:"commands" binding:"required"`
	TenantID   string              `json:"tenant_id" binding:"required"`
	Connection ConnectionConfig    `json:"connection" binding:"required"`
	Callback   *TaskCallbackConfig `json:"callback"`
}

// ExecuteCommandResponse represents command execution response
type ExecuteCommandResponse struct {
	Success bool            `json:"success"`
	Results []CommandResult `json:"results"`
	Error   string          `json:"error,omitempty"`
}

// CommandResult represents a single command result
type CommandResult struct {
	Command  string `json:"command"`
	Output   string `json:"output"`
	Error    string `json:"error,omitempty"`
	Duration int64  `json:"duration_ms"`
}

// DeployScriptRequest represents a full RouterOS script deployment request.
type DeployScriptRequest struct {
	RouterID   string              `json:"router_id" binding:"required"`
	TenantID   string              `json:"tenant_id" binding:"required"`
	Script     string              `json:"script" binding:"required"`
	Connection ConnectionConfig    `json:"connection" binding:"required"`
	Callback   *TaskCallbackConfig `json:"callback"`
}

// VerifyConnectivityRequest represents a router connectivity check request.
type VerifyConnectivityRequest struct {
	RouterID   string              `json:"router_id" binding:"required"`
	TenantID   string              `json:"tenant_id" binding:"required"`
	Connection ConnectionConfig    `json:"connection" binding:"required"`
	Callback   *TaskCallbackConfig `json:"callback"`
}

// ProvisionServiceRequest represents a full deploy-and-verify service workflow request.
type ProvisionServiceRequest struct {
	RouterID       string              `json:"router_id" binding:"required"`
	TenantID       string              `json:"tenant_id" binding:"required"`
	Script         string              `json:"script" binding:"required"`
	Connection     ConnectionConfig    `json:"connection" binding:"required"`
	Callback       *TaskCallbackConfig `json:"callback"`
	IdempotencyKey string              `json:"idempotency_key"`
}

// CommandPayload represents a provisioning command payload.
type CommandPayload struct {
	Script             string            `json:"script,omitempty"`
	Commands           []string          `json:"commands,omitempty"`
	Context            string            `json:"context,omitempty"`
	FilterConfigurable bool              `json:"filter_configurable,omitempty"`
	Connection         ConnectionConfig  `json:"connection,omitempty"`
	Monitoring         MonitoringPayload `json:"monitoring,omitempty"`
	Metrics            MetricsPayload    `json:"metrics,omitempty"`
}

type MetricsPayload struct {
	TimeRanges []string `json:"time_ranges,omitempty"`
}

type MonitoringPayload struct {
	Tunnels             []MonitoringTunnel      `json:"tunnels,omitempty"`
	PeerMappings        []MonitoringPeerMapping `json:"peer_mappings,omitempty"`
	Routers             []MonitoringRouter      `json:"routers,omitempty"`
	InactiveThreshold   int                     `json:"inactive_threshold,omitempty"`
	OfflineGracePeriod  int                     `json:"offline_grace_period,omitempty"`
	RecentMetricsWindow int                     `json:"recent_metrics_window,omitempty"`
	MaxWaitSeconds      int                     `json:"max_wait_seconds,omitempty"`
	RetryInterval       int                     `json:"retry_interval,omitempty"`
}

type MonitoringTunnel struct {
	InterfaceName string `json:"interface_name" binding:"required"`
}

type MonitoringPeerMapping struct {
	PublicKey            string `json:"public_key" binding:"required"`
	RouterID             string `json:"router_id" binding:"required"`
	RouterName           string `json:"router_name,omitempty"`
	IPAddress            string `json:"ip_address,omitempty"`
	VPNIP                string `json:"vpn_ip,omitempty"`
	Model                string `json:"model,omitempty"`
	OSVersion            string `json:"os_version,omitempty"`
	VPNConfigStatus      string `json:"vpn_config_status,omitempty"`
	PreviousRouterStatus string `json:"previous_router_status,omitempty"`
}

type MonitoringRouter struct {
	RouterID          string    `json:"router_id" binding:"required"`
	RouterName        string    `json:"router_name,omitempty"`
	IPAddress         string    `json:"ip_address,omitempty"`
	VPNIP             string    `json:"vpn_ip,omitempty"`
	Status            string    `json:"status,omitempty"`
	VPNStatus         string    `json:"vpn_status,omitempty"`
	LastSeen          time.Time `json:"last_seen,omitempty"`
	LastChecked       time.Time `json:"last_checked,omitempty"`
	VPNLastHandshake  time.Time `json:"vpn_last_handshake,omitempty"`
	ClientIP          string    `json:"client_ip,omitempty"`
	ProvisioningStage string    `json:"provisioning_stage,omitempty"`
	Model             string    `json:"model,omitempty"`
	OSVersion         string    `json:"os_version,omitempty"`
	VPNConfigID       int       `json:"vpn_config_id,omitempty"`
}

// CommandRequest represents an asynchronous provisioning command submission.
type CommandRequest struct {
	CommandID   string              `json:"command_id"`
	TaskID      string              `json:"task_id,omitempty"`
	RouterID    string              `json:"router_id" binding:"required"`
	TenantID    string              `json:"tenant_id" binding:"required"`
	Type        string              `json:"type" binding:"required"`
	RequestedBy string              `json:"requested_by,omitempty"`
	RequestedAt time.Time           `json:"requested_at,omitempty"`
	Payload     CommandPayload      `json:"payload" binding:"required"`
	Callback    *TaskCallbackConfig `json:"callback,omitempty"`
}

// CommandResponse represents the command submission response.
type CommandResponse struct {
	Success bool                   `json:"success"`
	Message string                 `json:"message"`
	Data    map[string]interface{} `json:"data,omitempty"`
	Error   string                 `json:"error,omitempty"`
}

// HealthResponse represents health check response
type HealthResponse struct {
	Status    string           `json:"status"`
	Timestamp time.Time        `json:"timestamp"`
	Version   string           `json:"version"`
	Uptime    int64            `json:"uptime_seconds"`
	Metrics   map[string]int64 `json:"metrics"`
}
