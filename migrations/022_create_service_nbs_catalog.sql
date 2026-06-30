CREATE TABLE mod_opennfse_service_nbs_catalog (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo_servico VARCHAR(6) NOT NULL,
  nbs VARCHAR(9) NOT NULL,
  descricao VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_nfse_service_nbs_catalog (codigo_servico, nbs),
  KEY idx_nfse_service_nbs_catalog_servico (codigo_servico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mod_opennfse_service_nbs_catalog (codigo_servico, nbs, descricao, created_at, updated_at)
SELECT '010301', '115061000', 'Serviços de hospedagem de sítios eletrônicos na rede mundial de computadores', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM mod_opennfse_service_nbs_catalog WHERE codigo_servico = '010301' AND nbs = '115061000');

INSERT INTO mod_opennfse_service_nbs_catalog (codigo_servico, nbs, descricao, created_at, updated_at)
SELECT '010301', '115062100', 'Serviços de hospedagem de aplicativos e programas software como serviço (SaaS)', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM mod_opennfse_service_nbs_catalog WHERE codigo_servico = '010301' AND nbs = '115062100');

INSERT INTO mod_opennfse_service_nbs_catalog (codigo_servico, nbs, descricao, created_at, updated_at)
SELECT '010301', '115062200', 'Serviços de fornecimento de infraestrutura como serviço (IaaS)', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM mod_opennfse_service_nbs_catalog WHERE codigo_servico = '010301' AND nbs = '115062200');

INSERT INTO mod_opennfse_service_nbs_catalog (codigo_servico, nbs, descricao, created_at, updated_at)
SELECT '010301', '115062300', 'Serviços de fornecimento de plataformas como serviço (PaaS)', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM mod_opennfse_service_nbs_catalog WHERE codigo_servico = '010301' AND nbs = '115062300');

INSERT INTO mod_opennfse_service_nbs_catalog (codigo_servico, nbs, descricao, created_at, updated_at)
SELECT '010301', '115062900', 'Serviços de hospedagem de aplicativos e programas não classificados em subposições anteriores', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM mod_opennfse_service_nbs_catalog WHERE codigo_servico = '010301' AND nbs = '115062900');

INSERT INTO mod_opennfse_service_nbs_catalog (codigo_servico, nbs, descricao, created_at, updated_at)
SELECT '010301', '115069000', 'Serviços de hospedagem e de disponibilização de infraestrutura em tecnologia da informação (TI) não classificados em subposições anteriores', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM mod_opennfse_service_nbs_catalog WHERE codigo_servico = '010301' AND nbs = '115069000');

INSERT INTO mod_opennfse_service_nbs_catalog (codigo_servico, nbs, descricao, created_at, updated_at)
SELECT '010301', '115090000', 'Serviços de processamento de dados', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM mod_opennfse_service_nbs_catalog WHERE codigo_servico = '010301' AND nbs = '115090000');

INSERT INTO mod_opennfse_service_nbs_catalog (codigo_servico, nbs, descricao, created_at, updated_at)
SELECT '010701', '115013000', 'Serviços de suporte em tecnologia da informação (TI)', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM mod_opennfse_service_nbs_catalog WHERE codigo_servico = '010701' AND nbs = '115013000');

INSERT INTO mod_opennfse_service_nbs_catalog (codigo_servico, nbs, descricao, created_at, updated_at)
SELECT '010701', '115080000', 'Serviços de manutenção de aplicativos e programas', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM mod_opennfse_service_nbs_catalog WHERE codigo_servico = '010701' AND nbs = '115080000');

INSERT INTO mod_opennfse_service_nbs_catalog (codigo_servico, nbs, descricao, created_at, updated_at)
SELECT '010701', '115021000', 'Serviços de projeto, desenvolvimento e instalação de aplicativos e programas não personalizados (não customizados)', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM mod_opennfse_service_nbs_catalog WHERE codigo_servico = '010701' AND nbs = '115021000');

INSERT INTO mod_opennfse_service_nbs_catalog (codigo_servico, nbs, descricao, created_at, updated_at)
SELECT '010701', '115022000', 'Serviços de projeto e desenvolvimento, adaptação e instalação de aplicativos e programas personalizados (customizados)', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM mod_opennfse_service_nbs_catalog WHERE codigo_servico = '010701' AND nbs = '115022000');
