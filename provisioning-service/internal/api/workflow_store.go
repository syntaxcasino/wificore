package api

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

type persistedWorkflowStore struct {
	mu              sync.Mutex
	path            string
	completedTTL    time.Duration
	outboxRetryBase time.Duration
	callbackSecret  string
	state           workflowStoreState
}

type workflowStoreState struct {
	Workflows     map[string]workflowRecord `json:"workflows"`
	PendingOutbox []workflowOutboxEntry     `json:"pending_outbox"`
}

type workflowRecord struct {
	RouterID       string                 `json:"router_id"`
	TenantID       string                 `json:"tenant_id"`
	IdempotencyKey string                 `json:"idempotency_key"`
	Status         string                 `json:"status"`
	Stage          string                 `json:"stage"`
	Progress       int                    `json:"progress"`
	Message        string                 `json:"message"`
	Result         map[string]interface{} `json:"result,omitempty"`
	Error          string                 `json:"error,omitempty"`
	StartedAt      time.Time              `json:"started_at"`
	UpdatedAt      time.Time              `json:"updated_at"`
	CompletedAt    *time.Time             `json:"completed_at,omitempty"`
}

type workflowOutboxEntry struct {
	ID            string                 `json:"id"`
	WorkflowKey   string                 `json:"workflow_key"`
	RouterID      string                 `json:"router_id"`
	URL           string                 `json:"url"`
	AuthToken     string                 `json:"auth_token,omitempty"`
	LegacyAPIKey  string                 `json:"api_key,omitempty"`
	APIKey        string                 `json:"-"`
	Payload       map[string]interface{} `json:"payload"`
	AttemptCount  int                    `json:"attempt_count"`
	LastError     string                 `json:"last_error,omitempty"`
	NextAttemptAt time.Time              `json:"next_attempt_at"`
	CreatedAt     time.Time              `json:"created_at"`
	UpdatedAt     time.Time              `json:"updated_at"`
}

func newPersistedWorkflowStore(path string, completedTTL time.Duration, outboxRetryBase time.Duration, callbackSecret string) (*persistedWorkflowStore, error) {
	if strings.TrimSpace(path) == "" {
		path = filepath.Join("data", "provisioning-workflows.json")
	}
	store := &persistedWorkflowStore{
		path:            path,
		completedTTL:    completedTTL,
		outboxRetryBase: outboxRetryBase,
		callbackSecret:  strings.TrimSpace(callbackSecret),
		state: workflowStoreState{
			Workflows:     make(map[string]workflowRecord),
			PendingOutbox: []workflowOutboxEntry{},
		},
	}
	if err := store.load(); err != nil {
		return nil, err
	}
	return store, nil
}

func (s *persistedWorkflowStore) Acquire(routerID string, tenantID string, idempotencyKey string) (string, workflowRecord, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.cleanupLocked()

	if record, ok := s.state.Workflows[idempotencyKey]; ok {
		switch record.Status {
		case "completed", "failed":
			return "duplicate_completed", record, nil
		case "running":
			return "duplicate_active", record, nil
		}
	}

	for _, record := range s.state.Workflows {
		if record.RouterID == routerID && record.Status == "running" {
			return "router_busy", record, nil
		}
	}

	now := time.Now()
	record := workflowRecord{
		RouterID:       routerID,
		TenantID:       tenantID,
		IdempotencyKey: idempotencyKey,
		Status:         "running",
		Stage:          "submitted",
		Progress:       0,
		StartedAt:      now,
		UpdatedAt:      now,
	}
	s.state.Workflows[idempotencyKey] = record
	return "acquired", record, s.persistLocked()
}

func (s *persistedWorkflowStore) RecordProgress(idempotencyKey string, stage string, progress int, message string, result map[string]interface{}) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	record, ok := s.state.Workflows[idempotencyKey]
	if !ok {
		return nil
	}
	record.Status = "running"
	record.Stage = stage
	record.Progress = progress
	record.Message = message
	if result != nil {
		record.Result = result
	}
	record.UpdatedAt = time.Now()
	s.state.Workflows[idempotencyKey] = record
	return s.persistLocked()
}

