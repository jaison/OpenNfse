CREATE TABLE IF NOT EXISTS mod_opennfse_config (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  environment VARCHAR(15) NOT NULL,
  certificate_path VARCHAR(255) NOT NULL,
  certificate_password_enc TEXT NOT NULL,
  cnpj_emissor VARCHAR(14) NOT NULL,
  inscricao_municipal VARCHAR(20) NOT NULL,
  codigo_ibge VARCHAR(7) NOT NULL,
  codigo_servico VARCHAR(20) NOT NULL,
  aliquota_iss DECIMAL(5,2) NOT NULL,
  tomador_cpfcnpj_customfield_id INT UNSIGNED NOT NULL,
  serie_dps VARCHAR(5) NOT NULL DEFAULT '1',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mod_opennfse_notas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoiceid INT UNSIGNED NOT NULL,
  userid INT UNSIGNED NOT NULL,
  numero_nf VARCHAR(30) NULL,
  protocolo VARCHAR(80) NULL,
  id_dps VARCHAR(60) NULL,
  chave_acesso VARCHAR(60) NULL,
  status VARCHAR(20) NOT NULL,
  xml_path VARCHAR(255) NULL,
  erro_api MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nfse_invoice (invoiceid),
  KEY idx_nfse_user (userid),
  KEY idx_nfse_chave (chave_acesso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mod_opennfse_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nota_id INT UNSIGNED NULL,
  tipo VARCHAR(30) NOT NULL,
  request MEDIUMTEXT NULL,
  response MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_nfse_log_nota (nota_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mod_opennfse_queue (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoiceid INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL,
  tentativas INT UNSIGNED NOT NULL DEFAULT 0,
  ultima_tentativa DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_nfse_queue_invoice (invoiceid),
  KEY idx_nfse_queue_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mod_opennfse_sequences (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  environment VARCHAR(15) NOT NULL,
  cnpj_emissor VARCHAR(14) NOT NULL,
  serie_dps VARCHAR(5) NOT NULL,
  last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nfse_seq (environment, cnpj_emissor, serie_dps)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

