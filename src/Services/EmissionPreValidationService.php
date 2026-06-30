<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Exceptions\NfseValidationException;
use OpenNfse\Repositories\WhmcsCustomerRepository;

final class EmissionPreValidationService
{
    public function validate(array $config, array $invoice, array $items, array $client): void
    {
        $errors = [];

        $certificatePath = trim((string) ($config['certificate_path'] ?? ''));
        if ($certificatePath === '') {
            $errors[] = 'caminho do certificado não configurado';
        } elseif (!is_file($certificatePath)) {
            $errors[] = 'arquivo do certificado não encontrado';
        }

        if (trim((string) ($config['certificate_password_enc'] ?? '')) === '') {
            $errors[] = 'senha do certificado não configurada';
        }

        $cnpjEmissor = preg_replace('/\D/', '', (string) ($config['cnpj_emissor'] ?? ''));
        if ($cnpjEmissor === '' || strlen($cnpjEmissor) !== 14) {
            $errors[] = 'CNPJ do emissor inválido';
        }

        $codigoIbge = trim((string) ($config['codigo_ibge'] ?? ''));
        if ($codigoIbge === '' || !ctype_digit($codigoIbge) || strlen($codigoIbge) !== 7) {
            $errors[] = 'código IBGE inválido';
        }

        if (trim((string) ($config['serie_dps'] ?? '')) === '') {
            $errors[] = 'série DPS não configurada';
        }

        if (trim((string) ($config['codigo_servico'] ?? '')) === '') {
            $errors[] = 'código do serviço padrão não configurado';
        }

        if ((string) ($config['prestador_informar_im'] ?? '1') === '1' && trim((string) ($config['inscricao_municipal'] ?? '')) === '') {
            $errors[] = 'inscrição municipal do emissor não informada';
        }

        if (trim((string) ($config['prestador_op_simp_nac'] ?? '')) === '') {
            $errors[] = 'opção do Simples Nacional do prestador não configurada';
        }

        $total = (float) ($invoice['total'] ?? 0);
        if ($total <= 0) {
            $errors[] = 'valor total da fatura inválido';
        }

        if (empty($items)) {
            $errors[] = 'a fatura não possui itens para composição da NFS-e';
        }

        $tomadorNome = trim((string) ($client['companyname'] ?? ''));
        if ($tomadorNome === '') {
            $tomadorNome = trim((string) ($client['firstname'] ?? '') . ' ' . (string) ($client['lastname'] ?? ''));
        }
        if ($tomadorNome === '') {
            $errors[] = 'nome ou razão social do tomador não informado';
        }

        if (trim((string) ($client['email'] ?? '')) === '') {
            $errors[] = 'e-mail do tomador não informado';
        }

        $country = strtoupper(trim((string) ($client['country'] ?? '')));
        if ($country === '') {
            $errors[] = 'país do tomador não informado';
        } elseif ($country === 'BR') {
            $customFieldId = (int) ($config['tomador_cpfcnpj_customfield_id'] ?? 0);
            if ($customFieldId <= 0) {
                $errors[] = 'custom field de CPF/CNPJ do tomador não configurado';
            } else {
                try {
                    (new WhmcsCustomerRepository())->getCpfCnpjFromCustomField((int) ($client['id'] ?? 0), $customFieldId);
                } catch (\Throwable $e) {
                    $errors[] = trim($e->getMessage()) !== '' ? trim($e->getMessage()) : 'CPF/CNPJ do tomador inválido';
                }
            }
        } else {
            if (trim((string) ($client['address1'] ?? '')) === '') {
                $errors[] = 'logradouro do tomador no exterior não informado';
            }
            if (trim((string) ($client['city'] ?? '')) === '') {
                $errors[] = 'cidade do tomador no exterior não informada';
            }
            if (trim((string) ($client['state'] ?? '')) === '') {
                $errors[] = 'estado ou província do tomador no exterior não informado';
            }
            if (trim((string) ($client['postcode'] ?? '')) === '') {
                $errors[] = 'código postal do tomador no exterior não informado';
            }
        }

        if (!empty($errors)) {
            throw new NfseValidationException('Validação preventiva falhou: ' . implode('; ', array_values(array_unique($errors))) . '.');
        }
    }
}
