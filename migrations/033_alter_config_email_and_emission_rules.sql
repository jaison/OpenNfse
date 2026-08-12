ALTER TABLE mod_opennfse_config
  ADD COLUMN auto_emit_client_customfield_id INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN email_template_name VARCHAR(150) NULL DEFAULT NULL,
  ADD COLUMN auto_send_email_on_emit TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN allow_unpaid_manual_emit TINYINT(1) NOT NULL DEFAULT 0;
