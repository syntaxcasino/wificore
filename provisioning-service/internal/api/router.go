package api

import (
	"time"

	"github.com/gin-gonic/gin"
	"github.com/prometheus/client_golang/prometheus/promhttp"
	"github.com/sirupsen/logrus"
	"github.com/wificore/provisioning-service/internal/middleware"
)

// SetupRouter configures the API routes
func SetupRouter(logger *logrus.Logger) *gin.Engine {
	// Set Gin to release mode in production
	gin.SetMode(gin.ReleaseMode)

	router := gin.New()
	router.Use(gin.Recovery())
	router.Use(LoggerMiddleware(logger))

	handler := NewHandler(logger)

	// Health and metrics endpoints (no auth required)
	router.GET("/health", handler.HealthCheck)
	router.GET("/metrics", gin.WrapH(promhttp.Handler()))

	// Create rate limiter (100 requests per minute per IP)
	rateLimiter := middleware.NewRateLimiter(logger)

	// API v1 routes (with authentication and rate limiting)
	v1 := router.Group("/api/v1")
	v1.Use(middleware.AuthMiddleware(logger))
	v1.Use(rateLimiter.Middleware(100, time.Minute))
	{
		// Provisioning endpoints
		v1.POST("/provision", handler.ProvisionRouter)
		v1.POST("/verify", handler.VerifyConnectivity)
		
		// Live data endpoints
		v1.POST("/live-data", handler.FetchLiveData)
		
		// Command execution
		v1.POST("/execute", handler.ExecuteCommand)
	}

	return router
}

// LoggerMiddleware logs HTTP requests
func LoggerMiddleware(logger *logrus.Logger) gin.HandlerFunc {
	return func(c *gin.Context) {
		start := c.Request.Context().Value("start_time")
		
		c.Next()

		logger.WithFields(logrus.Fields{
			"method":     c.Request.Method,
			"path":       c.Request.URL.Path,
			"status":     c.Writer.Status(),
			"ip":         c.ClientIP(),
			"user_agent": c.Request.UserAgent(),
		}).Info("HTTP request")
		
		_ = start // Placeholder for timing
	}
}
