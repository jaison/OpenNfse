CREATE TABLE IF NOT EXISTS mod_opennfse_geracao_checks (
  invoiceid INT UNSIGNED NOT NULL,
  registrado_em DATETIME NOT NULL,
  verificado_em DATETIME NOT NULL,
  proxima_em DATETIME NOT NULL,
  tentativas INT UNSIGNED NOT NULL DEFAULT 0,
  erro_logado_em DATETIME NULL,
  PRIMARY KEY (invoiceid),
  KEY idx_nfse_geracao_check_proxima (proxima_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
