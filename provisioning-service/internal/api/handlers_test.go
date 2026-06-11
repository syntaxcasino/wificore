package api

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/sirupsen/logrus"
	"github.com/wificore/provisioning-service/internal/models"
)

func TestSubmitCommandAcceptsComputeRouterMetrics(t *testing.T) {
	gin.SetMode(gin.TestMode)

	logger := logrus.New()
	logger.SetOutput(io.Discard)

	store, err := newPersistedWorkflowStore(filepath.Join(t.TempDir(), "workflows.json"), 30*time.Minute, 10*time.Minute, 30*time.Second, "")
	if err != nil {
		t.Fatalf("failed to create workflow store: %v", err)
	}

	h := &Handler{logger: logger, workflowStore: store}
	h.connectRouter = func(models.ConnectionConfig) (routerSSHClient, error) {
		t.Fatalf("compute metrics should not connect to router")
		return nil, nil
	}
	h.runCommandWorkflow = func(models.CommandRequest) {}

	body := map[string]interface{}{
		"command_id": "metrics:test",
		"router_id":  "tenant-router-metrics",
		"tenant_id":  "tenant-1",
		"type":       models.CommandTypeComputeRouterMetrics,
		"payload": map[string]interface{}{
			"monitoring": map[string]interface{}{
				"routers": []map[string]interface{}{{"router_id": "router-1"}},
			},
			"metrics": map[string]interface{}{
				"time_ranges": []string{"15m", "1h"},
			},
		},
	}

	payload, err := json.Marshal(body)
	if err != nil {
		t.Fatalf("failed to marshal request: %v", err)
	}

	req := httptest.NewRequest(http.MethodPost, "/api/v1/commands", bytes.NewReader(payload))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()

	ctx, _ := gin.CreateTestContext(rec)
	ctx.Request = req

	h.SubmitCommand(ctx)

	if rec.Code != http.StatusAccepted {
		t.Fatalf("expected status %d, got %d: %s", http.StatusAccepted, rec.Code, rec.Body.String())
	}

	var resp map[string]interface{}
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatalf("failed to decode response: %v", err)
	}

	if success, _ := resp["success"].(bool); !success {
		t.Fatalf("expected success response, got: %v", resp)
	}
}
