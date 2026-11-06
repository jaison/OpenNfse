CREATE TABLE IF NOT EXISTS mod_opennfse_updater (
  id INT UNSIGNED NOT NULL,
  paused TINYINT(1) NOT NULL DEFAULT 0,
  paused_at DATETIME NULL,
  paused_by INT UNSIGNED NULL,
  step VARCHAR(30) NULL,
  from_version VARCHAR(20) NULL,
  to_version VARCHAR(20) NULL,
  data MEDIUMTEXT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS mod_opennfse_updater_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  hook VARCHAR(40) NOT NULL,
  vars MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  replayed_at DATETIME NULL,
  error TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_nfse_updater_events_pending (replayed_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
