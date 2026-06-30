CREATE TABLE IF NOT EXISTS mod_opennfse_group_service_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  groupid INT UNSIGNED NOT NULL,
  codigo_servico VARCHAR(6) NULL,
  nbs VARCHAR(9) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nfse_group_service_codes_groupid (groupid),
  KEY idx_nfse_group_service_codes_codigo (codigo_servico),
  KEY idx_nfse_group_service_codes_nbs (nbs)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

