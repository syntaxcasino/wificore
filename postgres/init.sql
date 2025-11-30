-- Database bootstrap intentionally minimal.
-- Full schema and seed data are handled through Laravel migrations and seeders.
-- Only lightweight tasks that must run before migrations should stay here.

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
