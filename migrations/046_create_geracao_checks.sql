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
ALTER TABLE mod_opennfse_geracao_checks ADD COLUMN proxima_em DATETIME NULL;
ALTER TABLE mod_opennfse_geracao_checks ADD COLUMN tentativas INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE mod_opennfse_geracao_checks ADD COLUMN erro_logado_em DATETIME NULL;
ALTER TABLE mod_opennfse_geracao_checks ADD KEY idx_nfse_geracao_check_proxima (proxima_em);
UPDATE mod_opennfse_geracao_checks SET proxima_em = verificado_em WHERE proxima_em IS NULL;
