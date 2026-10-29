UPDATE mod_opennfse_notas SET email_automatico_chave = COALESCE(NULLIF(TRIM(chave_acesso), ''), '') WHERE email_automatico_chave IS NULL AND email_automatico_processado_at IS NOT NULL;
