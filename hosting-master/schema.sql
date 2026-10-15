-- HelixPanel Standalone Master (cPanel / DirectAdmin) — Developer Preview
-- Requires MySQL 8+/MariaDB 10.5+ and InnoDB, utf8mb4.
-- Import with phpMyAdmin into a database dedicated to this master.
CREATE TABLE IF NOT EXISTS hp_master_meta (
  meta_key VARCHAR(64) PRIMARY KEY,
  meta_value VARCHAR(512) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS hp_nodes (
  id CHAR(32) PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  region CHAR(2) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending_enrollment',
  bootstrap_hash CHAR(64) NULL,
  bootstrap_expires_at DATETIME NULL,
  agent_hash CHAR(64) NULL,
  enrolled_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  agent_version VARCHAR(64) NULL,
  load_one DOUBLE NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_node_name (name),
  KEY ix_node_online (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS hp_users (
  id CHAR(32) PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  quota_gb INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending_provisioning',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_user_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
