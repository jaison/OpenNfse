ALTER TABLE mod_opennfse_config ADD COLUMN ibge_sync_pinned_hash VARCHAR(64) NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN ibge_sync_pinned_count INT UNSIGNED NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN ibge_sync_pinned_at DATETIME NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN ibge_sync_pending_hash VARCHAR(64) NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN ibge_sync_pending_count INT UNSIGNED NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN ibge_sync_pending_checked_at DATETIME NULL;
