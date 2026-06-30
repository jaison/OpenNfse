ALTER TABLE mod_opennfse_config ADD COLUMN ibge_sync_last_hash VARCHAR(64) NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN ibge_sync_last_count INT UNSIGNED NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN ibge_sync_last_checked_at DATETIME NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN ibge_sync_last_synced_at DATETIME NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN ibge_sync_last_status VARCHAR(20) NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN ibge_sync_last_error TEXT NULL;
