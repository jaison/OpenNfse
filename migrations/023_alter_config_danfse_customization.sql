ALTER TABLE mod_opennfse_config
  ADD COLUMN danfse_logo_svg MEDIUMTEXT NULL,
  ADD COLUMN danfse_municipio_nome VARCHAR(255) NULL,
  ADD COLUMN danfse_secretaria_nome VARCHAR(255) NULL,
  ADD COLUMN danfse_telefone VARCHAR(80) NULL,
  ADD COLUMN danfse_email VARCHAR(255) NULL;
