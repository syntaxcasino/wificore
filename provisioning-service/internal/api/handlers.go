package api

import (
	"bytes"
	"encoding/json"
	"fmt"
	"math"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/sirupsen/logrus"
	"github.com/wificore/provisioning-service/internal/models"
	"github.com/wificore/provisioning-service/internal/ssh"
)

var (
	startTime = time.Now()
	version   = "1.0.0"
)

// Handler manages API requests
type routerSSHClient interface {
	Execute(command string) (string, error)
	ExecuteMultiple(commands []string) ([]string, error)
	ExecuteScript(script string) (string, error)
	Close() error
}

// metricPoint represents a VictoriaMetrics range sample.
type metricPoint struct {
	Ts int     `json:"ts"`
	V  float64 `json:"v"`
}

// trafficPoint represents aligned inbound/outbound traffic samples.
type trafficPoint struct {
	Ts       int     `json:"ts"`
	Upload   float64 `json:"upload"`
	Download float64 `json:"download"`
}

// Handler manages API requests
type Handler struct {
	logger             *logrus.Logger
	workflowStore      *persistedWorkflowStore
	connectRouter      func(models.ConnectionConfig) (routerSSHClient, error)
	runCommandWorkflow func(models.CommandRequest)
}

// NewHandler creates a new API handler
func NewHandler(logger *logrus.Logger) *Handler {
	store, err := newPersistedWorkflowStore(filepath.Join("data", "provisioning-workflows.json"), 30*time.Minute, 30*time.Second)
	if err != nil {
		logger.WithError(err).Fatal("Failed to initialize provisioning workflow store")
	}
	handler := &Handler{logger: logger, workflowStore: store}
	handler.connectRouter = handler.connectSSH
	handler.runCommandWorkflow = handler.executeCommandWorkflow
	go handler.startOutboxWorker()
	return handler
}

func (h *Handler) startOutboxWorker() {
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()
	for range ticker.C {
		entries := h.workflowStore.DueOutboxEntries(20)
		for _, entry := range entries {
			if err := h.sendCallbackPayload(entry.URL, entry.APIKey, entry.Payload); err != nil {
				h.logger.WithError(err).WithField("workflow_key", entry.WorkflowKey).Warn("Provisioning callback retry failed")
				_ = h.workflowStore.MarkOutboxRetry(entry.ID, err.Error())
				continue
			}
			_ = h.workflowStore.MarkOutboxDelivered(entry.ID)
		}
	}
}

// GetWorkflowStatus returns durable workflow state by idempotency key.
func (h *Handler) GetWorkflowStatus(c *gin.Context) {
	idempotencyKey := strings.TrimSpace(c.Param("idempotencyKey"))
	if idempotencyKey == "" {
		c.JSON(http.StatusBadRequest, gin.H{"success": false, "error": "Missing idempotency key"})
		return
	}

	record, ok := h.workflowStore.GetByKey(idempotencyKey)
	if !ok {
		c.JSON(http.StatusNotFound, gin.H{"success": false, "error": "Workflow not found"})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"success": true,
		"data":    record,
	})
}

// GetActiveWorkflow returns the active running workflow for a router, if any.
func (h *Handler) GetActiveWorkflow(c *gin.Context) {
	routerID := strings.TrimSpace(c.Param("routerId"))
	if routerID == "" {
		c.JSON(http.StatusBadRequest, gin.H{"success": false, "error": "Missing router ID"})
		return
	}

	record, ok := h.workflowStore.GetActiveByRouter(routerID)
	if !ok {
		c.JSON(http.StatusNotFound, gin.H{"success": false, "error": "No active workflow found for router"})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"success": true,
		"data":    record,
	})
}

// HealthCheck handles health check requests
func (h *Handler) HealthCheck(c *gin.Context) {
	uptime := time.Since(startTime).Seconds()
	c.JSON(http.StatusOK, models.HealthResponse{
		Status:    "healthy",
		Timestamp: time.Now(),
		Version:   version,
		Uptime:    int64(uptime),
		Metrics: map[string]int64{
			"active_connections": 0,
			"total_requests":     0,
		},
	})
}

// ProvisionRouter handles router provisioning requests
func (h *Handler) ProvisionRouter(c *gin.Context) {
	var req models.ProvisionRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		h.logger.WithError(err).Error("Invalid provision request")
		c.JSON(http.StatusBadRequest, models.ProvisionResponse{Success: false, Error: fmt.Sprintf("Invalid request: %v", err)})
		return
	}

	connection, err := connectionConfigFromMap(req.Configuration)
	if err != nil {
		c.JSON(http.StatusBadRequest, models.ProvisionResponse{Success: false, Error: err.Error()})
		return
	}

	client, err := h.connectSSH(connection)
	if err != nil {
		h.logger.WithError(err).Error("Failed to connect to router")
		c.JSON(http.StatusServiceUnavailable, models.ProvisionResponse{Success: false, Error: fmt.Sprintf("Connection failed: %v", err)})
		return
	}
	defer client.Close()

	commandsRaw, ok := req.Configuration["commands"].([]interface{})
	if !ok || len(commandsRaw) == 0 {
		c.JSON(http.StatusBadRequest, models.ProvisionResponse{Success: false, Error: "No commands provided"})
		return
	}

	commands := make([]string, 0, len(commandsRaw))
	for _, cmd := range commandsRaw {
		if cmdStr, ok := cmd.(string); ok && strings.TrimSpace(cmdStr) != "" {
			commands = append(commands, cmdStr)
		}
	}

	results, err := client.ExecuteMultiple(commands)
	if err != nil {
		h.logger.WithError(err).Error("Command execution failed")
		c.JSON(http.StatusInternalServerError, models.ProvisionResponse{Success: false, Error: fmt.Sprintf("Execution failed: %v", err), Data: map[string]interface{}{"partial_results": results}})
		return
	}

	c.JSON(http.StatusOK, models.ProvisionResponse{Success: true, Message: "Provisioning completed successfully", Data: map[string]interface{}{"results": results}})
}

// FetchLiveData handles live data fetch requests
func (h *Handler) FetchLiveData(c *gin.Context) {
	var req models.LiveDataRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		h.logger.WithError(err).Error("Invalid live data request")
		c.JSON(http.StatusBadRequest, models.LiveDataResponse{Success: false, Error: fmt.Sprintf("Invalid request: %v", err)})
		return
	}

	h.notifyTaskCallback(req.Callback, "running", 10, "Connecting to router for live data", nil, "")
	client, err := h.connectSSH(req.Connection)
	if err != nil {
		h.notifyTaskCallback(req.Callback, "failed", 100, "Failed to connect to router for live data", nil, err.Error())
		c.JSON(http.StatusServiceUnavailable, models.LiveDataResponse{Success: false, RouterID: req.RouterID, Error: fmt.Sprintf("Connection failed: %v", err), FetchedAt: time.Now()})
		return
	}
	defer client.Close()

	resourceOutput, err := client.Execute("/system resource print")
	if err != nil {
		h.notifyTaskCallback(req.Callback, "failed", 100, "Failed to fetch router resources", nil, err.Error())
		c.JSON(http.StatusInternalServerError, models.LiveDataResponse{Success: false, RouterID: req.RouterID, Error: fmt.Sprintf("Resource fetch failed: %v", err), FetchedAt: time.Now()})
		return
	}
	identityOutput, err := client.Execute("/system identity print")
	if err != nil {
		h.notifyTaskCallback(req.Callback, "failed", 100, "Failed to fetch router identity", nil, err.Error())
		c.JSON(http.StatusInternalServerError, models.LiveDataResponse{Success: false, RouterID: req.RouterID, Error: fmt.Sprintf("Identity fetch failed: %v", err), FetchedAt: time.Now()})
		return
	}

	resource := parseSingleKeyValueBlock(resourceOutput)
	identity := parseSingleKeyValueBlock(identityOutput)
	data := map[string]interface{}{
		"status":           "online",
		"board_name":       valueOrDefault(resource["board-name"], "Unknown"),
		"version":          valueOrDefault(resource["version"], "Unknown"),
		"uptime":           valueOrDefault(resource["uptime"], "Unknown"),
		"identity":         valueOrDefault(identity["name"], "Unknown"),
		"cpu_load":         parseOptionalInt(resource["cpu-load"]),
		"free_memory":      resource["free-memory"],
		"total_memory":     resource["total-memory"],
		"free_hdd_space":   resource["free-hdd-space"],
		"total_hdd_space":  resource["total-hdd-space"],
		"last_updated":     time.Now().Format("2006-01-02 15:04:05"),
		"interfaces":       []map[string]string{},
		"interface_count":  0,
		"interfaces_count": 0,
	}

	if req.Context == "provisioning" || req.Context == "details" {
		interfaceOutput, execErr := client.Execute("/interface print detail without-paging")
		if execErr != nil {
			h.notifyTaskCallback(req.Callback, "failed", 100, "Failed to fetch router interfaces", nil, execErr.Error())
			c.JSON(http.StatusInternalServerError, models.LiveDataResponse{Success: false, RouterID: req.RouterID, Error: fmt.Sprintf("Interface fetch failed: %v", execErr), FetchedAt: time.Now()})
			return
		}
		interfaces := parseInterfaces(interfaceOutput)
		if req.FilterConfigurable || req.Context == "provisioning" {
			interfaces = filterConfigurableInterfaces(interfaces)
		}
		data["interfaces"] = interfaces
		data["interface_count"] = len(interfaces)
		data["interfaces_count"] = len(interfaces)

		if (req.FilterConfigurable || req.Context == "provisioning") && len(interfaces) == 0 {
			h.notifyTaskCallback(req.Callback, "failed", 100, "No configurable interfaces found on router", data, "no configurable interfaces found")
			c.JSON(http.StatusOK, models.LiveDataResponse{Success: true, RouterID: req.RouterID, Data: data, FetchedAt: time.Now()})
			return
		}
	} else {
		countOutput, execErr := client.Execute("/interface print count-only")
		if execErr == nil {
			count := parseCount(countOutput)
			data["interface_count"] = count
			data["interfaces_count"] = count
		}
		if hotspotOutput, hotspotErr := client.Execute("/ip hotspot active print count-only"); hotspotErr == nil {
			hotspotCount := parseCount(hotspotOutput)
			data["hotspot_active"] = hotspotCount
			data["active_connections"] = hotspotCount
		}
		if pppoeOutput, pppoeErr := client.Execute("/ppp active print count-only"); pppoeErr == nil {
			pppoeCount := parseCount(pppoeOutput)
			data["pppoe_active"] = pppoeCount
			activeConnections := pppoeCount
			if existing, ok := data["active_connections"].(int); ok {
				activeConnections += existing
			}
			data["active_connections"] = activeConnections
		}
		if dhcpOutput, dhcpErr := client.Execute("/ip dhcp-server lease print count-only"); dhcpErr == nil {
			data["dhcp_leases"] = parseCount(dhcpOutput)
		}
	}

	h.notifyTaskCallback(req.Callback, "completed", 100, "Live data fetched successfully", data, "")
	c.JSON(http.StatusOK, models.LiveDataResponse{Success: true, RouterID: req.RouterID, Data: data, FetchedAt: time.Now()})
}

