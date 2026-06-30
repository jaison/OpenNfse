ALTER TABLE mod_opennfse_config ADD COLUMN logs_retention_days INT UNSIGNED NOT NULL DEFAULT 90;
ALTER TABLE mod_opennfse_config ADD COLUMN queue_done_retention_days INT UNSIGNED NOT NULL DEFAULT 30;

