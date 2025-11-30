-- Drop existing partial tables if they exist
DROP TABLE IF EXISTS worker_snapshots CASCADE;
DROP TABLE IF EXISTS performance_metrics CASCADE;
DROP TABLE IF EXISTS system_health_metrics CASCADE;
DROP TABLE IF EXISTS queue_metrics CASCADE;

-- Remove migration record so it can run again
DELETE FROM migrations WHERE migration = '2025_11_01_035000_create_system_metrics_tables';
