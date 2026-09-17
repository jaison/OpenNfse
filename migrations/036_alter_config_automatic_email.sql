ALTER TABLE mod_opennfse_config ADD COLUMN auto_send_nfse_email TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE mod_opennfse_notas ADD COLUMN email_automatico_processado_at DATETIME NULL;
ALTER TABLE mod_opennfse_notas ADD COLUMN email_automatico_status VARCHAR(20) NULL;
