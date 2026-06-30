<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Exceptions\NfseValidationException;
use WHMCS\Database\Capsule;

final class DpsBuilderService
{
    public function build(array $config, array $invoice, array $items, array $client, string $tomadorCpfCnpj, int $numeroDps): object
    {
        $this->assertSdkAvailable();

        $tpAmb = ($config['environment'] ?? 'homologacao') === 'producao' ? 1 : 2;
        $tpEmit = 1;

        $cnpjEmissor = (string) ($config['cnpj_emissor'] ?? '');
        $codigoIbge = (string) ($config['codigo_ibge'] ?? '');
        $serieDps = (string) ($config['serie_dps'] ?? '1');
        $inscricaoMunicipal = (string) ($config['inscricao_municipal'] ?? '');
        $codigoServico = (string) ($config['codigo_servico'] ?? '');
        $nbsPadrao = (string) ($config['nbs_padrao'] ?? '');
        $aliquotaIss = (float) ($config['aliquota_iss'] ?? 0);

        $cnpjEmissorDigits = preg_replace('/\D/', '', $cnpjEmissor);
        if (!$cnpjEmissorDigits || strlen($cnpjEmissorDigits) !== 14) {
            throw new NfseValidationException('CNPJ do emissor inválido.');
        }

        if ($codigoIbge === '' || !ctype_digit($codigoIbge) || strlen($codigoIbge) !== 7) {
            throw new NfseValidationException('Código IBGE inválido (precisa ter 7 dígitos).');
        }

        $informarIm = (string) ($config['prestador_informar_im'] ?? '1');
        if ($informarIm === '1' && $inscricaoMunicipal === '') {
            throw new NfseValidationException('Inscrição municipal do emissor não informada.');
        }

        if ($codigoServico === '') {
            throw new NfseValidationException('Código do serviço não informado.');
        }
        $nbsDigits = preg_replace('/\D/', '', $nbsPadrao);
        if ($nbsDigits !== '' && strlen($nbsDigits) !== 9) {
            throw new NfseValidationException('NBS inválida (precisa ter 9 dígitos).');
        }

        $resolved = $this->resolveCodigoServicoAndNbs($invoice, $items, $codigoServico, $nbsDigits);
        $codigoServico = $resolved['codigo_servico'];
        $nbsDigits = $resolved['nbs'];

        $tomadorNome = $this->resolveTomadorNome($client);
        $tomadorEmail = (string) ($client['email'] ?? '');
        if ($tomadorEmail === '') {
            throw new NfseValidationException('E-mail do tomador não informado.');
        }

        $country = strtoupper(trim((string) ($client['country'] ?? '')));
        if ($country === '') {
            throw new NfseValidationException('País do tomador não informado.');
        }
        $isExterior = $country !== 'BR';

        $descricao = $this->buildDescricao($invoice, $items);

        $total = (float) ($invoice['total'] ?? 0);
        if ($total <= 0) {
            throw new NfseValidationException('Valor total da fatura inválido.');
        }

        $idDps = \Nfse\Support\IdGenerator::generateDpsId($cnpjEmissorDigits, $codigoIbge, $serieDps, $numeroDps);

        $prestadorNome = (string) ($config['prestador_nome'] ?? '');
        if ($tpEmit !== 1 && $prestadorNome === '') {
            throw new NfseValidationException('Razão social do prestador (xNome) não configurada.');
        }

        $prest = [
            'CNPJ' => $cnpjEmissorDigits,
        ];
        if ($tpEmit !== 1 && $prestadorNome !== '') {
            $prest['xNome'] = $prestadorNome;
        }
        if ($informarIm === '1' && $inscricaoMunicipal !== '') {
            $prest['IM'] = $inscricaoMunicipal;
        }

        $prestEmail = (string) ($config['prestador_email'] ?? '');
        if ($prestEmail !== '') {
            $prest['email'] = $prestEmail;
        }

        $prestFone = preg_replace('/\D/', '', (string) ($config['prestador_fone'] ?? ''));
        if ($prestFone) {
            $prest['fone'] = $prestFone;
        }

        $opSimpNac = (string) ($config['prestador_op_simp_nac'] ?? '');
        $regApTribSn = (string) ($config['prestador_reg_ap_trib_sn'] ?? '');
        $regEspTrib = (string) ($config['prestador_reg_esp_trib'] ?? '0');
        if ($opSimpNac === '') {
            throw new NfseValidationException('Opção Simples Nacional do prestador não configurada (opSimpNac).');
        }
        $isNaoOptanteSimples = $opSimpNac === '1';
        $prest['regTrib'] = [
            'opSimpNac' => $opSimpNac,
            'regApTribSN' => $regApTribSn !== '' ? $regApTribSn : null,
            'regEspTrib' => $regEspTrib !== '' ? $regEspTrib : '0',
        ];

        $prestCep = preg_replace('/\D/', '', (string) ($config['prestador_cep'] ?? ''));
        $prestLogradouro = (string) ($config['prestador_logradouro'] ?? '');
        $prestNumero = (string) ($config['prestador_numero'] ?? '');
        $prestComplemento = (string) ($config['prestador_complemento'] ?? '');
        $prestBairro = (string) ($config['prestador_bairro'] ?? '');

        if ($prestCep !== '' && $prestLogradouro !== '' && $prestNumero !== '' && $prestBairro !== '') {
            $prest['end'] = [
                'endNac' => [
                    'cMun' => $codigoIbge,
                    'CEP' => $prestCep,
                ],
                'xLgr' => $prestLogradouro,
                'nro' => $prestNumero,
                'xCpl' => $prestComplemento,
                'xBairro' => $prestBairro,
            ];
        }
        if ($tpEmit === 1) {
            unset($prest['end']);
        }

        $toma = [
            'xNome' => $tomadorNome,
            'email' => $tomadorEmail,
        ];

        if (!$isExterior) {
            $tomadorDigits = preg_replace('/\D/', '', $tomadorCpfCnpj);
            if (!$tomadorDigits || (strlen($tomadorDigits) !== 11 && strlen($tomadorDigits) !== 14)) {
                throw new NfseValidationException('CPF/CNPJ do tomador inválido.');
            }
            $tomadorIsCnpj = strlen($tomadorDigits) === 14;
            $toma[$tomadorIsCnpj ? 'CNPJ' : 'CPF'] = $tomadorDigits;

            $cep = preg_replace('/\D/', '', (string) ($client['postcode'] ?? ''));
            if ($cep) {
                $logradouro = trim((string) ($client['address1'] ?? ''));
                $complemento = trim((string) ($client['address2'] ?? ''));
                $numero = $this->inferNumeroEndereco($logradouro) ?? (string) ($config['tomador_numero_padrao'] ?? 'S/N');
                $bairro = (string) ($config['tomador_bairro_padrao'] ?? '');
                $tomadorCodigoIbge = null;
                $cidade = (string) ($client['city'] ?? '');
                $uf = (string) ($client['state'] ?? '');
                $tomadorCodigoIbge = (new IbgeService())->getIbgeCode($cidade, $uf, $cep);
                if ($tomadorCodigoIbge === null) {
                    $tomadorCodigoIbge = (string) ($config['tomador_codigo_ibge_padrao'] ?? $codigoIbge);
                }

                $toma['end'] = [
                    'endNac' => [
                        'cMun' => $tomadorCodigoIbge,
                        'CEP' => $cep,
                    ],
                    'xLgr' => $logradouro,
                    'nro' => $numero,
                    'xCpl' => $complemento,
                    'xBairro' => $bairro,
                ];
            }
        } else {
            $toma['cNaoNIF'] = '2';

            $logradouro = trim((string) ($client['address1'] ?? ''));
            $complemento = trim((string) ($client['address2'] ?? ''));
            $cidade = trim((string) ($client['city'] ?? ''));
            $estado = trim((string) ($client['state'] ?? ''));
            $endPost = trim((string) ($client['postcode'] ?? ''));
            $numero = $this->inferNumeroEndereco($logradouro) ?? (string) ($config['tomador_numero_padrao'] ?? 'S/N');
            $bairro = (string) ($config['tomador_bairro_padrao'] ?? '');

            if ($logradouro === '') {
                throw new NfseValidationException('Logradouro do tomador não informado.');
            }
            if ($cidade === '') {
                throw new NfseValidationException('Cidade do tomador (exterior) não informada.');
            }
            if ($estado === '') {
                throw new NfseValidationException('Estado/Província do tomador (exterior) não informado.');
            }
            if ($endPost === '') {
                throw new NfseValidationException('Código postal do tomador (exterior) não informado.');
            }

            $toma['end'] = [
                'endExt' => [
                    'cPais' => $country,
                    'cEndPost' => $endPost,
                    'xCidade' => $cidade,
                    'xEstProvReg' => $estado,
                ],
                'xLgr' => $logradouro,
                'nro' => $numero,
                'xCpl' => $complemento,
                'xBairro' => $bairro,
            ];
        }

        $tpRetIssqn = 1;
        $pAliq = $aliquotaIss;
        if ($opSimpNac === '2') {
            $pAliq = null;
        }
        if ($opSimpNac === '3' && $regApTribSn === '1' && $tpRetIssqn === 1) {
            $pAliq = null;
        }

        $trib = [
            'tribMun' => [
                'tribISSQN' => 1,
                'tpRetISSQN' => $tpRetIssqn,
                'pAliq' => $pAliq,
            ],
        ];
        if ($opSimpNac === '3') {
            $trib['totTrib'] = [
                'vTotTrib' => [
                    'vTotTribFed' => 0,
                    'vTotTribEst' => 0,
                    'vTotTribMun' => 0,
                ],
            ];
        } else {
            $trib['totTrib'] = [
                'indTotTrib' => 0,
            ];
        }

        $serv = [
            'locPrest' => [
                'cLocPrestacao' => $codigoIbge,
            ],
            'cServ' => [
                'cTribNac' => $codigoServico,
                'cNBS' => $nbsDigits !== '' ? $nbsDigits : null,
                'xDescServ' => $descricao,
            ],
        ];

        if ($isExterior) {
            if ($nbsDigits === '') {
                throw new NfseValidationException('Para tomador no exterior, é obrigatório informar NBS.');
            }

            $tpMoeda = '986';
            $currencyId = (int) ($client['currency'] ?? 0);
            if ($currencyId > 0) {
                try {
                    $cur = Capsule::table('tblcurrencies')->where('id', $currencyId)->first();
                    $currencyCode = strtoupper(trim((string) ($cur->code ?? '')));
                    $tpMoeda = $this->mapIsoCurrencyToBacen($currencyCode);
                } catch (\Throwable $e) {
                    $tpMoeda = '986';
                }
            }

            $comExt = [
                'mdPrestacao' => '1',
                'vincPrest' => '0',
                'tpMoeda' => $tpMoeda,
                'vServMoeda' => $this->formatDec15V2($total),
                'mecAFComexP' => '01',
                'mecAFComexT' => '01',
                'movTempBens' => '1',
                'mdic' => '0',
            ];

            $serv['comExt'] = $comExt;
        }

        $dps = new \Nfse\Dto\Nfse\DpsData([
            '@attributes' => ['versao' => '1.00'],
            'infDPS' => [
                '@attributes' => ['Id' => $idDps],
                'tpAmb' => $tpAmb,
                'dhEmi' => (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP'),
                'verAplic' => 'whmcs-nfse/0.1.0',
                'serie' => $serieDps,
                'nDPS' => (string) $numeroDps,
                'dCompet' => date('Y-m-d'),
                'tpEmit' => $tpEmit,
                'cLocEmi' => $codigoIbge,
                'prest' => $prest,
                'toma' => $toma,
                'serv' => $serv,
                'valores' => [
                    'vServPrest' => [
                        'vServ' => $total,
                    ],
                    'trib' => $trib,
                ],
            ],
        ]);

        return $dps;
    }

    private function formatDec15V2(float $value): string
    {
        $v = round($value, 2);
        if (abs($v) < 0.00001) {
            return '0';
        }
        return number_format($v, 2, '.', '');
    }

    private function mapIsoCurrencyToBacen(string $iso): string
    {
        $iso = strtoupper(trim($iso));
        if ($iso === '') {
            return '986';
        }
        if (preg_match('/^\d{3}$/', $iso)) {
            return $iso;
        }

        $map = [
            'BRL' => '790',
            'USD' => '220',
            'EUR' => '978',
            'GBP' => '826',
            'ARS' => '032',
            'CAD' => '124',
            'AUD' => '036',
            'CHF' => '756',
            'JPY' => '392',
            'CNY' => '156',
            'MXN' => '484',
            'CLP' => '152',
            'COP' => '170',
            'SEK' => '752',
            'NOK' => '578',
            'DKK' => '208',
            'NZD' => '554',
        ];

        return $map[$iso] ?? '986';
    }

    private function resolveCodigoServicoAndNbs(array $invoice, array $items, string $defaultCodigoServico, string $defaultNbsDigits): array
    {
        $invoiceId = (int) ($invoice['id'] ?? 0);
        if ($invoiceId <= 0) {
            return [
                'codigo_servico' => $defaultCodigoServico,
                'nbs' => $defaultNbsDigits,
            ];
        }

        $groupIds = $this->resolveGroupIdsFromInvoiceItems($items);
        if (empty($groupIds)) {
            return [
                'codigo_servico' => $defaultCodigoServico,
                'nbs' => $defaultNbsDigits,
            ];
        }

        $rows = Capsule::table('mod_opennfse_group_service_codes')
            ->whereIn('groupid', $groupIds)
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $gid = (int) ($r->groupid ?? 0);
            if ($gid <= 0) {
                continue;
            }
            if (isset($map[$gid])) {
                continue;
            }
            $map[$gid] = [
                'codigo_servico' => $r->codigo_servico !== null ? (string) $r->codigo_servico : '',
                'nbs' => $r->nbs !== null ? (string) $r->nbs : '',
            ];
        }

        $effectiveServicos = [];
        $effectiveNbsByServico = [];

        foreach ($groupIds as $gid) {
            $cfg = $map[$gid] ?? null;
            $serv = $cfg ? preg_replace('/\D/', '', (string) ($cfg['codigo_servico'] ?? '')) : '';
            $nbs = $cfg ? preg_replace('/\D/', '', (string) ($cfg['nbs'] ?? '')) : '';

            if ($serv === '') {
                $serv = preg_replace('/\D/', '', $defaultCodigoServico);
                $nbs = $defaultNbsDigits;
            } elseif ($nbs === '') {
                throw new NfseValidationException('NBS não configurada no mapeamento do grupo de produtos/serviços.');
            }

            if ($nbs !== '' && strlen($nbs) !== 9) {
                throw new NfseValidationException('NBS inválida no mapeamento de grupos (precisa ter 9 dígitos).');
            }

            if (!isset($effectiveNbsByServico[$serv])) {
                $effectiveNbsByServico[$serv] = [];
            }
            $effectiveNbsByServico[$serv][$nbs] = true;
            $effectiveServicos[$serv] = true;
        }

        $servicos = array_keys($effectiveServicos);
        if (count($servicos) !== 1) {
            throw new NfseValidationException('Invoice possui itens de grupos com códigos de serviço diferentes. Ajuste o mapeamento dos grupos para usar o mesmo código nesta invoice.');
        }

        $serv = (string) $servicos[0];
        $nbsSet = $effectiveNbsByServico[$serv] ?? [];
        $nbsList = array_keys($nbsSet);
        if (count($nbsList) !== 1) {
            throw new NfseValidationException('Invoice possui itens de grupos com NBS diferentes. Ajuste o mapeamento dos grupos para usar a mesma NBS nesta invoice.');
        }

        return [
            'codigo_servico' => $serv,
            'nbs' => (string) $nbsList[0],
        ];
    }

