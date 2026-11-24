-- MariaDB 10.4+ (XAMPP); ejecutar sobre la base seleccionada. No elimina datos.
CREATE TABLE IF NOT EXISTS lab3_twins (
 node_id VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 edge_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 desired_state JSON NULL, reported_state JSON NULL,
 desired_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
 reported_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
 sync_status ENUM('unknown','pending','synced','diverged') NOT NULL DEFAULT 'unknown',
 last_report_at DATETIME(6) NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 INDEX idx_edge(edge_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS lab3_aggregates (
 event_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 node_id VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 edge_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 window_start BIGINT NOT NULL, window_end BIGINT NOT NULL,
 payload JSON NOT NULL, payload_hash CHAR(64) NOT NULL,
 received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY(node_id) REFERENCES lab3_twins(node_id),
 INDEX idx_node_window(node_id,window_end)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS lab3_commands (
 command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 node_id VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version BIGINT UNSIGNED NOT NULL, desired_state JSON NOT NULL,
 status ENUM('pending','confirmed','superseded') NOT NULL DEFAULT 'pending',
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 confirmed_at DATETIME(6) NULL, convergence_ms DECIMAL(18,3) NULL,
 UNIQUE KEY uq_node_request(node_id,request_key), UNIQUE KEY uq_node_version(node_id,version),
 INDEX idx_pending(status,node_id), FOREIGN KEY(node_id) REFERENCES lab3_twins(node_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS lab3_confirmations (
 command_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 payload JSON NOT NULL, payload_hash CHAR(64) NOT NULL,
 received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY(command_id) REFERENCES lab3_commands(command_id)
) ENGINE=InnoDB;
