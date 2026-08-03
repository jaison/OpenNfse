ALTER TABLE mod_opennfse_nsu_sync
  ADD COLUMN last_sync_mode VARCHAR(20) NULL,
  ADD COLUMN last_diagnostics_json MEDIUMTEXT NULL;