// ExecuteCommand handles command execution requests
func (h *Handler) ExecuteCommand(c *gin.Context) {
	var req models.ExecuteCommandRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		h.logger.WithError(err).Error("Invalid execute command request")
		c.JSON(http.StatusBadRequest, models.ExecuteCommandResponse{Success: false, Error: fmt.Sprintf("Invalid request: %v", err)})
		return
	}

	h.notifyTaskCallback(req.Callback, "running", 10, "Connecting to router for command execution", nil, "")
	client, err := h.connectSSH(req.Connection)
	if err != nil {
		h.notifyTaskCallback(req.Callback, "failed", 100, "Command execution connection failed", nil, err.Error())
		c.JSON(http.StatusServiceUnavailable, models.ExecuteCommandResponse{Success: false, Error: fmt.Sprintf("Connection failed: %v", err)})
		return
	}
	defer client.Close()

	results := make([]models.CommandResult, 0, len(req.Commands))
	for _, command := range req.Commands {
		started := time.Now()
		output, execErr := client.Execute(command)
		result := models.CommandResult{Command: command, Output: output, Duration: time.Since(started).Milliseconds()}
		if execErr != nil {
			result.Error = execErr.Error()
			results = append(results, result)
			h.notifyTaskCallback(req.Callback, "failed", 100, "Command execution failed", map[string]interface{}{"results": results}, execErr.Error())
			c.JSON(http.StatusInternalServerError, models.ExecuteCommandResponse{Success: false, Results: results, Error: execErr.Error()})
			return
		}
		results = append(results, result)
	}

	h.notifyTaskCallback(req.Callback, "completed", 100, "Commands executed successfully", map[string]interface{}{"results": results}, "")
	c.JSON(http.StatusOK, models.ExecuteCommandResponse{Success: true, Results: results})
}

// DeployScript handles full script deployment requests
func (h *Handler) DeployScript(c *gin.Context) {
	var req models.DeployScriptRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		h.logger.WithError(err).Error("Invalid deploy script request")
		h.notifyTaskCallback(req.Callback, "failed", 100, "Invalid deploy script request", nil, err.Error())
		c.JSON(http.StatusBadRequest, gin.H{"success": false, "error": fmt.Sprintf("Invalid request: %v", err)})
		return
	}

	h.notifyTaskCallback(req.Callback, "running", 10, "Preparing script deployment", nil, "")
	executedCommands := countExecutableScriptLines(req.Script)
	if executedCommands == 0 {
		h.notifyTaskCallback(req.Callback, "failed", 100, "No executable commands found in script", nil, "invalid script")
		c.JSON(http.StatusBadRequest, gin.H{"success": false, "error": "No executable commands found in script"})
		return
	}

	h.notifyTaskCallback(req.Callback, "running", 25, "Connecting to router for script deployment", nil, "")
	client, err := h.connectRouter(req.Connection)
	if err != nil {
		h.notifyTaskCallback(req.Callback, "failed", 100, "Script deployment connection failed", nil, err.Error())
		c.JSON(http.StatusServiceUnavailable, gin.H{"success": false, "error": fmt.Sprintf("Connection failed: %v", err)})
		return
	}
	defer client.Close()

	results, execErr := executeRouterScript(client, req.Script)
	if execErr != nil {
		h.logger.WithError(execErr).WithField("router_id", req.RouterID).Error("Script deployment failed")
		h.notifyTaskCallback(req.Callback, "failed", 100, "Script deployment failed", map[string]interface{}{"command_results": results, "executed_commands": executedCommands}, execErr.Error())
		c.JSON(http.StatusInternalServerError, gin.H{"success": false, "error": execErr.Error(), "data": map[string]interface{}{"command_results": results, "executed_commands": executedCommands}})
		return
	}

	payload := map[string]interface{}{"router_id": req.RouterID, "executed_at": time.Now(), "executed_commands": executedCommands, "command_results": results}
	h.notifyTaskCallback(req.Callback, "completed", 100, "Script deployed successfully", payload, "")
	c.JSON(http.StatusOK, gin.H{"success": true, "message": "Script deployed successfully", "data": payload})
}

// ProvisionService handles a full deploy-and-verify service workflow request.
func (h *Handler) ProvisionService(c *gin.Context) {
	var req models.ProvisionServiceRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		h.logger.WithError(err).Error("Invalid provision service request")
		h.notifyTaskCallbackWithOptions(req.Callback, "failed", 100, "Invalid service provisioning request", nil, err.Error(), "submitted", true)
		c.JSON(http.StatusBadRequest, gin.H{"success": false, "error": fmt.Sprintf("Invalid request: %v", err)})
		return
	}

	idempotencyKey := strings.TrimSpace(req.IdempotencyKey)
	if idempotencyKey == "" {
		idempotencyKey = strings.TrimSpace(req.RouterID)
	}

	acquireStatus, activeEntry, err := h.workflowStore.Acquire(req.RouterID, req.TenantID, idempotencyKey)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"success": false, "error": fmt.Sprintf("Failed to persist workflow state: %v", err)})
		return
	}
	switch acquireStatus {
	case "duplicate_active":
		c.JSON(http.StatusOK, gin.H{"success": true, "message": "Provisioning workflow already in progress for this idempotency key", "data": map[string]interface{}{"status": "duplicate_active", "router_id": req.RouterID, "idempotency_key": idempotencyKey, "started_at": activeEntry.StartedAt}})
		return
	case "duplicate_completed":
		c.JSON(http.StatusOK, gin.H{"success": true, "message": "Provisioning workflow already completed for this idempotency key", "data": map[string]interface{}{"status": "duplicate_completed", "router_id": req.RouterID, "idempotency_key": idempotencyKey, "completed_at": activeEntry.CompletedAt, "result": activeEntry.Result}})
		return
	case "router_busy":
		c.JSON(http.StatusConflict, gin.H{"success": false, "error": "Router already has an active provisioning workflow", "data": map[string]interface{}{"status": "router_busy", "router_id": req.RouterID, "active_idempotency_key": activeEntry.IdempotencyKey, "started_at": activeEntry.StartedAt}})
		return
	}

	h.reportProvisioningWorkflowEvent(req.Callback, idempotencyKey, req.RouterID, "running", 10, "Preparing service provisioning workflow", nil, "", "submitted", false)
	executedCommands := countExecutableScriptLines(req.Script)
	if executedCommands == 0 {
		h.reportProvisioningWorkflowEvent(req.Callback, idempotencyKey, req.RouterID, "failed", 100, "No executable commands found in service configuration", nil, "invalid script", "submitted", true)
		c.JSON(http.StatusBadRequest, gin.H{"success": false, "error": "No executable commands found in service configuration"})
		return
	}

	h.reportProvisioningWorkflowEvent(req.Callback, idempotencyKey, req.RouterID, "running", 20, "Prechecking router connectivity for provisioning", nil, "", "precheck_connectivity", false)
	client, err := h.connectRouter(req.Connection)
	if err != nil {
		h.reportProvisioningWorkflowEvent(req.Callback, idempotencyKey, req.RouterID, "failed", 100, "Service provisioning connection failed", nil, err.Error(), "precheck_connectivity", true)
		c.JSON(http.StatusServiceUnavailable, gin.H{"success": false, "error": fmt.Sprintf("Connection failed: %v", err)})
		return
	}
	defer client.Close()

	h.reportProvisioningWorkflowEvent(req.Callback, idempotencyKey, req.RouterID, "running", 45, "Applying service configuration", nil, "", "deploying_config", false)
	results, execErr := executeRouterScript(client, req.Script)
	if execErr != nil {
		h.reportProvisioningWorkflowEvent(req.Callback, idempotencyKey, req.RouterID, "failed", 100, "Service configuration deployment failed", map[string]interface{}{"command_results": results, "executed_commands": executedCommands}, execErr.Error(), "deploying_config", true)
		c.JSON(http.StatusInternalServerError, gin.H{"success": false, "error": execErr.Error(), "data": map[string]interface{}{"command_results": results, "executed_commands": executedCommands}})
		return
	}

	h.reportProvisioningWorkflowEvent(req.Callback, idempotencyKey, req.RouterID, "running", 80, "Verifying service provisioning deployment", map[string]interface{}{"executed_commands": executedCommands}, "", "verifying_deployment", false)
	identityOutput, err := client.Execute("/system identity print")
	if err != nil {
		h.reportProvisioningWorkflowEvent(req.Callback, idempotencyKey, req.RouterID, "failed", 100, "Unable to read router identity after deployment", map[string]interface{}{"command_results": results, "executed_commands": executedCommands}, err.Error(), "verifying_deployment", true)
		c.JSON(http.StatusOK, gin.H{"success": true, "status": "failed", "message": "Unable to read router identity after deployment", "error": err.Error()})
		return
	}
	resourceOutput, err := client.Execute("/system resource print")
	if err != nil {
		h.reportProvisioningWorkflowEvent(req.Callback, idempotencyKey, req.RouterID, "failed", 100, "Unable to read router resources after deployment", map[string]interface{}{"command_results": results, "executed_commands": executedCommands}, err.Error(), "verifying_deployment", true)
		c.JSON(http.StatusOK, gin.H{"success": true, "status": "failed", "message": "Unable to read router resources after deployment", "error": err.Error()})
		return
	}

	identity := parseSingleKeyValueBlock(identityOutput)
	resource := parseSingleKeyValueBlock(resourceOutput)
	payload := map[string]interface{}{
		"router_id":         req.RouterID,
		"status":            "connected",
		"executed_at":       time.Now(),
		"executed_commands": executedCommands,
		"command_results":   results,
		"model":             valueOrDefault(resource["board-name"], "Unknown"),
		"os_version":        valueOrDefault(resource["version"], "Unknown"),
		"identity":          valueOrDefault(identity["name"], "Unknown"),
		"interfaces":        []string{},
		"last_seen":         time.Now(),
	}
	h.reportProvisioningWorkflowEvent(req.Callback, idempotencyKey, req.RouterID, "completed", 100, "Service provisioning workflow completed successfully", payload, "", "completed", true)
	c.JSON(http.StatusOK, gin.H{"success": true, "message": "Service provisioning workflow completed successfully", "data": payload})
}

// VerifyConnectivity handles connectivity verification requests
func (h *Handler) VerifyConnectivity(c *gin.Context) {
	var req models.VerifyConnectivityRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		h.notifyTaskCallback(req.Callback, "failed", 100, "Invalid connectivity request", nil, err.Error())
		c.JSON(http.StatusBadRequest, gin.H{"success": false, "error": fmt.Sprintf("Invalid request: %v", err)})
		return
	}

	h.notifyTaskCallback(req.Callback, "running", 10, "Connecting to router for connectivity verification", nil, "")
	client, err := h.connectSSH(req.Connection)
	if err != nil {
		h.notifyTaskCallback(req.Callback, "failed", 100, "Unable to connect to router via SSH", nil, err.Error())
		c.JSON(http.StatusOK, gin.H{"success": true, "status": "failed", "message": "Unable to connect to router via SSH", "error": err.Error()})
		return
	}
	defer client.Close()

	identityOutput, err := client.Execute("/system identity print")
	if err != nil {
		h.notifyTaskCallback(req.Callback, "failed", 100, "Unable to read router identity", nil, err.Error())
		c.JSON(http.StatusOK, gin.H{"success": true, "status": "failed", "message": "Unable to read router identity", "error": err.Error()})
		return
	}

	resourceOutput, err := client.Execute("/system resource print")
	if err != nil {
		h.notifyTaskCallback(req.Callback, "failed", 100, "Unable to read router resources", nil, err.Error())
		c.JSON(http.StatusOK, gin.H{"success": true, "status": "failed", "message": "Unable to read router resources", "error": err.Error()})
		return
	}

	identity := parseSingleKeyValueBlock(identityOutput)
	resource := parseSingleKeyValueBlock(resourceOutput)
	payload := map[string]interface{}{"status": "connected", "model": valueOrDefault(resource["board-name"], "Unknown"), "os_version": valueOrDefault(resource["version"], "Unknown"), "identity": valueOrDefault(identity["name"], "Unknown"), "interfaces": []string{}, "last_seen": time.Now()}
	h.notifyTaskCallback(req.Callback, "completed", 100, "Connectivity verified successfully", payload, "")
	c.JSON(http.StatusOK, gin.H{"success": true, "status": "connected", "model": valueOrDefault(resource["board-name"], "Unknown"), "os_version": valueOrDefault(resource["version"], "Unknown"), "identity": valueOrDefault(identity["name"], "Unknown"), "interfaces": []string{}, "last_seen": time.Now()})
}

