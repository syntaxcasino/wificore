package middleware

import (
	"net/http"
	"sync"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/sirupsen/logrus"
)

// RateLimiter implements a simple token bucket rate limiter
type RateLimiter struct {
	requests map[string]*clientBucket
	mu       sync.RWMutex
	logger   *logrus.Logger
}

type clientBucket struct {
	tokens     int
	lastRefill time.Time
}

// NewRateLimiter creates a new rate limiter
func NewRateLimiter(logger *logrus.Logger) *RateLimiter {
	rl := &RateLimiter{
		requests: make(map[string]*clientBucket),
		logger:   logger,
	}
	
	// Cleanup old entries every 5 minutes
	go rl.cleanup()
	
	return rl
}

// Middleware returns a Gin middleware for rate limiting
func (rl *RateLimiter) Middleware(maxRequests int, window time.Duration) gin.HandlerFunc {
	return func(c *gin.Context) {
		clientIP := c.ClientIP()
		
		if !rl.allow(clientIP, maxRequests, window) {
			rl.logger.WithFields(logrus.Fields{
				"ip":     clientIP,
				"path":   c.Request.URL.Path,
				"method": c.Request.Method,
			}).Warn("Rate limit exceeded")

			c.JSON(http.StatusTooManyRequests, gin.H{
				"success": false,
				"error":   "Rate limit exceeded - too many requests",
			})
			c.Abort()
			return
		}

		c.Next()
	}
}

// allow checks if a request is allowed based on rate limit
func (rl *RateLimiter) allow(clientIP string, maxRequests int, window time.Duration) bool {
	rl.mu.Lock()
	defer rl.mu.Unlock()

	now := time.Now()
	bucket, exists := rl.requests[clientIP]

	if !exists {
		// New client
		rl.requests[clientIP] = &clientBucket{
			tokens:     maxRequests - 1,
			lastRefill: now,
		}
		return true
	}

	// Refill tokens based on time elapsed
	elapsed := now.Sub(bucket.lastRefill)
	if elapsed >= window {
		// Full refill
		bucket.tokens = maxRequests - 1
		bucket.lastRefill = now
		return true
	}

	// Partial refill (linear)
	tokensToAdd := int(float64(maxRequests) * (elapsed.Seconds() / window.Seconds()))
	if tokensToAdd > 0 {
		bucket.tokens += tokensToAdd
		if bucket.tokens > maxRequests {
			bucket.tokens = maxRequests
		}
		bucket.lastRefill = now
	}

	// Check if request is allowed
	if bucket.tokens > 0 {
		bucket.tokens--
		return true
	}

	return false
}

// cleanup removes old entries to prevent memory leak
func (rl *RateLimiter) cleanup() {
	ticker := time.NewTicker(5 * time.Minute)
	defer ticker.Stop()

	for range ticker.C {
		rl.mu.Lock()
		now := time.Now()
		for ip, bucket := range rl.requests {
			if now.Sub(bucket.lastRefill) > 10*time.Minute {
				delete(rl.requests, ip)
			}
		}
		rl.mu.Unlock()
	}
}