func (s *persistedWorkflowStore) RecordCompletion(idempotencyKey string, stage string, progress int, message string, result map[string]interface{}) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	record, ok := s.state.Workflows[idempotencyKey]
	if !ok {
		return nil
	}
	now := time.Now()
	record.Status = "completed"
	record.Stage = stage
	record.Progress = progress
	record.Message = message
	record.Result = result
	record.Error = ""
	record.UpdatedAt = now
	record.CompletedAt = &now
	s.state.Workflows[idempotencyKey] = record
	return s.persistLocked()
}

func (s *persistedWorkflowStore) RecordFailure(idempotencyKey string, stage string, progress int, message string, result map[string]interface{}, errorMessage string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	record, ok := s.state.Workflows[idempotencyKey]
	if !ok {
		return nil
	}
	now := time.Now()
	record.Status = "failed"
	record.Stage = stage
	record.Progress = progress
	record.Message = message
	record.Result = result
	record.Error = errorMessage
	record.UpdatedAt = now
	record.CompletedAt = &now
	s.state.Workflows[idempotencyKey] = record
	return s.persistLocked()
}

func (s *persistedWorkflowStore) EnqueueCallback(idempotencyKey, routerID, url, apiKey string, payload map[string]interface{}, errorMessage string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	now := time.Now()
	s.state.PendingOutbox = append(s.state.PendingOutbox, workflowOutboxEntry{
		ID:            fmt.Sprintf("%s:%d", idempotencyKey, now.UnixNano()),
		WorkflowKey:   idempotencyKey,
		RouterID:      routerID,
		URL:           url,
		AuthToken:     s.encodeAPIKey(strings.TrimSpace(apiKey)),
		Payload:       payload,
		AttemptCount:  0,
		LastError:     errorMessage,
		NextAttemptAt: now.Add(s.outboxRetryBase),
		CreatedAt:     now,
		UpdatedAt:     now,
	})
	return s.persistLocked()
}

func (s *persistedWorkflowStore) DueOutboxEntries(limit int) []workflowOutboxEntry {
	s.mu.Lock()
	defer s.mu.Unlock()
	if limit <= 0 {
		limit = 20
	}
	now := time.Now()
	entries := make([]workflowOutboxEntry, 0, limit)
	for _, entry := range s.state.PendingOutbox {
		if entry.NextAttemptAt.After(now) {
			continue
		}
		decoded := entry
		decoded.APIKey = s.decodeAPIKey(decoded.AuthToken, decoded.LegacyAPIKey)
		entries = append(entries, decoded)
		if len(entries) >= limit {
			break
		}
	}
	return entries
}

func (s *persistedWorkflowStore) MarkOutboxDelivered(id string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	filtered := s.state.PendingOutbox[:0]
	for _, entry := range s.state.PendingOutbox {
		if entry.ID != id {
			filtered = append(filtered, entry)
		}
	}
	s.state.PendingOutbox = filtered
	return s.persistLocked()
}

func (s *persistedWorkflowStore) MarkOutboxRetry(id string, errorMessage string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	now := time.Now()
	for i, entry := range s.state.PendingOutbox {
		if entry.ID != id {
			continue
		}
		entry.AttemptCount++
		entry.LastError = errorMessage
		entry.UpdatedAt = now
		backoff := s.outboxRetryBase * time.Duration(entry.AttemptCount+1)
		if backoff > 10*time.Minute {
			backoff = 10 * time.Minute
		}
		entry.NextAttemptAt = now.Add(backoff)
		s.state.PendingOutbox[i] = entry
		break
	}
	return s.persistLocked()
}

func (s *persistedWorkflowStore) GetByKey(idempotencyKey string) (workflowRecord, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.cleanupLocked()
	record, ok := s.state.Workflows[idempotencyKey]
	return record, ok
}

func (s *persistedWorkflowStore) GetActiveByRouter(routerID string) (workflowRecord, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.cleanupLocked()
	for _, record := range s.state.Workflows {
		if record.RouterID == routerID && record.Status == "running" {
			return record, true
		}
	}
	return workflowRecord{}, false
}

