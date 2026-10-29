CREATE TABLE IF NOT EXISTS mod_opennfse_geracao_checks (
  invoiceid INT UNSIGNED NOT NULL,
  registrado_em DATETIME NOT NULL,
  verificado_em DATETIME NOT NULL,
  PRIMARY KEY (invoiceid),
  KEY idx_nfse_geracao_check_verificado (verificado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
