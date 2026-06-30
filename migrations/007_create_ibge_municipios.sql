CREATE TABLE IF NOT EXISTS mod_opennfse_ibge_municipios (
  ibge_code VARCHAR(7) NOT NULL,
  nome_normalizado VARCHAR(120) NOT NULL,
  nome_original VARCHAR(120) NOT NULL,
  uf CHAR(2) NOT NULL,
  PRIMARY KEY (ibge_code),
  UNIQUE KEY uq_nfse_ibge_uf_nome (uf, nome_normalizado),
  KEY idx_nfse_ibge_nome (nome_normalizado),
  KEY idx_nfse_ibge_uf (uf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

