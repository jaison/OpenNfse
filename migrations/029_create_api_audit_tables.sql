CREATE TABLE IF NOT EXISTS mod_opennfse_nsu_sync (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  environment VARCHAR(15) NOT NULL,
  cnpj_consulta VARCHAR(14) NULL,
  ultimo_nsu BIGINT UNSIGNED NOT NULL DEFAULT 0,
  maior_nsu BIGINT UNSIGNED NULL,
  ultimo_sync_em DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nfse_nsu_sync_env (environment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mod_opennfse_distribuicao_dfe (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  environment VARCHAR(15) NOT NULL,
  nsu BIGINT UNSIGNED NOT NULL,
  chave_acesso VARCHAR(60) NULL,
  tipo_documento VARCHAR(20) NOT NULL DEFAULT 'DESCONHECIDO',
  tipo_evento VARCHAR(20) NULL,
  numero_nf VARCHAR(30) NULL,
  competencia DATE NULL,
  emitida_em DATETIME NULL,
  evento_em DATETIME NULL,
  referencia_em DATETIME NULL,
  xml_hash CHAR(40) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nfse_distribuicao_env_nsu (environment, nsu),
  KEY idx_nfse_distribuicao_chave (chave_acesso),
  KEY idx_nfse_distribuicao_tipo (tipo_documento),
  KEY idx_nfse_distribuicao_referencia (referencia_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