// SubmitCommand accepts an asynchronous provisioning command and executes it in the background.
func (h *Handler) SubmitCommand(c *gin.Context) {
	var req models.CommandRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		h.logger.WithError(err).Error("Invalid command request")
		c.JSON(http.StatusBadRequest, models.CommandResponse{Success: false, Error: fmt.Sprintf("Invalid request: %v", err)})
		return
	}

	req.CommandID = strings.TrimSpace(req.CommandID)
	if req.CommandID == "" {
		if strings.TrimSpace(req.TaskID) != "" {
			req.CommandID = strings.TrimSpace(req.TaskID)
		} else {
			req.CommandID = fmt.Sprintf("%s:%s", req.Type, req.RouterID)
		}
	}
	if req.RequestedAt.IsZero() {
		req.RequestedAt = time.Now()
	}

	if err := validateCommandRequest(req); err != nil {
		c.JSON(http.StatusBadRequest, models.CommandResponse{Success: false, Error: err.Error()})
		return
	}

	acquireStatus, activeEntry, err := h.workflowStore.Acquire(req.RouterID, req.TenantID, req.CommandID)
	if err != nil {
		c.JSON(http.StatusInternalServerError, models.CommandResponse{Success: false, Error: fmt.Sprintf("Failed to persist workflow state: %v", err)})
		return
	}

	switch acquireStatus {
	case "duplicate_active":
		c.JSON(http.StatusOK, models.CommandResponse{Success: true, Message: "Provisioning command already in progress", Data: map[string]interface{}{"status": acquireStatus, "command_id": req.CommandID, "router_id": req.RouterID, "started_at": activeEntry.StartedAt}})
		return
	case "duplicate_completed":
		c.JSON(http.StatusOK, models.CommandResponse{Success: true, Message: "Provisioning command already completed", Data: map[string]interface{}{"status": acquireStatus, "command_id": req.CommandID, "router_id": req.RouterID, "completed_at": activeEntry.CompletedAt, "result": activeEntry.Result}})
		return
	case "router_busy":
		c.JSON(http.StatusConflict, models.CommandResponse{Success: false, Error: "Router already has an active provisioning workflow", Data: map[string]interface{}{"status": acquireStatus, "command_id": req.CommandID, "router_id": req.RouterID, "active_idempotency_key": activeEntry.IdempotencyKey, "started_at": activeEntry.StartedAt}})
		return
	}

	runWorkflow := h.runCommandWorkflow
	if runWorkflow == nil {
		runWorkflow = h.executeCommandWorkflow
	}
	go runWorkflow(req)

	c.JSON(http.StatusAccepted, models.CommandResponse{Success: true, Message: "Provisioning command accepted", Data: map[string]interface{}{"status": "accepted", "command_id": req.CommandID, "router_id": req.RouterID, "type": req.Type, "accepted_at": time.Now()}})
}

func validateCommandRequest(req models.CommandRequest) error {
	switch req.Type {
	case models.CommandTypeDeployServiceConfig, models.CommandTypeApplyServiceConfigs:
		if strings.TrimSpace(req.Payload.Script) == "" {
			return fmt.Errorf("script is required for %s", req.Type)
		}
	case models.CommandTypeVerifyConnectivity, models.CommandTypeDiscoverInterfaces:
		// no-op
	case models.CommandTypeServiceControlAction:
		if len(req.Payload.Commands) == 0 {
			return fmt.Errorf("at least one service-control command is required")
		}
		return nil
	case models.CommandTypeRefreshVPNStatus:
		if len(req.Payload.Monitoring.Tunnels) == 0 {
			return fmt.Errorf("at least one monitoring tunnel is required")
		}
		if len(req.Payload.Monitoring.PeerMappings) == 0 {
			return fmt.Errorf("at least one monitoring peer mapping is required")
		}
		return nil
	case models.CommandTypeRefreshRouterStatus:
		if len(req.Payload.Monitoring.Routers) == 0 {
			return fmt.Errorf("at least one monitoring router snapshot is required")
		}
		return nil
	case models.CommandTypeRefreshLiveData:
		if len(req.Payload.Monitoring.Routers) == 0 {
			return fmt.Errorf("at least one monitoring router snapshot is required")
		}
		return nil
	case models.CommandTypeComputeRouterMetrics:
		if len(req.Payload.Monitoring.Routers) == 0 {
			return fmt.Errorf("at least one monitoring router snapshot is required")
		}
		if len(req.Payload.Metrics.TimeRanges) == 0 {
			return fmt.Errorf("at least one metrics time range is required")
		}
		return nil
	case models.CommandTypeWaitVPNConnectivity:
		if len(req.Payload.Monitoring.Routers) == 0 {
			return fmt.Errorf("at least one monitoring router snapshot is required")
		}
		if req.Payload.Monitoring.MaxWaitSeconds <= 0 || req.Payload.Monitoring.RetryInterval <= 0 {
			return fmt.Errorf("max_wait_seconds and retry_interval are required")
		}
		return nil
	default:
		return fmt.Errorf("unsupported command type: %s", req.Type)
	}

	if strings.TrimSpace(req.Payload.Connection.IPAddress) == "" && strings.TrimSpace(req.Payload.Connection.VPNIP) == "" {
		return fmt.Errorf("missing router IP address")
	}
	if strings.TrimSpace(req.Payload.Connection.Username) == "" || strings.TrimSpace(req.Payload.Connection.Password) == "" {
		return fmt.Errorf("missing router credentials")
	}
	return nil
}

func (h *Handler) executeCommandWorkflow(req models.CommandRequest) {
	switch req.Type {
	case models.CommandTypeDeployServiceConfig:
		h.executeProvisionServiceCommand(req)
	case models.CommandTypeApplyServiceConfigs:
		h.executeDeployScriptCommand(req)
	case models.CommandTypeVerifyConnectivity:
		h.executeVerifyConnectivityCommand(req)
	case models.CommandTypeDiscoverInterfaces:
		h.executeDiscoverInterfacesCommand(req)
	case models.CommandTypeRefreshVPNStatus:
		h.executeRefreshVPNStatusCommand(req)
	case models.CommandTypeRefreshRouterStatus:
		h.executeRefreshRouterStatusCommand(req)
	case models.CommandTypeRefreshLiveData:
		h.executeRefreshLiveDataCommand(req)
	case models.CommandTypeComputeRouterMetrics:
		h.executeComputeRouterMetricsCommand(req)
	case models.CommandTypeWaitVPNConnectivity:
		h.executeWaitVPNConnectivityCommand(req)
	case models.CommandTypeServiceControlAction:
		h.executeServiceControlActionCommand(req)
	default:
		h.reportCommandEvent(req, "failed", 100, "Unsupported provisioning command", nil, fmt.Sprintf("unsupported command type: %s", req.Type), "submitted", true)
	}
}

func (h *Handler) reportCommandEvent(req models.CommandRequest, status string, progress int, message string, result map[string]interface{}, errorMessage string, stage string, terminal bool) {
	if result == nil {
		result = map[string]interface{}{}
	}
	result["tenant_id"] = req.TenantID
	result["router_id"] = req.RouterID
	result["command_id"] = req.CommandID
	if strings.TrimSpace(req.TaskID) != "" {
		result["task_id"] = req.TaskID
	}
	h.reportProvisioningWorkflowEvent(req.Callback, req.CommandID, req.RouterID, status, progress, message, result, errorMessage, stage, terminal)
}

func (h *Handler) executeProvisionServiceCommand(req models.CommandRequest) {
	h.reportCommandEvent(req, "running", 10, "Preparing service provisioning workflow", nil, "", "submitted", false)
	executedCommands := countExecutableScriptLines(req.Payload.Script)
	if executedCommands == 0 {
		h.reportCommandEvent(req, "failed", 100, "No executable commands found in service configuration", nil, "invalid script", "submitted", true)
		return
	}

	h.reportCommandEvent(req, "running", 20, "Prechecking router connectivity for provisioning", nil, "", "precheck_connectivity", false)
	client, err := h.connectRouter(req.Payload.Connection)
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Service provisioning connection failed", nil, err.Error(), "precheck_connectivity", true)
		return
	}
	defer client.Close()

	h.reportCommandEvent(req, "running", 45, "Applying service configuration", nil, "", "deploying_config", false)
	results, execErr := executeRouterScript(client, req.Payload.Script)
	if execErr != nil {
		h.reportCommandEvent(req, "failed", 100, "Service configuration deployment failed", map[string]interface{}{"command_results": results, "executed_commands": executedCommands}, execErr.Error(), "deploying_config", true)
		return
	}

	h.reportCommandEvent(req, "running", 80, "Verifying service provisioning deployment", map[string]interface{}{"executed_commands": executedCommands}, "", "verifying_deployment", false)
	identityOutput, err := client.Execute("/system identity print")
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Unable to read router identity after deployment", map[string]interface{}{"command_results": results, "executed_commands": executedCommands}, err.Error(), "verifying_deployment", true)
		return
	}
	resourceOutput, err := client.Execute("/system resource print")
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Unable to read router resources after deployment", map[string]interface{}{"command_results": results, "executed_commands": executedCommands}, err.Error(), "verifying_deployment", true)
		return
	}

	identity := parseSingleKeyValueBlock(identityOutput)
	resource := parseSingleKeyValueBlock(resourceOutput)
	payload := map[string]interface{}{
		"router_id":         req.RouterID,
		"status":            "connected",
		"executed_at":       time.Now(),
		"executed_commands": executedCommands,
		"command_results":   results,
		"model":             valueOrDefault(resource["board-name"], "Unknown"),
		"os_version":        valueOrDefault(resource["version"], "Unknown"),
		"identity":          valueOrDefault(identity["name"], "Unknown"),
		"interfaces":        []string{},
		"last_seen":         time.Now(),
	}
	h.reportCommandEvent(req, "completed", 100, "Service provisioning workflow completed successfully", payload, "", "completed", true)
}

