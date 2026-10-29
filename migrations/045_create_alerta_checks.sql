CREATE TABLE IF NOT EXISTS mod_opennfse_alerta_checks (
  invoiceid INT UNSIGNED NOT NULL,
  solicitado_em DATETIME NOT NULL,
  PRIMARY KEY (invoiceid),
  KEY idx_nfse_alerta_check_solicitado (solicitado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
