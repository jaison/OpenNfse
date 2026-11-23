ALTER TABLE mod_opennfse_config ADD COLUMN ibscbs_enabled TINYINT NOT NULL DEFAULT 0;
ALTER TABLE mod_opennfse_config ADD COLUMN ibscbs_cindop VARCHAR(6) NOT NULL DEFAULT '100301';
ALTER TABLE mod_opennfse_config ADD COLUMN ibscbs_cst VARCHAR(3) NOT NULL DEFAULT '000';
ALTER TABLE mod_opennfse_config ADD COLUMN ibscbs_cclasstrib VARCHAR(6) NOT NULL DEFAULT '000001';
ALTER TABLE mod_opennfse_config ADD COLUMN ibscbs_inddest VARCHAR(1) NOT NULL DEFAULT '0';
