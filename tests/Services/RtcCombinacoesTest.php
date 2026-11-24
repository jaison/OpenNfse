<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Repositories\LogRepository;
use OpenNfse\Services\DpsLayoutService;
use OpenNfse\Services\IbscbsConfig;
use OpenNfse\Services\RtcCombinacoes;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class RtcCombinacoesTest extends TestCase
{
    protected function setUp(): void
    {
        Capsule::reset();
    }

    public function testFormatoDoCodigoDeServico(): void
    {
        $rtc = $this->rtc();
        $this->assertSame(['1.03.01', '1.03'], $rtc->codigosListaServico('010301'));
        $this->assertSame(['1.03.01', '1.03'], $rtc->codigosListaServico('01.03.01'));
        $this->assertSame(['99.01.01', '99.01'], $rtc->codigosListaServico('990101'));
    }

    public function testAvaliarValidaInvalidaEDesconhecida(): void
    {
        $rtc = $this->rtc();
        $dentro = new \DateTimeImmutable('2026-06-30');
        $depois = new \DateTimeImmutable('2026-07-01');
        $antes = new \DateTimeImmutable('2025-12-31');

        $this->assertSame(RtcCombinacoes::VALIDA, $rtc->avaliar('010301', '115061000', '100301', '000001', $dentro));
        $this->assertSame(RtcCombinacoes::VALIDA, $rtc->avaliar('010301', '115061000', '100301', '000001', $depois));
        $this->assertSame(RtcCombinacoes::DESCONHECIDA, $rtc->avaliar('010301', '115061000', '100301', '000001', $antes));
        $this->assertSame(RtcCombinacoes::INVALIDA, $rtc->avaliar('010301', '115061000', '100301', '000002', $depois));
        $this->assertSame(RtcCombinacoes::DESCONHECIDA, $rtc->avaliar('010301', '999999999', '100301', '000001', $depois));
        $this->assertSame(RtcCombinacoes::VALIDA, $rtc->avaliar('990101', '123456789', '100301', '000001', $depois));
        $this->assertSame(RtcCombinacoes::VALIDA, $rtc->avaliar('010501', '111111111', '100302', '000002', $depois));
    }

    public function testVigenciaInclusivaNosDoisExtremos(): void
    {
        $rtc = $this->rtc();
        $this->assertSame(
            RtcCombinacoes::VALIDA,
            $rtc->avaliar('010701', '333333333', '100301', '000001', new \DateTimeImmutable('2026-01-01'))
        );
        $this->assertSame(
            RtcCombinacoes::VALIDA,
            $rtc->avaliar('010701', '333333333', '100301', '000001', new \DateTimeImmutable('2026-06-30'))
        );
        $this->assertSame(
            RtcCombinacoes::DESCONHECIDA,
            $rtc->avaliar('010701', '333333333', '100301', '000001', new \DateTimeImmutable('2026-07-01'))
        );
    }

    public function testOpcoesENbsDaTabela(): void
    {
        $rtc = $this->rtc();
        $data = new \DateTimeImmutable('2026-07-01');
        $pares = array_map(
            static fn (array $opcao): string => $opcao['cIndOp'] . '/' . $opcao['cClassTrib'],
            $rtc->opcoes('010301', '115061000', $data)
        );
        $this->assertContains('100301/000001', $pares);
        $this->assertContains('100302/000002', $pares);
        $this->assertContains('115061000', $rtc->nbsPara('010301', $data));
        $this->assertContains('123456789', $rtc->nbsPara('990101', $data));
    }

    public function testAvisoNaConfigNaoBloqueiaOSalvamento(): void
    {
        $rtc = $this->rtc();
        $data = new \DateTimeImmutable('2026-07-01');
        $html = $rtc->htmlPainel('010301', '115061000', '100301', '000002', [
            ['rotulo' => 'Grupo 7', 'codigo_servico' => '010501', 'nbs' => '111111111'],
        ], $data);

        $this->assertStringContainsString('Combinação fora da tabela oficial', $html);
        $this->assertStringContainsString('Grupo 7', $html);
        $this->assertStringContainsString('value="100301"', $html);
        $this->assertStringContainsString('<datalist id="nfse-rtc-cindop">', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertSame([], IbscbsConfig::validate('1', '100301', '000', '000002'));

        $valida = $rtc->htmlPainel('010301', '115061000', '100301', '000001', [], $data);
        $this->assertStringNotContainsString('Combinação fora da tabela oficial', $valida);
        $this->assertStringContainsString('cIndOp 100301', $valida);
    }

    public function testLogNaEmissaoSoQuandoInvalida(): void
    {
        $rtc = $this->rtc();
        $logs = new LogRepository();
        $data = new \DateTimeImmutable('2026-07-01');
        $config = [
            'ibscbs_enabled' => '1',
            'ibscbs_cindop' => '100301',
            'ibscbs_cst' => '000',
            'ibscbs_cclasstrib' => '000002',
        ];

        $rtc->registrarSeInvalida($logs, 15, 'corr-1', $config, DpsLayoutService::LAYOUT_NT009, '010301', '115061000', $data);
        $gravados = Capsule::$inserts['mod_opennfse_logs'] ?? [];
        $this->assertCount(1, $gravados);
        $this->assertSame(RtcCombinacoes::LOG_FORA_TABELA, $gravados[0]['tipo']);
        $this->assertSame(15, $gravados[0]['nota_id']);
        $payload = json_decode((string) $gravados[0]['request'], true);
        $this->assertSame('010301', $payload['cTribNac']);
        $this->assertSame('115061000', $payload['nbs']);
        $this->assertSame('000002', $payload['cClassTrib']);
        $this->assertArrayNotHasKey('cliente', $payload);
        $this->assertArrayNotHasKey('cpf', $payload);

        Capsule::reset();
        $config['ibscbs_cclasstrib'] = '000001';
        $rtc->registrarSeInvalida($logs, 15, 'corr-1', $config, DpsLayoutService::LAYOUT_NT009, '010301', '115061000', $data);
        $rtc->registrarSeInvalida($logs, 15, 'corr-1', $config, DpsLayoutService::LAYOUT_PRE_NT009, '010301', '115061000', $data);
        $config['ibscbs_enabled'] = '0';
        $config['ibscbs_cclasstrib'] = '000002';
        $rtc->registrarSeInvalida($logs, 15, 'corr-1', $config, DpsLayoutService::LAYOUT_NT009, '010301', '115061000', $data);
        $rtc->registrarSeInvalida($logs, 15, 'corr-1', [
            'ibscbs_enabled' => '1',
            'ibscbs_cindop' => '100301',
            'ibscbs_cclasstrib' => '000001',
        ], DpsLayoutService::LAYOUT_NT009, '010301', '999999999', $data);
        $this->assertSame([], Capsule::$inserts);
    }

    public function testPainelComNbsVaziaListaAsVigentes(): void
    {
        $combinacoes = [];
        for ($i = 1; $i <= 22; $i++) {
            $codigo = sprintf('1000000%02d', $i);
            $combinacoes[] = $this->linha('2.01', $codigo, '100301', '000001', '2026-01-01', null);
        }
        $combinacoes[] = $this->linha('3.01', '300000001', '100302', '000002', '2026-01-01', null);
        $rtc = new RtcCombinacoes([
            'indop' => [
                '100301' => ['descricao' => 'Demais', 'local' => 'Tomador'],
                '100302' => ['descricao' => 'Outra', 'local' => 'Prestador'],
            ],
            'classtrib' => [
                '000001' => 'Integral',
                '000002' => 'Reduzida',
            ],
            'nbs' => [
                '100000001' => 'A & B <script>',
            ],
            'combinacoes' => $combinacoes,
        ]);
        $html = $rtc->htmlPainel('020101', '', '100301', '000001', [
            ['rotulo' => 'Grupo <2>', 'codigo_servico' => '030101', 'nbs' => ''],
        ], new \DateTimeImmutable('2026-07-01'));

        $this->assertStringContainsString('NBS vigentes', $html);
        $this->assertStringContainsString('NBS 100000001 — A &amp; B &lt;script&gt;', $html);
        $this->assertStringContainsString('NBS 100000002', $html);
        $this->assertStringContainsString('NBS 100000020', $html);
        $this->assertStringContainsString('cIndOp 100301', $html);
        $this->assertStringContainsString('cClassTrib 000001', $html);
        $this->assertStringContainsString('e mais 2', $html);
        $this->assertStringContainsString('Grupo &lt;2&gt;', $html);
        $this->assertStringNotContainsString('100000021', $html);
        $this->assertStringNotContainsString('100000022', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('Sem combinação oficial', $html);
        $this->assertStringContainsString('NBS 300000001', $html);
        $this->assertStringContainsString('value="100301"', $html);
        $this->assertStringContainsString('value="100302"', $html);
    }

    public function testBaseOficialDoProcessamentoDeDados(): void
    {
        $rtc = new RtcCombinacoes();
        $data = new \DateTimeImmutable('2026-11-15');
        $this->assertSame(
            RtcCombinacoes::VALIDA,
            $rtc->avaliar('010301', '115061000', '100301', '000001', $data)
        );
        $pares = array_map(
            static fn (array $opcao): string => $opcao['cIndOp'] . '/' . $opcao['cClassTrib'],
            $rtc->opcoes('010301', '115061000', $data)
        );
        $this->assertContains('100301/000001', $pares);
        $this->assertSame(
            RtcCombinacoes::VALIDA,
            $rtc->avaliar('010301', '115061000', '100301', '000001', new \DateTimeImmutable('2026-12-15'))
        );
    }

    private function rtc(): RtcCombinacoes
    {
        return new RtcCombinacoes([
            'indop' => [
                '100301' => ['descricao' => 'Demais serviços', 'local' => 'Endereço do tomador'],
                '100302' => ['descricao' => 'Outra operação', 'local' => 'Endereço do prestador'],
            ],
            'classtrib' => [
                '000001' => 'Integral',
                '000002' => 'Reduzida',
            ],
            'combinacoes' => [
                $this->linha('1.03', '115061000', '100301', '000001', '2026-01-01', '2026-06-30'),
                $this->linha('1.03', '115061000', '100301', '000001', '2026-07-01', null),
                $this->linha('1.03', '115061000', '100302', '000002', '2026-01-01', null),
                $this->linha('99.01.01', '123456789', '100301', '000001', '2026-01-01', null),
                $this->linha('1.05', '111111111', '100302', '000002', '2026-01-01', null),
                $this->linha('1.07', '333333333', '100301', '000001', '2026-01-01', '2026-06-30'),
            ],
        ]);
    }

    /**
     * @return array{ls: string, nbs: string, indop: string, classtrib: string, inicio: string, fim: ?string, onerosa: string, exterior: string}
     */
    private function linha(
        string $ls,
        string $nbs,
        string $indop,
        string $classtrib,
        string $inicio,
        ?string $fim
    ): array {
        return [
            'ls' => $ls,
            'nbs' => $nbs,
            'indop' => $indop,
            'classtrib' => $classtrib,
            'inicio' => $inicio,
            'fim' => $fim,
            'onerosa' => '1',
            'exterior' => '0',
        ];
    }
}