func (h *Handler) executeDeployScriptCommand(req models.CommandRequest) {
	h.reportCommandEvent(req, "running", 10, "Preparing script deployment", nil, "", "submitted", false)
	executedCommands := countExecutableScriptLines(req.Payload.Script)
	if executedCommands == 0 {
		h.reportCommandEvent(req, "failed", 100, "No executable commands found in script", nil, "invalid script", "submitted", true)
		return
	}

	h.reportCommandEvent(req, "running", 25, "Connecting to router for script deployment", nil, "", "deploying_config", false)
	client, err := h.connectRouter(req.Payload.Connection)
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Script deployment connection failed", nil, err.Error(), "deploying_config", true)
		return
	}
	defer client.Close()

	results, execErr := executeRouterScript(client, req.Payload.Script)
	if execErr != nil {
		h.reportCommandEvent(req, "failed", 100, "Script deployment failed", map[string]interface{}{"command_results": results, "executed_commands": executedCommands}, execErr.Error(), "deploying_config", true)
		return
	}

	payload := map[string]interface{}{
		"router_id":         req.RouterID,
		"executed_at":       time.Now(),
		"executed_commands": executedCommands,
		"command_results":   results,
	}
	h.reportCommandEvent(req, "completed", 100, "Script deployed successfully", payload, "", "completed", true)
}

func (h *Handler) executeServiceControlActionCommand(req models.CommandRequest) {
	h.reportCommandEvent(req, "running", 10, "Connecting to router for service-control action", nil, "", "executing_service_control", false)
	client, err := h.connectSSH(req.Payload.Connection)
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Service-control connection failed", nil, err.Error(), "executing_service_control", true)
		return
	}
	defer client.Close()

	commands := sanitizeCommands(req.Payload.Commands)
	if len(commands) == 0 {
		h.reportCommandEvent(req, "failed", 100, "No service-control commands provided", nil, "invalid commands", "executing_service_control", true)
		return
	}

	h.reportCommandEvent(req, "running", 35, "Applying service-control commands", nil, "", "executing_service_control", false)
	results, execErr := client.ExecuteMultiple(commands)
	if execErr != nil {
		h.reportCommandEvent(req, "failed", 100, "Service-control command execution failed", map[string]interface{}{"results": results, "commands": commands}, execErr.Error(), "executing_service_control", true)
		return
	}

	payload := map[string]interface{}{
		"router_id":          req.RouterID,
		"action":             req.Payload.Context,
		"commands":           commands,
		"command_results":    results,
		"executed_commands":  len(commands),
		"executed_at":        time.Now(),
		"execution_category": "service_control",
	}
	h.reportCommandEvent(req, "completed", 100, "Service-control commands executed successfully", payload, "", "completed", true)
}

func (h *Handler) executeVerifyConnectivityCommand(req models.CommandRequest) {
	h.reportCommandEvent(req, "running", 10, "Connecting to router for connectivity verification", nil, "", "verifying_connectivity", false)
	client, err := h.connectSSH(req.Payload.Connection)
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Unable to connect to router via SSH", nil, err.Error(), "verifying_connectivity", true)
		return
	}
	defer client.Close()

	identityOutput, err := client.Execute("/system identity print")
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Unable to read router identity", nil, err.Error(), "verifying_connectivity", true)
		return
	}
	resourceOutput, err := client.Execute("/system resource print")
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Unable to read router resources", nil, err.Error(), "verifying_connectivity", true)
		return
	}

	identity := parseSingleKeyValueBlock(identityOutput)
	resource := parseSingleKeyValueBlock(resourceOutput)
	payload := map[string]interface{}{
		"router_id":  req.RouterID,
		"status":     "connected",
		"model":      valueOrDefault(resource["board-name"], "Unknown"),
		"os_version": valueOrDefault(resource["version"], "Unknown"),
		"identity":   valueOrDefault(identity["name"], "Unknown"),
		"interfaces": []string{},
		"last_seen":  time.Now(),
		"method":     "provisioning_service_command",
	}
	h.reportCommandEvent(req, "completed", 100, "Connectivity verified successfully", payload, "", "completed", true)
}

func (h *Handler) executeDiscoverInterfacesCommand(req models.CommandRequest) {
	h.reportCommandEvent(req, "running", 10, "Connecting to router for live data", nil, "", "discovering_interfaces", false)
	client, err := h.connectSSH(req.Payload.Connection)
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Failed to connect to router for live data", nil, err.Error(), "discovering_interfaces", true)
		return
	}
	defer client.Close()

	resourceOutput, err := client.Execute("/system resource print")
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Failed to fetch router resources", nil, err.Error(), "discovering_interfaces", true)
		return
	}
	identityOutput, err := client.Execute("/system identity print")
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Failed to fetch router identity", nil, err.Error(), "discovering_interfaces", true)
		return
	}
	interfaceOutput, execErr := client.Execute("/interface print detail without-paging")
	if execErr != nil {
		h.reportCommandEvent(req, "failed", 100, "Failed to fetch router interfaces", nil, execErr.Error(), "discovering_interfaces", true)
		return
	}

	resource := parseSingleKeyValueBlock(resourceOutput)
	identity := parseSingleKeyValueBlock(identityOutput)
	interfaces := parseInterfaces(interfaceOutput)
	filterConfigurable := req.Payload.FilterConfigurable || req.Payload.Context == "provisioning"
	if filterConfigurable {
		interfaces = filterConfigurableInterfaces(interfaces)
	}

	payload := map[string]interface{}{
		"router_id":        req.RouterID,
		"status":           "online",
		"board_name":       valueOrDefault(resource["board-name"], "Unknown"),
		"model":            valueOrDefault(resource["board-name"], "Unknown"),
		"version":          valueOrDefault(resource["version"], "Unknown"),
		"os_version":       valueOrDefault(resource["version"], "Unknown"),
		"uptime":           valueOrDefault(resource["uptime"], "Unknown"),
		"identity":         valueOrDefault(identity["name"], "Unknown"),
		"interfaces":       interfaces,
		"interface_count":  len(interfaces),
		"interfaces_count": len(interfaces),
		"last_seen":        time.Now(),
		"method":           "provisioning_service_command",
	}
	if filterConfigurable && len(interfaces) == 0 {
		h.reportCommandEvent(req, "failed", 100, "No configurable interfaces found on router", payload, "no configurable interfaces found", "discovering_interfaces", true)
		return
	}

	h.reportCommandEvent(req, "completed", 100, "Live data fetched successfully", payload, "", "completed", true)
}

func (h *Handler) connectSSH(connection models.ConnectionConfig) (routerSSHClient, error) {
	host := strings.TrimSpace(connection.VPNIP)
	if host == "" {
		host = strings.TrimSpace(connection.IPAddress)
	}
	if host == "" {
		return nil, fmt.Errorf("missing router IP address")
	}
	port := connection.SSHPort
	if port <= 0 {
		port = 22
	}
	client := ssh.NewClient(host, port, connection.Username, connection.Password, 20*time.Second)
	if err := client.Connect(); err != nil {
		return nil, err
	}
	return client, nil
}

func connectionConfigFromMap(configuration map[string]interface{}) (models.ConnectionConfig, error) {
	connection := models.ConnectionConfig{SSHPort: 22}
	if ipAddress, ok := configuration["ip_address"].(string); ok {
		connection.IPAddress = ipAddress
	}
	if vpnIP, ok := configuration["vpn_ip"].(string); ok {
		connection.VPNIP = vpnIP
	}
	if username, ok := configuration["username"].(string); ok {
		connection.Username = username
	}
	if password, ok := configuration["password"].(string); ok {
		connection.Password = password
	}
	if sshPort, ok := configuration["ssh_port"].(float64); ok && int(sshPort) > 0 {
		connection.SSHPort = int(sshPort)
	}
	if sshPort, ok := configuration["ssh_port"].(int); ok && sshPort > 0 {
		connection.SSHPort = sshPort
	}
	if strings.TrimSpace(connection.IPAddress) == "" && strings.TrimSpace(connection.VPNIP) == "" {
		return connection, fmt.Errorf("missing router IP address")
	}
	if strings.TrimSpace(connection.Username) == "" || strings.TrimSpace(connection.Password) == "" {
		return connection, fmt.Errorf("missing router credentials")
	}
	return connection, nil
}

func executeRouterScript(client routerSSHClient, script string) ([]models.CommandResult, error) {
	executableScript := normalizeExecutableScript(script)
	started := time.Now()
	output, err := client.ExecuteScript(executableScript)
	result := models.CommandResult{
		Command:  summarizeScript(executableScript),
		Output:   output,
		Duration: time.Since(started).Milliseconds(),
	}
	if err != nil {
		result.Error = err.Error()
		return []models.CommandResult{result}, err
	}
	return []models.CommandResult{result}, nil
}

func normalizeExecutableScript(script string) string {
	lines := make([]string, 0)
	for _, line := range strings.Split(script, "\n") {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") {
			continue
		}
		lines = append(lines, line)
	}
	return strings.TrimSpace(strings.Join(lines, "\n"))
}

func countExecutableScriptLines(script string) int {
	count := 0
	for _, line := range strings.Split(script, "\n") {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") {
			continue
		}
		count++
	}
	return count
}

func sanitizeCommands(commands []string) []string {
	filtered := make([]string, 0, len(commands))
	for _, command := range commands {
		trimmed := strings.TrimSpace(command)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") {
			continue
		}
		filtered = append(filtered, command)
	}
	return filtered
}

func summarizeScript(script string) string {
	for _, line := range strings.Split(script, "\n") {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") {
			continue
		}
		if len(trimmed) > 96 {
			return trimmed[:96] + "..."
		}
		return trimmed
	}
	return "[routeros-script]"
}

func parseSingleKeyValueBlock(output string) map[string]string {
	result := map[string]string{}
	for _, line := range strings.Split(output, "\n") {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, ";;;") {
			continue
		}
		parts := strings.SplitN(trimmed, ":", 2)
		if len(parts) != 2 {
			continue
		}
		key := strings.TrimSpace(parts[0])
		value := strings.TrimSpace(parts[1])
		if key != "" {
			result[key] = value
		}
	}
	return result
}

func parseInterfaces(output string) []map[string]string {
	interfaces := make([]map[string]string, 0)
	lines := strings.Split(output, "\n")
	var current map[string]string
	startPattern := regexp.MustCompile(`^\d+\s+.*name="([^"]+)"`)
	typePattern := regexp.MustCompile(`\btype=([^\s]+)`)
	mtuPattern := regexp.MustCompile(`\bmtu=(\d+)`)
	commentPattern := regexp.MustCompile(`\bcomment="([^"]*)"`)
	dynamicPattern := regexp.MustCompile(`\bdynamic=(yes|true)`)
	disabledPattern := regexp.MustCompile(`\bdisabled=(yes|true)`)
	masterPattern := regexp.MustCompile(`\bmaster-port=([^\s]+)`)
	slavePattern := regexp.MustCompile(`\bslave=(yes|true)`)
	for _, rawLine := range lines {
		line := strings.TrimSpace(rawLine)
		if line == "" || strings.HasPrefix(line, ";;;") {
			continue
		}
		if matches := startPattern.FindStringSubmatch(line); matches != nil {
			if current != nil {
				interfaces = append(interfaces, current)
			}
			current = map[string]string{"name": matches[1], "type": "ether", "running": "false", "mtu": "1500", "comment": ""}
			if strings.Contains(rawLine, " R ") || strings.Contains(rawLine, "running=yes") || strings.Contains(rawLine, "running=true") {
				current["running"] = "true"
			}
		}
		if current == nil {
			continue
		}
		if matches := typePattern.FindStringSubmatch(line); matches != nil {
			current["type"] = matches[1]
		}
		if matches := mtuPattern.FindStringSubmatch(line); matches != nil {
			current["mtu"] = matches[1]
		}
		if matches := commentPattern.FindStringSubmatch(line); matches != nil {
			current["comment"] = matches[1]
		}
		if matches := masterPattern.FindStringSubmatch(line); matches != nil {
			current["master-port"] = matches[1]
		}
		if dynamicPattern.MatchString(line) {
			current["dynamic"] = "true"
		}
		if disabledPattern.MatchString(line) {
			current["disabled"] = "true"
		}
		if slavePattern.MatchString(line) {
			current["slave"] = "true"
		}
	}
	if current != nil {
		interfaces = append(interfaces, current)
	}
	return interfaces
}

