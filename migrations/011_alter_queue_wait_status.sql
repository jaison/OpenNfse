ALTER TABLE mod_opennfse_queue ADD COLUMN status_checks INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE mod_opennfse_queue ADD COLUMN next_check_at DATETIME NULL;
