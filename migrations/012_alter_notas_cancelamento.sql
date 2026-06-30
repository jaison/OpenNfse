ALTER TABLE mod_opennfse_notas ADD COLUMN cancelado_em DATETIME NULL;
ALTER TABLE mod_opennfse_notas ADD COLUMN cancel_codigo_motivo VARCHAR(10) NULL;
ALTER TABLE mod_opennfse_notas ADD COLUMN cancel_motivo VARCHAR(255) NULL;
ALTER TABLE mod_opennfse_notas ADD COLUMN cancel_descricao VARCHAR(255) NULL;
ALTER TABLE mod_opennfse_notas ADD COLUMN cancel_erro MEDIUMTEXT NULL;