    private function resolveGroupIdsFromInvoiceItems(array $items): array
    {
        $hostingIds = [];
        foreach ($items as $it) {
            $type = (string) ($it['type'] ?? '');
            $relid = (int) ($it['relid'] ?? 0);
            if ($relid <= 0) {
                continue;
            }
            if ($type === 'Hosting') {
                $hostingIds[$relid] = true;
            }
        }

        if (empty($hostingIds)) {
            return [];
        }

        $hostingIdsList = array_keys($hostingIds);

        $rows = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->whereIn('h.id', $hostingIdsList)
            ->select(['p.gid'])
            ->get();

        $groupIds = [];
        foreach ($rows as $r) {
            $gid = (int) ($r->gid ?? 0);
            if ($gid > 0) {
                $groupIds[$gid] = true;
            }
        }

        return array_keys($groupIds);
    }

    private function resolveTomadorNome(array $client): string
    {
        $company = trim((string) ($client['companyname'] ?? ''));
        if ($company !== '') {
            return $company;
        }

        $first = trim((string) ($client['firstname'] ?? ''));
        $last = trim((string) ($client['lastname'] ?? ''));
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            throw new NfseValidationException('Nome do tomador não informado.');
        }
        return $name;
    }

    private function buildDescricao(array $invoice, array $items): string
    {
        $invoiceId = (string) ($invoice['id'] ?? '');
        $parts = [];
        foreach ($items as $it) {
            $desc = trim((string) ($it['description'] ?? ''));
            if ($desc !== '') {
                $parts[] = $desc;
            }
        }
        $base = 'Serviços referentes à fatura #' . $invoiceId;
        if (empty($parts)) {
            return $base;
        }

        $full = $base . ': ' . implode(' | ', $parts);
        return mb_substr($full, 0, 1900);
    }

    private function inferNumeroEndereco(string $logradouro): ?string
    {
        if ($logradouro === '') {
            return null;
        }

        if (preg_match('/\\b(\\d{1,6}[A-Za-z]?)\\b/u', $logradouro, $m)) {
            return (string) $m[1];
        }

        return null;
    }

    private function assertSdkAvailable(): void
    {
        if (!class_exists(\Nfse\Dto\Nfse\DpsData::class) || !class_exists(\Nfse\Support\IdGenerator::class)) {
            throw new NfseModuleException('SDK nfse-nacional/nfse-php não encontrada. Instale a pasta vendor do módulo.');
        }
    }
}
