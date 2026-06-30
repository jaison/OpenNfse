ALTER TABLE mod_opennfse_config ADD COLUMN queue_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE mod_opennfse_config ADD COLUMN auto_emit_on_payment TINYINT(1) NOT NULL DEFAULT 0;
