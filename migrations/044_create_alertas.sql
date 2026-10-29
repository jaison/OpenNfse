CREATE TABLE IF NOT EXISTS mod_opennfse_alertas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoiceid INT UNSIGNED NOT NULL,
  nota_id INT UNSIGNED NULL,
  chave_acesso VARCHAR(60) NULL,
  tipo VARCHAR(40) NOT NULL,
  detalhes TEXT NULL,
  chave_aberto CHAR(40) NULL,
  criado_em DATETIME NOT NULL,
  resolvido_em DATETIME NULL,
  resolvido_por INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nfse_alerta_aberto (chave_aberto),
  KEY idx_nfse_alerta_invoice (invoiceid),
  KEY idx_nfse_alerta_resolvido (resolvido_em),
  KEY idx_nfse_alerta_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