func filterConfigurableInterfaces(interfaces []map[string]string) []map[string]string {
	filtered := make([]map[string]string, 0, len(interfaces))
	excludedTypes := map[string]bool{"bridge": true, "vlan": true, "vrrp": true, "vpls": true, "ovpn-out": true, "ovpn-in": true, "wg": true, "gre": true, "ipip": true, "eoip": true}
	for _, iface := range interfaces {
		ifaceType := strings.ToLower(strings.TrimSpace(iface["type"]))
		if excludedTypes[ifaceType] {
			continue
		}
		if iface["dynamic"] == "true" || iface["disabled"] == "true" || iface["slave"] == "true" {
			continue
		}
		if strings.TrimSpace(iface["master-port"]) != "" {
			continue
		}
		filtered = append(filtered, iface)
	}
	return filtered
}

func parseCount(output string) int {
	digitsOnly := regexp.MustCompile(`[^0-9]`).ReplaceAllString(output, "")
	if digitsOnly == "" {
		return 0
	}
	count, err := strconv.Atoi(digitsOnly)
	if err != nil {
		return 0
	}
	return count
}

func parseOptionalInt(value string) interface{} {
	value = strings.TrimSpace(value)
	if value == "" {
		return nil
	}
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return nil
	}
	return parsed
}

type wireGuardDumpPeer struct {
	PublicKey       string
	Endpoint        string
	AllowedIPs      string
	LatestHandshake *time.Time
	TransferRX      int64
	TransferTX      int64
}

func (h *Handler) executeRefreshRouterStatusCommand(req models.CommandRequest) {
	h.reportCommandEvent(req, "running", 10, "Evaluating router status snapshot", nil, "", "evaluating_router_status", false)
	updates, err := h.collectRouterStatusUpdates(req.Payload.Monitoring)
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Failed to evaluate router status snapshot", nil, err.Error(), "evaluating_router_status", true)
		return
	}

	payload := map[string]interface{}{
		"routers":       updates,
		"observed_at":   time.Now(),
		"router_count":  len(updates),
		"command_scope": "tenant_router_status",
	}
	h.reportCommandEvent(req, "completed", 100, "Router status snapshot evaluated successfully", payload, "", "completed", true)
}

func (h *Handler) collectRouterStatusUpdates(payload models.MonitoringPayload) ([]map[string]interface{}, error) {
	controllerURL := strings.TrimRight(strings.TrimSpace(os.Getenv("WIREGUARD_CONTROLLER_URL")), "/")
	apiKey := strings.TrimSpace(os.Getenv("WIREGUARD_API_KEY"))
	inactiveThreshold := payload.InactiveThreshold
	if inactiveThreshold <= 0 {
		inactiveThreshold = 190
	}
	gracePeriod := payload.OfflineGracePeriod
	if gracePeriod <= 0 {
		gracePeriod = 60
	}
	now := time.Now().UTC()
	updates := make([]map[string]interface{}, 0, len(payload.Routers))
	for _, router := range payload.Routers {
		phase := determineMonitoringPhase(router)
		if phase == "provisioning" {
			if controllerURL == "" || strings.TrimSpace(router.ClientIP) == "" {
				continue
			}
			online, latency, pingErr := pingWireGuardPeer(controllerURL, apiKey, router.ClientIP)
			if pingErr != nil || !online {
				continue
			}
			status := router.Status
			if strings.TrimSpace(status) == "pending" {
				status = "provisioning"
			}
			updates = append(updates, map[string]interface{}{
				"router_id":          router.RouterID,
				"name":               router.RouterName,
				"ip_address":         router.IPAddress,
				"vpn_ip":             router.VPNIP,
				"status":             status,
				"phase":              phase,
				"provisioning_stage": firstNonEmpty(router.ProvisioningStage, "ping_verified"),
				"discovery_required": true,
				"latency_ms":         latency,
				"observed_at":        now.Format(time.RFC3339),
			})
			continue
		}

		status := "offline"
		vpnStatus := "inactive"
		var handshake *time.Time
		if !router.VPNLastHandshake.IsZero() {
			t := router.VPNLastHandshake.UTC()
			handshake = &t
			handshakeAge := int(now.Sub(t).Seconds())
			if handshakeAge < 0 {
				handshakeAge = -handshakeAge
			}
			if handshakeAge <= inactiveThreshold {
				status = "online"
				vpnStatus = "active"
			} else if strings.TrimSpace(router.Status) == "online" && !router.LastSeen.IsZero() {
				secondsSinceLastSeen := int(now.Sub(router.LastSeen.UTC()).Seconds())
				if secondsSinceLastSeen < 0 {
					secondsSinceLastSeen = -secondsSinceLastSeen
				}
				if secondsSinceLastSeen < inactiveThreshold+gracePeriod {
					status = "online"
					vpnStatus = "active"
				}
			}
		}
		entry := map[string]interface{}{
			"router_id":   router.RouterID,
			"name":        router.RouterName,
			"ip_address":  router.IPAddress,
			"vpn_ip":      router.VPNIP,
			"status":      status,
			"vpn_status":  vpnStatus,
			"phase":       phase,
			"model":       router.Model,
			"os_version":  router.OSVersion,
			"observed_at": now.Format(time.RFC3339),
		}
		if handshake != nil {
			entry["vpn_last_handshake"] = handshake.Format(time.RFC3339)
		}
		updates = append(updates, entry)
	}
	return updates, nil
}

func determineMonitoringPhase(router models.MonitoringRouter) string {
	provisioningStatuses := map[string]bool{
		"pending":      true,
		"deploying":    true,
		"provisioning": true,
		"verifying":    true,
	}
	status := strings.TrimSpace(router.Status)
	if provisioningStatuses[status] {
		return "provisioning"
	}
	if status == "offline" && router.LastSeen.IsZero() {
		return "provisioning"
	}
	return "operational"
}

func pingWireGuardPeer(controllerURL string, apiKey string, ip string) (bool, int, error) {
	payload, err := json.Marshal(map[string]interface{}{
		"ip":       ip,
		"timeout":  3,
		"attempts": 3,
	})
	if err != nil {
		return false, 0, err
	}
	req, err := http.NewRequest(http.MethodPost, controllerURL+"/vpn/ping", bytes.NewReader(payload))
	if err != nil {
		return false, 0, err
	}
	req.Header.Set("Content-Type", "application/json")
	if apiKey != "" {
		req.Header.Set("Authorization", "Bearer "+apiKey)
	}
	resp, err := (&http.Client{Timeout: 15 * time.Second}).Do(req)
	if err != nil {
		return false, 0, err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 400 {
		return false, 0, fmt.Errorf("controller returned status %d", resp.StatusCode)
	}
	var body map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&body); err != nil {
		return false, 0, err
	}
	success, _ := body["success"].(bool)
	latency := 0
	if rawLatency, ok := body["latency_ms"]; ok {
		switch v := rawLatency.(type) {
		case float64:
			latency = int(v)
		case int:
			latency = v
		}
	}
	return success, latency, nil
}

func firstNonEmpty(values ...string) string {
	for _, value := range values {
		if strings.TrimSpace(value) != "" {
			return value
		}
	}
	return ""
}

func (h *Handler) executeRefreshLiveDataCommand(req models.CommandRequest) {
	h.reportCommandEvent(req, "running", 10, "Collecting router live data", nil, "", "collecting_live_data", false)
	updates, err := collectLiveDataUpdates(req.TenantID, req.Payload.Monitoring)
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Failed to collect router live data", nil, err.Error(), "collecting_live_data", true)
		return
	}
	payload := map[string]interface{}{
		"routers":       updates,
		"observed_at":   time.Now(),
		"router_count":  len(updates),
		"command_scope": "tenant_live_data",
	}
	h.reportCommandEvent(req, "completed", 100, "Router live data collected successfully", payload, "", "completed", true)
}

func collectLiveDataUpdates(tenantID string, payload models.MonitoringPayload) ([]map[string]interface{}, error) {
	routerIDs := make([]string, 0, len(payload.Routers))
	for _, router := range payload.Routers {
		if strings.TrimSpace(router.RouterID) == "" {
			continue
		}
		routerIDs = append(routerIDs, router.RouterID)
	}
	if len(routerIDs) == 0 {
		return []map[string]interface{}{}, nil
	}
	batch, err := fetchLatestRouterMetrics(tenantID, routerIDs)
	if err != nil {
		return nil, err
	}
	updates := make([]map[string]interface{}, 0, len(routerIDs))
	for _, router := range payload.Routers {
		data := batch[router.RouterID]
		if len(data) == 0 {
			continue
		}
		updates = append(updates, map[string]interface{}{
			"router_id": router.RouterID,
			"data":      data,
		})
	}
	return updates, nil
}

func fetchLatestRouterMetrics(tenantID string, routerIDs []string) (map[string]map[string]interface{}, error) {
	baseURL := getVictoriaMetricsBaseURL()
	if baseURL == "" {
		return nil, fmt.Errorf("VictoriaMetrics query URL is not configured")
	}
	selector := buildRouterMetricsSelector(tenantID, routerIDs)
	diskType := `(^([.]?1[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]4|iso[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]4)$|hrStorageFixedDisk|HOST-RESOURCES-MIB::hrStorageFixedDisk)`
	ramType := `(^([.]?1[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]2|iso[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]2)$|hrStorageRam|HOST-RESOURCES-MIB::hrStorageRam)`
	queries := map[string][]string{
		"cpu_load":           {fmt.Sprintf("router_health_cpu_load{%s}", selector), fmt.Sprintf("avg by (router_id) (cpu_hrProcessorLoad{%s})", selector)},
		"total_memory":       {fmt.Sprintf("router_health_total_memory{%s}", selector)},
		"total_memory_kb":    {fmt.Sprintf("router_health_total_memory_kb{%s}", selector)},
		"free_memory":        {fmt.Sprintf("router_health_free_memory{%s}", selector)},
		"uptime_ticks":       {fmt.Sprintf("router_health_uptime_ticks{%s}", selector)},
		"pppoe_sessions":     {fmt.Sprintf("router_health_pppoe_sessions{%s}", selector)},
		"hotspot_active":     {fmt.Sprintf("router_health_hotspot_active{%s}", selector)},
		"wireless_clients":   {fmt.Sprintf("router_health_wireless_clients{%s}", selector)},
		"dhcp_leases":        {fmt.Sprintf("router_health_dhcp_leases{%s}", selector)},
		"interface_count":    {fmt.Sprintf("router_health_interface_count{%s}", selector)},
		"disk_total_bytes":   {buildStorageBytesQuery("storage", "hrStorageSize", selector, diskType), buildStorageBytesQuery("router_storage", "hrStorageSize", selector, diskType)},
		"disk_used_bytes":    {buildStorageBytesQuery("storage", "hrStorageUsed", selector, diskType), buildStorageBytesQuery("router_storage", "hrStorageUsed", selector, diskType)},
		"memory_total_bytes": {buildStorageBytesQuery("storage", "hrStorageSize", selector, ramType), buildStorageBytesQuery("router_storage", "hrStorageSize", selector, ramType)},
		"memory_used_bytes":  {buildStorageBytesQuery("storage", "hrStorageUsed", selector, ramType), buildStorageBytesQuery("router_storage", "hrStorageUsed", selector, ramType)},
	}
	live := map[string]map[string]interface{}{}
	for _, id := range routerIDs {
		live[id] = map[string]interface{}{}
	}
	for field, promqls := range queries {
		missing := map[string]bool{}
		for _, id := range routerIDs {
			missing[id] = true
		}
		for idx, promql := range promqls {
			response, err := queryVictoriaMetricsInstant(baseURL, promql)
			if err != nil {
				if idx == 0 {
					return nil, err
				}
				continue
			}
			missing = applyMetricsInstantResult(response, live, field, missing)
			if len(missing) == 0 {
				break
			}
		}
	}
	for _, rid := range routerIDs {
		finalizeRouterMetricsLiveData(live[rid])
	}
	return live, nil
}

