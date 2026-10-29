ALTER TABLE mod_opennfse_config ADD COLUMN auto_emit_trigger VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN auto_emit_scope VARCHAR(16) NOT NULL DEFAULT 'todos';
ALTER TABLE mod_opennfse_config ADD COLUMN auto_emit_group_ids TEXT NULL;
ALTER TABLE mod_opennfse_config ADD COLUMN auto_emit_customfield_id INT UNSIGNED NOT NULL DEFAULT 0;
UPDATE mod_opennfse_config SET auto_emit_trigger = CASE WHEN auto_emit_on_payment = 1 THEN 'paga' ELSE 'nunca' END WHERE auto_emit_trigger IS NULL;