func (s *persistedWorkflowStore) load() error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if err := os.MkdirAll(filepath.Dir(s.path), 0o755); err != nil {
		return err
	}
	data, err := os.ReadFile(s.path)
	if err != nil {
		if os.IsNotExist(err) {
			return s.persistLocked()
		}
		return err
	}
	if len(data) == 0 {
		return nil
	}
	var state workflowStoreState
	if err := json.Unmarshal(data, &state); err != nil {
		corruptPath := fmt.Sprintf("%s.corrupt-%d", s.path, time.Now().Unix())
		if renameErr := os.Rename(s.path, corruptPath); renameErr != nil {
			return fmt.Errorf("workflow store corrupt (%w) and could not quarantine file: %v", err, renameErr)
		}
		s.state = workflowStoreState{Workflows: make(map[string]workflowRecord), PendingOutbox: []workflowOutboxEntry{}}
		return s.persistLocked()
	}
	if state.Workflows == nil {
		state.Workflows = make(map[string]workflowRecord)
	}
	if state.PendingOutbox == nil {
		state.PendingOutbox = []workflowOutboxEntry{}
	}
	s.state = state
	changed := s.sanitizeOutboxLocked()
	s.cleanupLocked()
	if changed {
		return s.persistLocked()
	}
	return nil
}

func (s *persistedWorkflowStore) sanitizeOutboxLocked() bool {
	changed := false
	for i, entry := range s.state.PendingOutbox {
		if entry.AuthToken == "" && entry.LegacyAPIKey != "" {
			entry.AuthToken = s.encodeAPIKey(strings.TrimSpace(entry.LegacyAPIKey))
			entry.LegacyAPIKey = ""
			changed = true
		}
		entry.APIKey = s.decodeAPIKey(entry.AuthToken, entry.LegacyAPIKey)
		s.state.PendingOutbox[i] = entry
	}
	return changed
}

func (s *persistedWorkflowStore) cleanupLocked() {
	if s.completedTTL > 0 {
		cutoff := time.Now().Add(-s.completedTTL)
		for key, record := range s.state.Workflows {
			if (record.Status == "completed" || record.Status == "failed") && record.CompletedAt != nil && record.CompletedAt.Before(cutoff) {
				delete(s.state.Workflows, key)
			}
		}
	}
}

func (s *persistedWorkflowStore) persistLocked() error {
	if err := os.MkdirAll(filepath.Dir(s.path), 0o755); err != nil {
		return err
	}
	payload, err := json.MarshalIndent(s.state, "", "  ")
	if err != nil {
		return err
	}
	tmpPath := s.path + ".tmp"
	file, err := os.OpenFile(tmpPath, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	if _, err := file.Write(payload); err != nil {
		_ = file.Close()
		return err
	}
	if err := file.Sync(); err != nil {
		_ = file.Close()
		return err
	}
	if err := file.Close(); err != nil {
		return err
	}
	return os.Rename(tmpPath, s.path)
}

func (s *persistedWorkflowStore) encodeAPIKey(apiKey string) string {
	apiKey = strings.TrimSpace(apiKey)
	if apiKey == "" {
		return ""
	}
	if s.callbackSecret != "" && apiKey == s.callbackSecret {
		return "default"
	}
	if s.callbackSecret == "" {
		return ""
	}
	key := sha256.Sum256([]byte(s.callbackSecret))
	block, err := aes.NewCipher(key[:])
	if err != nil {
		return ""
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return ""
	}
	nonce := make([]byte, gcm.NonceSize())
	if _, err := rand.Read(nonce); err != nil {
		return ""
	}
	ciphertext := gcm.Seal(nil, nonce, []byte(apiKey), nil)
	return "enc:" + base64.StdEncoding.EncodeToString(append(nonce, ciphertext...))
}

func (s *persistedWorkflowStore) decodeAPIKey(authToken string, legacyAPIKey string) string {
	authToken = strings.TrimSpace(authToken)
	legacyAPIKey = strings.TrimSpace(legacyAPIKey)
	if authToken == "" {
		return legacyAPIKey
	}
	if authToken == "default" {
		return s.callbackSecret
	}
	if !strings.HasPrefix(authToken, "enc:") {
		return authToken
	}
	if s.callbackSecret == "" {
		return ""
	}
	raw, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(authToken, "enc:"))
	if err != nil {
		return ""
	}
	key := sha256.Sum256([]byte(s.callbackSecret))
	block, err := aes.NewCipher(key[:])
	if err != nil {
		return ""
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return ""
	}
	nonceSize := gcm.NonceSize()
	if len(raw) < nonceSize {
		return ""
	}
	nonce := raw[:nonceSize]
	ciphertext := raw[nonceSize:]
	plaintext, err := gcm.Open(nil, nonce, ciphertext, nil)
	if err != nil {
		return ""
	}
	return string(plaintext)
}