func getVictoriaMetricsBaseURL() string {
	if explicit := strings.TrimSpace(os.Getenv("VICTORIAMETRICS_QUERY_URL")); explicit != "" {
		return strings.TrimRight(explicit, "/")
	}
	writeURL := strings.TrimSpace(os.Getenv("VICTORIAMETRICS_WRITE_URL"))
	if writeURL == "" {
		writeURL = "http://wificore-victoriametrics:8428"
	}
	writeURL = strings.TrimRight(writeURL, "/")
	return strings.TrimSuffix(writeURL, "/api/v1/write")
}

func buildRouterMetricsSelector(tenantID string, routerIDs []string) string {
	selectorTenant := fmt.Sprintf(`tenant_id="%s"`, escapeLabelValue(tenantID))
	if len(routerIDs) == 1 {
		return fmt.Sprintf(`%s,router_id="%s"`, selectorTenant, escapeLabelValue(routerIDs[0]))
	}
	routerIDRegex := "^(?:" + strings.Join(mapStrings(routerIDs, escapeRegexValue), "|") + ")$"
	return fmt.Sprintf(`%s,router_id=~"%s"`, selectorTenant, escapeLabelValue(routerIDRegex))
}

func mapStrings(values []string, mapper func(string) string) []string {
	mapped := make([]string, 0, len(values))
	for _, value := range values {
		mapped = append(mapped, mapper(value))
	}
	return mapped
}

func queryVictoriaMetricsInstant(baseURL string, promql string) (map[string]interface{}, error) {
	queryString := "query=" + url.QueryEscape(promql)
	resp, err := (&http.Client{Timeout: 5 * time.Second}).Get(strings.TrimRight(baseURL, "/") + "/api/v1/query?" + queryString)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 400 {
		return nil, fmt.Errorf("VictoriaMetrics query failed with status %d", resp.StatusCode)
	}
	var body map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&body); err != nil {
		return nil, err
	}
	return body, nil
}

func applyMetricsInstantResult(response map[string]interface{}, live map[string]map[string]interface{}, field string, missing map[string]bool) map[string]bool {
	data, _ := response["data"].(map[string]interface{})
	results, _ := data["result"].([]interface{})
	for _, rawSeries := range results {
		series, _ := rawSeries.(map[string]interface{})
		metric, _ := series["metric"].(map[string]interface{})
		routerID, _ := metric["router_id"].(string)
		if routerID == "" || live[routerID] == nil || !missing[routerID] {
			continue
		}
		value := extractPrometheusValue(series)
		if value == nil {
			continue
		}
		live[routerID][field] = *value
		delete(missing, routerID)
	}
	return missing
}

func extractPrometheusValue(series map[string]interface{}) *int {
	values, ok := series["value"].([]interface{})
	if !ok || len(values) < 2 {
		return nil
	}
	raw := fmt.Sprintf("%v", values[1])
	if raw == "" {
		return nil
	}
	parsed, err := strconv.ParseFloat(raw, 64)
	if err != nil {
		return nil
	}
	value := int(parsed + 0.5)
	return &value
}

func finalizeRouterMetricsLiveData(data map[string]interface{}) {
	if data == nil {
		return
	}
	if total, ok := data["disk_total_bytes"].(int); ok {
		if used, ok := data["disk_used_bytes"].(int); ok {
			free := total - used
			if free < 0 {
				free = 0
			}
			data["total_hdd_space"] = total
			data["free_hdd_space"] = free
		}
	}
	delete(data, "disk_total_bytes")
	delete(data, "disk_used_bytes")
	if totalMemory, ok := data["total_memory"].(int); ok && totalMemory <= 0 {
		delete(data, "total_memory")
	}
	if freeMemory, ok := data["free_memory"].(int); ok && freeMemory <= 0 {
		delete(data, "free_memory")
	}
	if _, ok := data["total_memory"]; !ok {
		if totalBytes, ok := data["memory_total_bytes"].(int); ok && totalBytes >= 0 {
			data["total_memory"] = totalBytes
		} else if totalKB, ok := data["total_memory_kb"].(int); ok && totalKB >= 0 {
			data["total_memory"] = totalKB * 1024
		}
	}
	if _, ok := data["free_memory"]; !ok {
		if usedBytes, ok := data["memory_used_bytes"].(int); ok && usedBytes >= 0 {
			totalForFree := 0
			if totalBytes, ok := data["memory_total_bytes"].(int); ok && totalBytes >= 0 {
				totalForFree = totalBytes
			} else if total, ok := data["total_memory"].(int); ok && total >= 0 {
				totalForFree = total
			}
			if totalForFree > 0 {
				free := totalForFree - usedBytes
				if free < 0 {
					free = 0
				}
				data["free_memory"] = free
			}
		}
	}
	delete(data, "total_memory_kb")
	delete(data, "memory_total_bytes")
	delete(data, "memory_used_bytes")
	if uptimeTicks, ok := data["uptime_ticks"].(int); ok && uptimeTicks >= 0 {
		data["uptime"] = formatUptimeFromTicks(uptimeTicks)
	}
	pppoe, _ := data["pppoe_sessions"].(int)
	hotspot, _ := data["hotspot_active"].(int)
	wireless, _ := data["wireless_clients"].(int)
	if _, ok := data["pppoe_sessions"]; ok || data["hotspot_active"] != nil || data["wireless_clients"] != nil {
		data["active_connections"] = pppoe + hotspot + wireless
	}
}

func formatUptimeFromTicks(ticks int) string {
	seconds := ticks / 100
	days := seconds / 86400
	hours := (seconds % 86400) / 3600
	minutes := (seconds % 3600) / 60
	secs := seconds % 60
	if days > 0 {
		return fmt.Sprintf("%dd %dh", days, hours)
	}
	if hours > 0 {
		return fmt.Sprintf("%dh %dm", hours, minutes)
	}
	return fmt.Sprintf("%dm %ds", minutes, secs)
}

func buildStorageBytesQuery(prefix string, valueField string, selector string, storageTypePattern string) string {
	allocUnits := fmt.Sprintf("%s_hrStorageAllocationUnits", prefix)
	values := fmt.Sprintf("%s_%s", prefix, valueField)
	return fmt.Sprintf(
		`max by (tenant_id, router_id) (%s{%s,hrStorageType=~"%s"} * on (tenant_id, router_id, hrStorageIndex) group_left %s{%s,hrStorageType=~"%s"})`,
		allocUnits,
		selector,
		storageTypePattern,
		values,
		selector,
		storageTypePattern,
	)
}

