ALTER TABLE mod_opennfse_config ADD COLUMN client_area_notice_enabled TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE mod_opennfse_config ADD COLUMN client_area_notice_type VARCHAR(20) NOT NULL DEFAULT 'warning';
ALTER TABLE mod_opennfse_config ADD COLUMN client_area_notice_message TEXT NULL;

UPDATE mod_opennfse_config
SET
  client_area_notice_enabled = COALESCE(client_area_notice_enabled, 1),
  client_area_notice_type = COALESCE(NULLIF(client_area_notice_type, ''), 'warning'),
  client_area_notice_message = COALESCE(
    NULLIF(client_area_notice_message, ''),
    CONCAT(
      'A partir de 01/07/2026 mudamos nosso sistema de emissão de NFS-e para nova API da Nota Nacional. Portanto serão exibidas apenas as notas emitidas depois desta data.',
      CHAR(10),
      CHAR(10),
      'Caso precise do PDF ou XML de alguma nota fiscal anterior a esta data, solicite via https://my.minivps.com.br/submitticket.php'
    )
  );
