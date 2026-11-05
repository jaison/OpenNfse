ALTER TABLE mod_opennfse_config ADD COLUMN tomador_bairro_origem VARCHAR(20) NOT NULL DEFAULT 'address2';
ALTER TABLE mod_opennfse_config ADD COLUMN tomador_bairro_customfield_id INT NULL;
