package middleware

import (
	"net/http"
	"os"
	"strings"

	"github.com/gin-gonic/gin"
	"github.com/sirupsen/logrus"
)

// AuthMiddleware validates API key for secure communication
func AuthMiddleware(logger *logrus.Logger) gin.HandlerFunc {
	apiKey := os.Getenv("API_KEY")
	
	// If no API key is set, log warning but allow requests (backward compatibility)
	if apiKey == "" {
		logger.Warn("API_KEY not set - authentication disabled (not recommended for production)")
		return func(c *gin.Context) {
			c.Next()
		}
	}

	return func(c *gin.Context) {
		// Get API key from header
		providedKey := c.GetHeader("X-API-Key")
		
		// Also check Authorization header as fallback
		if providedKey == "" {
			authHeader := c.GetHeader("Authorization")
			if strings.HasPrefix(authHeader, "Bearer ") {
				providedKey = strings.TrimPrefix(authHeader, "Bearer ")
			}
		}

		// Validate API key
		if providedKey != apiKey {
			logger.WithFields(logrus.Fields{
				"ip":     c.ClientIP(),
				"path":   c.Request.URL.Path,
				"method": c.Request.Method,
			}).Warn("Unauthorized access attempt - invalid API key")

			c.JSON(http.StatusUnauthorized, gin.H{
				"success": false,
				"error":   "Unauthorized - invalid or missing API key",
			})
			c.Abort()
			return
		}

		// Log successful authentication
		logger.WithFields(logrus.Fields{
			"ip":   c.ClientIP(),
			"path": c.Request.URL.Path,
		}).Debug("API authentication successful")

		c.Next()
	}
}
