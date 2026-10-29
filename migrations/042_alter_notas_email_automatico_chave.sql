ALTER TABLE mod_opennfse_notas ADD COLUMN email_automatico_chave VARCHAR(60) NULL;
ALTER TABLE mod_opennfse_notas ADD COLUMN email_automatico_tentativas INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE mod_opennfse_notas ADD COLUMN email_automatico_proxima_tentativa_at DATETIME NULL;
ALTER TABLE mod_opennfse_notas ADD KEY idx_nfse_email_auto_retry (email_automatico_status, email_automatico_proxima_tentativa_at);