func escapeLabelValue(value string) string {
	replacer := strings.NewReplacer(`\`, `\\`, `"`, `\"`)
	return replacer.Replace(value)
}

func escapeRegexValue(value string) string {
	return regexp.MustCompile(`([\.^$|?*+()\[\]{}])`).ReplaceAllString(value, `\$1`)
}

func (h *Handler) executeComputeRouterMetricsCommand(req models.CommandRequest) {
	h.reportCommandEvent(req, "running", 10, "Preparing router metrics computation", nil, "", "submitted", false)

	routerIDs := collectRouterIDs(req.Payload.Monitoring.Routers)
	if len(routerIDs) == 0 {
		h.reportCommandEvent(req, "failed", 100, "No routers provided for metrics computation", nil, "missing router snapshots", "submitted", true)
		return
	}

	timeRanges := sanitizeMetricsTimeRanges(req.Payload.Metrics.TimeRanges)
	if len(timeRanges) == 0 {
		h.reportCommandEvent(req, "failed", 100, "No valid metrics time ranges provided", nil, "missing metrics time ranges", "submitted", true)
		return
	}

	baseURL := getVictoriaMetricsBaseURL()
	if baseURL == "" {
		h.reportCommandEvent(req, "failed", 100, "VictoriaMetrics query URL is not configured", nil, "missing VictoriaMetrics configuration", "submitted", true)
		return
	}

	ranges := make([]map[string]interface{}, 0, len(timeRanges))
	for idx, timeRange := range timeRanges {
		progress := 10 + int(float64(idx+1)/float64(len(timeRanges))*70)
		h.reportCommandEvent(req, "running", progress, "Computing router metrics for range "+timeRange, map[string]interface{}{"time_range": timeRange}, "", "computing_metrics", false)

		traffic, err := fetchRouterTrafficRangeMetrics(baseURL, req.TenantID, routerIDs, timeRange)
		if err != nil {
			h.reportCommandEvent(req, "failed", 100, "Failed to compute router traffic metrics", map[string]interface{}{"time_range": timeRange}, err.Error(), "computing_metrics", true)
			return
		}

		resources, err := fetchRouterResourceRangeMetrics(baseURL, req.TenantID, routerIDs, timeRange)
		if err != nil {
			h.reportCommandEvent(req, "failed", 100, "Failed to compute router resource metrics", map[string]interface{}{"time_range": timeRange}, err.Error(), "computing_metrics", true)
			return
		}

		rangeRouters := make([]map[string]interface{}, 0, len(routerIDs))
		for _, routerID := range routerIDs {
			entry := map[string]interface{}{"router_id": routerID}
			if trafficPoints, ok := traffic[routerID]; ok && len(trafficPoints) > 0 {
				entry["traffic"] = trafficPoints
			}
			if resourcePoints, ok := resources[routerID]; ok && len(resourcePoints) > 0 {
				entry["resources"] = resourcePoints
			}
			if len(entry) > 1 {
				rangeRouters = append(rangeRouters, entry)
			}
		}

		ranges = append(ranges, map[string]interface{}{
			"time_range":  timeRange,
			"routers":     rangeRouters,
			"observed_at": time.Now().UTC(),
		})
	}

	payload := map[string]interface{}{
		"tenant_id":     req.TenantID,
		"router_count":  len(routerIDs),
		"time_ranges":   timeRanges,
		"ranges":        ranges,
		"observed_at":   time.Now().UTC(),
		"command_scope": "tenant_router_metrics",
	}
	h.reportCommandEvent(req, "completed", 100, "Router metrics computed successfully", payload, "", "completed", true)
}

func collectRouterIDs(routers []models.MonitoringRouter) []string {
	seen := map[string]bool{}
	ids := make([]string, 0, len(routers))
	for _, router := range routers {
		id := strings.TrimSpace(router.RouterID)
		if id == "" || seen[id] {
			continue
		}
		seen[id] = true
		ids = append(ids, id)
	}
	return ids
}

func sanitizeMetricsTimeRanges(values []string) []string {
	allowed := regexp.MustCompile(`^\d+[mhd]$`)
	seen := map[string]bool{}
	ranges := make([]string, 0, len(values))
	for _, value := range values {
		rangeValue := strings.TrimSpace(strings.ToLower(value))
		if rangeValue == "" || !allowed.MatchString(rangeValue) || seen[rangeValue] {
			continue
		}
		seen[rangeValue] = true
		ranges = append(ranges, rangeValue)
	}
	return ranges
}

func getStepForRange(timeRange string) string {
	switch {
	case strings.HasSuffix(timeRange, "m"):
		return "15s"
	case strings.HasSuffix(timeRange, "h"):
		return "30s"
	case strings.HasSuffix(timeRange, "d"):
		return "5m"
	default:
		return "30s"
	}
}

func rangeStartFromNow(timeRange string, now int) int {
	timeRange = strings.TrimSpace(strings.ToLower(timeRange))
	switch {
	case strings.HasSuffix(timeRange, "m"):
		value, _ := strconv.Atoi(strings.TrimSuffix(timeRange, "m"))
		return maxInt(0, now-(value*60))
	case strings.HasSuffix(timeRange, "h"):
		value, _ := strconv.Atoi(strings.TrimSuffix(timeRange, "h"))
		return maxInt(0, now-(value*3600))
	case strings.HasSuffix(timeRange, "d"):
		value, _ := strconv.Atoi(strings.TrimSuffix(timeRange, "d"))
		return maxInt(0, now-(value*86400))
	default:
		return maxInt(0, now-3600)
	}
}

func maxInt(a int, b int) int {
	if a > b {
		return a
	}
	return b
}

func fetchRouterTrafficRangeMetrics(baseURL string, tenantID string, routerIDs []string, timeRange string) (map[string][]trafficPoint, error) {
	step := getStepForRange(timeRange)
	end := int(time.Now().UTC().Unix())
	start := rangeStartFromNow(timeRange, end)
	selector := buildRouterMetricsSelector(tenantID, routerIDs)

	inPrimary := fmt.Sprintf("sum by (router_id) (rate(interface_ifHCInOctets{%s}[1m]))", selector)
	inFallbacks := []string{
		fmt.Sprintf("sum by (router_id) (rate(interface_ifInOctets{%s}[1m]))", selector),
		fmt.Sprintf("sum by (router_id) (rate(interface_counters_ifHCInOctets{%s}[1m]))", selector),
		fmt.Sprintf("sum by (router_id) (rate(interface_counters_ifInOctets{%s}[1m]))", selector),
	}
	outPrimary := fmt.Sprintf("sum by (router_id) (rate(interface_ifHCOutOctets{%s}[1m]))", selector)
	outFallbacks := []string{
		fmt.Sprintf("sum by (router_id) (rate(interface_ifOutOctets{%s}[1m]))", selector),
		fmt.Sprintf("sum by (router_id) (rate(interface_counters_ifHCOutOctets{%s}[1m]))", selector),
		fmt.Sprintf("sum by (router_id) (rate(interface_counters_ifOutOctets{%s}[1m]))", selector),
	}

	inResponse, err := queryVictoriaMetricsRangeWithFallback(baseURL, inPrimary, inFallbacks, start, end, step)
	if err != nil {
		return nil, err
	}
	outResponse, err := queryVictoriaMetricsRangeWithFallback(baseURL, outPrimary, outFallbacks, start, end, step)
	if err != nil {
		return nil, err
	}

	inResults := extractRouterRangeSeriesData(inResponse)
	outResults := extractRouterRangeSeriesData(outResponse)

	results := make(map[string][]trafficPoint, len(routerIDs))
	for _, routerID := range routerIDs {
		results[routerID] = alignTrafficRangeData(inResults[routerID], outResults[routerID])
	}

	return results, nil
}

func fetchRouterResourceRangeMetrics(baseURL string, tenantID string, routerIDs []string, timeRange string) (map[string]map[string][]metricPoint, error) {
	step := getStepForRange(timeRange)
	end := int(time.Now().UTC().Unix())
	start := rangeStartFromNow(timeRange, end)
	selector := buildRouterMetricsSelector(tenantID, routerIDs)
	diskType := `(^([.]?1[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]4|iso[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]4)$|hrStorageFixedDisk|HOST-RESOURCES-MIB::hrStorageFixedDisk)`
	ramType := `(^([.]?1[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]2|iso[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]2)$|hrStorageRam|HOST-RESOURCES-MIB::hrStorageRam)`

	cpuPrimary := fmt.Sprintf("router_health_cpu_load{%s}", selector)
	cpuFallback := fmt.Sprintf("avg by (router_id) (cpu_hrProcessorLoad{%s})", selector)
	cpuResponse, err := queryVictoriaMetricsRangeWithFallback(baseURL, cpuPrimary, []string{cpuFallback}, start, end, step)
	if err != nil {
		return nil, err
	}

	memPrimary := fmt.Sprintf("100 - ((router_health_free_memory{%s} / router_health_total_memory{%s}) * 100)", selector, selector)
	memFallback := fmt.Sprintf(`(max by (tenant_id, router_id) (storage_hrStorageAllocationUnits{%s,hrStorageType=~"%s"} * on (tenant_id, router_id, hrStorageIndex) group_left storage_hrStorageUsed{%s,hrStorageType=~"%s"}) / max by (tenant_id, router_id) (storage_hrStorageAllocationUnits{%s,hrStorageType=~"%s"} * on (tenant_id, router_id, hrStorageIndex) group_left storage_hrStorageSize{%s,hrStorageType=~"%s"})) * 100`, selector, ramType, selector, ramType, selector, ramType, selector, ramType)
	memResponse, err := queryVictoriaMetricsRangeWithFallback(baseURL, memPrimary, []string{memFallback}, start, end, step)
	if err != nil {
		return nil, err
	}

	diskPrimary := fmt.Sprintf(`(max by (tenant_id, router_id) (storage_hrStorageAllocationUnits{%s,hrStorageType=~"%s"} * on (tenant_id, router_id, hrStorageIndex) group_left storage_hrStorageUsed{%s,hrStorageType=~"%s"}) / max by (tenant_id, router_id) (storage_hrStorageAllocationUnits{%s,hrStorageType=~"%s"} * on (tenant_id, router_id, hrStorageIndex) group_left storage_hrStorageSize{%s,hrStorageType=~"%s"})) * 100`, selector, diskType, selector, diskType, selector, diskType, selector, diskType)
	diskResponse, err := queryVictoriaMetricsRangeWithFallback(baseURL, diskPrimary, nil, start, end, step)
	if err != nil {
		return nil, err
	}

	cpuResults := extractRouterRangeSeriesData(cpuResponse)
	memResults := extractRouterRangeSeriesData(memResponse)
	diskResults := extractRouterRangeSeriesData(diskResponse)

	results := make(map[string]map[string][]metricPoint, len(routerIDs))
	for _, routerID := range routerIDs {
		results[routerID] = map[string][]metricPoint{
			"cpu":    cpuResults[routerID],
			"memory": memResults[routerID],
			"disk":   diskResults[routerID],
		}
	}

	return results, nil
}

func queryVictoriaMetricsRangeWithFallback(baseURL string, primary string, fallbacks []string, start int, end int, step string) (map[string]interface{}, error) {
	response, err := queryVictoriaMetricsRange(baseURL, primary, start, end, step)
	if err != nil {
		return nil, err
	}
	if countRangeResults(response) > 0 || len(fallbacks) == 0 {
		return response, nil
	}
	for _, fallback := range fallbacks {
		fallbackResponse, fallbackErr := queryVictoriaMetricsRange(baseURL, fallback, start, end, step)
		if fallbackErr != nil {
			continue
		}
		if countRangeResults(fallbackResponse) > 0 {
			return fallbackResponse, nil
		}
		response = fallbackResponse
	}
	return response, nil
}

func countRangeResults(response map[string]interface{}) int {
	data, _ := response["data"].(map[string]interface{})
	results, _ := data["result"].([]interface{})
	return len(results)
}

func queryVictoriaMetricsRange(baseURL string, promql string, start int, end int, step string) (map[string]interface{}, error) {
	queryString := url.Values{}
	queryString.Set("query", promql)
	queryString.Set("start", strconv.Itoa(start))
	queryString.Set("end", strconv.Itoa(end))
	queryString.Set("step", step)
	resp, err := (&http.Client{Timeout: 10 * time.Second}).Get(strings.TrimRight(baseURL, "/") + "/api/v1/query_range?" + queryString.Encode())
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 400 {
		return nil, fmt.Errorf("VictoriaMetrics range query failed with status %d", resp.StatusCode)
	}
	var body map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&body); err != nil {
		return nil, err
	}
	return body, nil
}

func extractRouterRangeSeriesData(response map[string]interface{}) map[string][]metricPoint {
	results := map[string][]metricPoint{}
	data, _ := response["data"].(map[string]interface{})
	seriesList, _ := data["result"].([]interface{})
	for _, rawSeries := range seriesList {
		series, _ := rawSeries.(map[string]interface{})
		metric, _ := series["metric"].(map[string]interface{})
		routerID, _ := metric["router_id"].(string)
		if routerID == "" {
			continue
		}
		values, _ := series["values"].([]interface{})
		points := make([]metricPoint, 0, len(values))
		for _, rawPoint := range values {
			point, _ := rawPoint.([]interface{})
			if len(point) < 2 {
				continue
			}
			ts := 0
			switch v := point[0].(type) {
			case float64:
				ts = int(v)
			case int:
				ts = v
			case string:
				if parsed, err := strconv.ParseFloat(v, 64); err == nil {
					ts = int(parsed)
				}
			}
			if ts == 0 {
				continue
			}
			value := 0.0
			switch v := point[1].(type) {
			case float64:
				value = v
			case int:
				value = float64(v)
			case string:
				parsed, err := strconv.ParseFloat(v, 64)
				if err != nil {
					continue
				}
				value = parsed
			default:
				continue
			}
			points = append(points, metricPoint{Ts: ts, V: value})
		}
		results[routerID] = points
	}
	return results
}

func alignTrafficRangeData(inData []metricPoint, outData []metricPoint) []trafficPoint {
	aligned := map[int]*trafficPoint{}
	for _, point := range inData {
		entry, ok := aligned[point.Ts]
		if !ok {
			entry = &trafficPoint{Ts: point.Ts}
			aligned[point.Ts] = entry
		}
		entry.Upload = point.V
	}
	for _, point := range outData {
		entry, ok := aligned[point.Ts]
		if !ok {
			entry = &trafficPoint{Ts: point.Ts}
			aligned[point.Ts] = entry
		}
		entry.Download = point.V
	}
	results := make([]trafficPoint, 0, len(aligned))
	for _, point := range aligned {
		results = append(results, *point)
	}
	sort.Slice(results, func(i, j int) bool { return results[i].Ts < results[j].Ts })
	return results
}

