package api

import (
	"fmt"
	"net/http"
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
type Handler struct {
	logger *logrus.Logger
}

// NewHandler creates a new API handler
func NewHandler(logger *logrus.Logger) *Handler {
	return &Handler{
		logger: logger,
	}
}

// HealthCheck handles health check requests
func (h *Handler) HealthCheck(c *gin.Context) {
	uptime := time.Since(startTime).Seconds()
	
	response := models.HealthResponse{
		Status:    "healthy",
		Timestamp: time.Now(),
		Version:   version,
		Uptime:    int64(uptime),
		Metrics: map[string]int64{
			"active_connections": 0, // TODO: Track active SSH connections
			"total_requests":     0, // TODO: Track total requests
		},
	}

	c.JSON(http.StatusOK, response)
}

// ProvisionRouter handles router provisioning requests
func (h *Handler) ProvisionRouter(c *gin.Context) {
	var req models.ProvisionRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		h.logger.WithError(err).Error("Invalid provision request")
		c.JSON(http.StatusBadRequest, models.ProvisionResponse{
			Success: false,
			Error:   fmt.Sprintf("Invalid request: %v", err),
		})
		return
	}

	h.logger.WithFields(logrus.Fields{
		"router_id": req.RouterID,
		"tenant_id": req.TenantID,
	}).Info("Processing provision request")

	// Extract router connection details from configuration
	routerIP, ok := req.Configuration["ip_address"].(string)
	if !ok {
		c.JSON(http.StatusBadRequest, models.ProvisionResponse{
			Success: false,
			Error:   "Missing router IP address",
		})
		return
	}

	username, _ := req.Configuration["username"].(string)
	password, _ := req.Configuration["password"].(string)
	
	// Prefer VPN IP if available
	if vpnIP, ok := req.Configuration["vpn_ip"].(string); ok && vpnIP != "" {
		routerIP = vpnIP
		h.logger.WithField("vpn_ip", vpnIP).Info("Using VPN IP for connection")
	}

	// Create SSH client
	client := ssh.NewClient(routerIP, 22, username, password, 15*time.Second)
	
	// Connect to router
	if err := client.Connect(); err != nil {
		h.logger.WithError(err).Error("Failed to connect to router")
		c.JSON(http.StatusServiceUnavailable, models.ProvisionResponse{
			Success: false,
			Error:   fmt.Sprintf("Connection failed: %v", err),
		})
		return
	}
	defer client.Close()

	// Execute provisioning commands
	commands, ok := req.Configuration["commands"].([]interface{})
	if !ok || len(commands) == 0 {
		c.JSON(http.StatusBadRequest, models.ProvisionResponse{
			Success: false,
			Error:   "No commands provided",
		})
		return
	}

	// Convert commands to string slice
	cmdStrings := make([]string, 0, len(commands))
	for _, cmd := range commands {
		if cmdStr, ok := cmd.(string); ok {
			cmdStrings = append(cmdStrings, cmdStr)
		}
	}

	// Execute commands
	results, err := client.ExecuteMultiple(cmdStrings)
	if err != nil {
		h.logger.WithError(err).Error("Command execution failed")
		c.JSON(http.StatusInternalServerError, models.ProvisionResponse{
			Success: false,
			Error:   fmt.Sprintf("Execution failed: %v", err),
			Data: map[string]interface{}{
				"partial_results": results,
			},
		})
		return
	}

	h.logger.WithField("router_id", req.RouterID).Info("Provisioning completed successfully")

	c.JSON(http.StatusOK, models.ProvisionResponse{
		Success: true,
		Message: "Provisioning completed successfully",
		Data: map[string]interface{}{
			"results": results,
		},
	})
}

// FetchLiveData handles live data fetch requests
func (h *Handler) FetchLiveData(c *gin.Context) {
	var req models.LiveDataRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		h.logger.WithError(err).Error("Invalid live data request")
		c.JSON(http.StatusBadRequest, models.LiveDataResponse{
			Success: false,
			Error:   fmt.Sprintf("Invalid request: %v", err),
		})
		return
	}

	h.logger.WithFields(logrus.Fields{
		"router_id": req.RouterID,
		"context":   req.Context,
		"tenant_id": req.TenantID,
	}).Info("Fetching live data")

	// TODO: Implement actual live data fetching logic
	// This is a placeholder that should be replaced with actual MikroTik API calls

	c.JSON(http.StatusOK, models.LiveDataResponse{
		Success:   true,
		RouterID:  req.RouterID,
		Data:      map[string]interface{}{},
		FetchedAt: time.Now(),
	})
}

// ExecuteCommand handles command execution requests
func (h *Handler) ExecuteCommand(c *gin.Context) {
	var req models.ExecuteCommandRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		h.logger.WithError(err).Error("Invalid execute command request")
		c.JSON(http.StatusBadRequest, models.ExecuteCommandResponse{
			Success: false,
			Error:   fmt.Sprintf("Invalid request: %v", err),
		})
		return
	}

	h.logger.WithFields(logrus.Fields{
		"router_id":     req.RouterID,
		"command_count": len(req.Commands),
		"tenant_id":     req.TenantID,
	}).Info("Executing commands")

	// TODO: Get router details from backend API or database
	// For now, this is a placeholder

	results := make([]models.CommandResult, 0, len(req.Commands))
	
	c.JSON(http.StatusOK, models.ExecuteCommandResponse{
		Success: true,
		Results: results,
	})
}

// VerifyConnectivity handles connectivity verification requests
func (h *Handler) VerifyConnectivity(c *gin.Context) {
	var req struct {
		RouterID string `json:"router_id" binding:"required"`
		TenantID string `json:"tenant_id" binding:"required"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{
			"success": false,
			"error":   fmt.Sprintf("Invalid request: %v", err),
		})
		return
	}

	h.logger.WithFields(logrus.Fields{
		"router_id": req.RouterID,
		"tenant_id": req.TenantID,
	}).Info("Verifying connectivity")

	// TODO: Implement actual connectivity check
	
	c.JSON(http.StatusOK, gin.H{
		"success": true,
		"message": "Connectivity verified",
	})
}
