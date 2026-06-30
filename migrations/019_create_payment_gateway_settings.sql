CREATE TABLE IF NOT EXISTS mod_opennfse_payment_gateway_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gateway VARCHAR(64) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nfse_payment_gateway_settings_gateway (gateway),
  KEY idx_nfse_payment_gateway_settings_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