func (h *Handler) executeWaitVPNConnectivityCommand(req models.CommandRequest) {
	if len(req.Payload.Monitoring.Routers) == 0 {
		h.reportCommandEvent(req, "failed", 100, "VPN connectivity verification payload missing router snapshot", nil, "missing router snapshot", "submitted", true)
		return
	}
	router := req.Payload.Monitoring.Routers[0]
	clientIP := strings.TrimSpace(router.ClientIP)
	if clientIP == "" {
		h.reportCommandEvent(req, "failed", 100, "VPN connectivity verification payload missing client IP", nil, "missing client IP", "submitted", true)
		return
	}
	maxWaitSeconds := req.Payload.Monitoring.MaxWaitSeconds
	retryInterval := req.Payload.Monitoring.RetryInterval
	maxAttempts := int(math.Ceil(float64(maxWaitSeconds) / float64(retryInterval)))
	if maxAttempts < 1 {
		maxAttempts = 1
	}
	controllerURL := strings.TrimRight(strings.TrimSpace(os.Getenv("WIREGUARD_CONTROLLER_URL")), "/")
	apiKey := strings.TrimSpace(os.Getenv("WIREGUARD_API_KEY"))
	if controllerURL == "" {
		h.reportCommandEvent(req, "failed", 100, "WireGuard controller URL is not configured", nil, "missing WIREGUARD_CONTROLLER_URL", "submitted", true)
		return
	}
	startedAt := time.Now()
	for attempt := 1; time.Since(startedAt) < time.Duration(maxWaitSeconds)*time.Second; attempt++ {
		progress := int(math.Min(95, math.Round((float64(attempt)/float64(maxAttempts))*100)))
		h.reportCommandEvent(req, "running", progress, "Checking VPN connectivity", map[string]interface{}{
			"router_id":     req.RouterID,
			"vpn_config_id": router.VPNConfigID,
			"client_ip":     clientIP,
			"attempt":       attempt,
			"max_attempts":  maxAttempts,
		}, "", "verifying_vpn", false)
		online, latency, err := pingWireGuardPeer(controllerURL, apiKey, clientIP)
		if err == nil && online {
			payload := map[string]interface{}{
				"router_id":     req.RouterID,
				"vpn_config_id": router.VPNConfigID,
				"client_ip":     clientIP,
				"latency_ms":    latency,
				"attempts":      attempt,
				"method":        "ping",
			}
			h.reportCommandEvent(req, "completed", 100, "VPN connectivity verified successfully", payload, "", "completed", true)
			return
		}
		if time.Since(startedAt)+time.Duration(retryInterval)*time.Second >= time.Duration(maxWaitSeconds)*time.Second {
			break
		}
		time.Sleep(time.Duration(retryInterval) * time.Second)
	}
	h.reportCommandEvent(req, "failed", 100, "VPN connectivity timeout", map[string]interface{}{
		"router_id":     req.RouterID,
		"vpn_config_id": router.VPNConfigID,
		"client_ip":     clientIP,
		"attempts":      maxAttempts,
	}, fmt.Sprintf("VPN connectivity timeout - router did not respond within %d seconds", maxWaitSeconds), "completed", true)
}

func (h *Handler) executeRefreshVPNStatusCommand(req models.CommandRequest) {
	h.reportCommandEvent(req, "running", 10, "Fetching WireGuard peer dump", nil, "", "collecting_peer_dump", false)
	updates, err := h.collectVPNStatusUpdates(req.Payload.Monitoring)
	if err != nil {
		h.reportCommandEvent(req, "failed", 100, "Failed to refresh WireGuard peer health", nil, err.Error(), "collecting_peer_dump", true)
		return
	}

	payload := map[string]interface{}{
		"routers":       updates,
		"observed_at":   time.Now(),
		"router_count":  len(updates),
		"tunnel_count":  len(req.Payload.Monitoring.Tunnels),
		"command_scope": "tenant_monitoring",
	}
	h.reportCommandEvent(req, "completed", 100, "WireGuard peer health refreshed successfully", payload, "", "completed", true)
}

func (h *Handler) collectVPNStatusUpdates(payload models.MonitoringPayload) ([]map[string]interface{}, error) {
	controllerURL := strings.TrimRight(strings.TrimSpace(os.Getenv("WIREGUARD_CONTROLLER_URL")), "/")
	apiKey := strings.TrimSpace(os.Getenv("WIREGUARD_API_KEY"))
	if controllerURL == "" {
		return nil, fmt.Errorf("WIREGUARD_CONTROLLER_URL is not configured")
	}

	threshold := payload.InactiveThreshold
	if threshold <= 0 {
		threshold = 190
	}

	seenPeers := map[string]wireGuardDumpPeer{}
	for _, tunnel := range payload.Tunnels {
		interfaceName := strings.TrimSpace(tunnel.InterfaceName)
		if interfaceName == "" {
			continue
		}
		dump, err := h.fetchWireGuardDump(controllerURL, apiKey, interfaceName)
		if err != nil {
			h.logger.WithError(err).WithField("interface", interfaceName).Warn("WireGuard controller dump request failed during monitoring refresh")
			continue
		}
		for _, peer := range parseWireGuardDump(dump) {
			seenPeers[peer.PublicKey] = peer
		}
	}

	now := time.Now().UTC()
	updates := make([]map[string]interface{}, 0, len(payload.PeerMappings))
	for _, mapping := range payload.PeerMappings {
		entry := map[string]interface{}{
			"public_key":         mapping.PublicKey,
			"router_id":          mapping.RouterID,
			"name":               mapping.RouterName,
			"ip_address":         mapping.IPAddress,
			"vpn_ip":             mapping.VPNIP,
			"model":              mapping.Model,
			"os_version":         mapping.OSVersion,
			"vpn_status":         "inactive",
			"status":             "offline",
			"vpn_config_status":  fallbackVPNConfigStatus(mapping.VPNConfigStatus),
			"vpn_last_handshake": nil,
			"transfer_rx":        int64(0),
			"transfer_tx":        int64(0),
		}

		if peer, ok := seenPeers[mapping.PublicKey]; ok {
			entry["endpoint"] = peer.Endpoint
			entry["allowed_ips"] = peer.AllowedIPs
			entry["transfer_rx"] = peer.TransferRX
			entry["transfer_tx"] = peer.TransferTX
			if peer.LatestHandshake != nil {
				handshake := peer.LatestHandshake.UTC()
				entry["vpn_last_handshake"] = handshake.Format(time.RFC3339)
				age := int(now.Sub(handshake).Seconds())
				if age < 0 {
					age = -age
				}
				entry["handshake_age_seconds"] = age
				if age <= threshold {
					entry["vpn_status"] = "active"
					entry["status"] = "online"
					entry["vpn_config_status"] = "connected"
				} else {
					entry["vpn_status"] = "inactive"
					entry["status"] = "offline"
					entry["vpn_config_status"] = "disconnected"
				}
			}
		}

		updates = append(updates, entry)
	}

	return updates, nil
}

func (h *Handler) fetchWireGuardDump(controllerURL string, apiKey string, interfaceName string) (string, error) {
	req, err := http.NewRequest(http.MethodGet, controllerURL+"/vpn/dump/"+interfaceName, nil)
	if err != nil {
		return "", err
	}
	if apiKey != "" {
		req.Header.Set("Authorization", "Bearer "+apiKey)
	}
	resp, err := (&http.Client{Timeout: 10 * time.Second}).Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 400 {
		return "", fmt.Errorf("controller returned status %d", resp.StatusCode)
	}
	var body map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&body); err != nil {
		return "", err
	}
	dump, _ := body["dump"].(string)
	return dump, nil
}

func parseWireGuardDump(output string) []wireGuardDumpPeer {
	lines := strings.Split(strings.TrimSpace(output), "\n")
	peers := make([]wireGuardDumpPeer, 0, len(lines))
	for _, line := range lines {
		parts := strings.Split(line, "\t")
		if len(parts) < 8 {
			continue
		}
		handshake := int64(0)
		if parsed, err := strconv.ParseInt(strings.TrimSpace(parts[4]), 10, 64); err == nil {
			handshake = parsed
		}
		var handshakeAt *time.Time
		if handshake > 0 {
			t := time.Unix(handshake, 0).UTC()
			handshakeAt = &t
		}
		rx, _ := strconv.ParseInt(strings.TrimSpace(parts[5]), 10, 64)
		tx, _ := strconv.ParseInt(strings.TrimSpace(parts[6]), 10, 64)
		peers = append(peers, wireGuardDumpPeer{
			PublicKey:       strings.TrimSpace(parts[0]),
			Endpoint:        strings.TrimSpace(parts[2]),
			AllowedIPs:      strings.TrimSpace(parts[3]),
			LatestHandshake: handshakeAt,
			TransferRX:      rx,
			TransferTX:      tx,
		})
	}
	return peers
}

func fallbackVPNConfigStatus(current string) string {
	if strings.TrimSpace(current) == "pending" {
		return "pending"
	}
	return "disconnected"
}

func (h *Handler) reportProvisioningWorkflowEvent(callback *models.TaskCallbackConfig, workflowKey string, routerID string, status string, progress int, message string, result map[string]interface{}, errorMessage string, stage string, terminal bool) {
	if terminal {
		if status == "completed" {
			_ = h.workflowStore.RecordCompletion(workflowKey, stage, progress, message, result)
		} else if status == "failed" {
			_ = h.workflowStore.RecordFailure(workflowKey, stage, progress, message, result, errorMessage)
		}
	} else {
		_ = h.workflowStore.RecordProgress(workflowKey, stage, progress, message, result)
	}

	payload := h.buildCallbackPayload(status, progress, message, result, errorMessage, stage, terminal)
	if callback == nil || strings.TrimSpace(callback.URL) == "" {
		return
	}
	if err := h.sendCallbackPayload(callback.URL, callback.APIKey, payload); err != nil {
		h.logger.WithError(err).WithField("workflow_key", workflowKey).Warn("Provisioning callback delivery failed, queued for retry")
		_ = h.workflowStore.EnqueueCallback(workflowKey, routerID, callback.URL, callback.APIKey, payload, err.Error())
	}
}

func (h *Handler) notifyTaskCallback(callback *models.TaskCallbackConfig, status string, progress int, message string, result map[string]interface{}, errorMessage string) {
	h.notifyTaskCallbackWithOptions(callback, status, progress, message, result, errorMessage, callback.Stage, callback.Terminal)
}

func (h *Handler) notifyTaskCallbackWithOptions(callback *models.TaskCallbackConfig, status string, progress int, message string, result map[string]interface{}, errorMessage string, stage string, terminal bool) {
	if callback == nil || strings.TrimSpace(callback.URL) == "" {
		return
	}
	payload := h.buildCallbackPayload(status, progress, message, result, errorMessage, stage, terminal)
	if err := h.sendCallbackPayload(callback.URL, callback.APIKey, payload); err != nil {
		h.logger.WithError(err).Warn("Task callback request failed")
	}
}

func (h *Handler) buildCallbackPayload(status string, progress int, message string, result map[string]interface{}, errorMessage string, stage string, terminal bool) map[string]interface{} {
	body := map[string]interface{}{
		"status":   status,
		"progress": progress,
		"message":  message,
		"terminal": terminal,
	}
	if stage != "" {
		body["stage"] = stage
	}
	if result != nil {
		body["result"] = result
	}
	if errorMessage != "" {
		body["error"] = errorMessage
	}
	return body
}

func (h *Handler) sendCallbackPayload(url string, apiKey string, body map[string]interface{}) error {
	payload, err := json.Marshal(body)
	if err != nil {
		return err
	}
	req, err := http.NewRequest(http.MethodPost, url, bytes.NewReader(payload))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	if apiKey != "" {
		req.Header.Set("X-API-Key", apiKey)
	}
	resp, err := (&http.Client{Timeout: 10 * time.Second}).Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 400 {
		return fmt.Errorf("callback returned status %d", resp.StatusCode)
	}
	return nil
}

func valueOrDefault(value string, fallback string) string {
	if strings.TrimSpace(value) == "" {
		return fallback
	}
	return value
}
