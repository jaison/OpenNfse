ALTER TABLE mod_opennfse_logs ADD COLUMN correlation_id VARCHAR(64) NULL AFTER nota_id;
ALTER TABLE mod_opennfse_logs ADD KEY idx_mod_opennfse_logs_correlation_id (correlation_id);

ALTER TABLE mod_opennfse_queue ADD COLUMN correlation_id VARCHAR(64) NULL AFTER invoiceid;
ALTER TABLE mod_opennfse_queue ADD KEY idx_mod_opennfse_queue_correlation_id (correlation_id);
