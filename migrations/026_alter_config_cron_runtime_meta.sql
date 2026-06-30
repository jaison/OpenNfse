ALTER TABLE mod_opennfse_config ADD COLUMN cron_last_run_at DATETIME NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN cron_last_source VARCHAR(20) NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN cron_last_minute_key VARCHAR(12) NULL;
