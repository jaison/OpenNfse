<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\DpsLayoutService;
use PHPUnit\Framework\TestCase;

final class DpsLayoutServiceTest extends TestCase
{
    private DpsLayoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DpsLayoutService();
    }

    /** Horário de Brasília com offset fixo, sem depender do tzdata. */
    private static function at(string $dateTime, string $offset = '-03:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable(str_replace(' ', 'T', $dateTime) . $offset);
    }

    public function testAutomaticUsesNt009InHomologationRegardlessOfDate(): void
    {
        $this->assertSame(
            DpsLayoutService::LAYOUT_NT009,
            $this->service->resolve(DpsLayoutService::MODE_AUTO, 'homologacao', self::at('2026-10-28 10:00:00'))
        );
    }

    public function testAutomaticKeepsPreviousLayoutInProductionBeforeStart(): void
    {
        $this->assertSame(
            DpsLayoutService::LAYOUT_PRE_NT009,
            $this->service->resolve(DpsLayoutService::MODE_AUTO, 'producao', self::at('2026-11-30 23:59:59'))
        );
    }

    public function testAutomaticSwitchesProductionAtMidnightSaoPaulo(): void
    {
        $this->assertSame(
            DpsLayoutService::LAYOUT_NT009,
            $this->service->resolve(DpsLayoutService::MODE_AUTO, 'producao', self::at('2026-12-01 00:00:00'))
        );
    }

    public function testAutomaticComparesInstantsNotLocalClock(): void
    {
        // 02:59:59 UTC ainda é 30/11 23:59:59 em Brasília (UTC-3).
        $this->assertSame(
            DpsLayoutService::LAYOUT_PRE_NT009,
            $this->service->resolve(DpsLayoutService::MODE_AUTO, 'producao', self::at('2026-12-01 02:59:59', 'Z'))
        );
        $this->assertSame(
            DpsLayoutService::LAYOUT_NT009,
            $this->service->resolve(DpsLayoutService::MODE_AUTO, 'producao', self::at('2026-12-01 03:00:00', 'Z'))
        );
    }

    public function testCutoverIgnoresServerTimezoneAndDst(): void
    {
        $original = date_default_timezone_get();
        try {
            // Fuso do servidor diferente e um fuso hipotético com horário de verão (UTC-2).
            date_default_timezone_set('Asia/Tokyo');
            $auto = DpsLayoutService::MODE_AUTO;
            $this->assertSame(
                DpsLayoutService::LAYOUT_PRE_NT009,
                $this->service->resolve($auto, 'producao', self::at('2026-12-01 00:59:59', '-02:00'))
            );
            $this->assertSame(
                DpsLayoutService::LAYOUT_NT009,
                $this->service->resolve($auto, 'producao', self::at('2026-12-01 01:00:00', '-02:00'))
            );
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testScreenTextShowsCutoverInBrasiliaTime(): void
    {
        $text = $this->service->describe(
            ['environment' => 'producao', 'dps_leiaute' => DpsLayoutService::MODE_AUTO],
            self::at('2026-11-15 12:00:00')
        );
        $this->assertStringContainsString('01/12/2026 00:00', $text);
    }

    public function testOverridesWinOverEnvironmentAndDate(): void
    {
        $this->assertSame(
            DpsLayoutService::LAYOUT_PRE_NT009,
            $this->service->resolve(DpsLayoutService::MODE_PRE_NT009, 'homologacao', self::at('2026-12-15 10:00:00'))
        );
        $this->assertSame(
            DpsLayoutService::LAYOUT_NT009,
            $this->service->resolve(DpsLayoutService::MODE_NT009, 'producao', self::at('2026-11-01 10:00:00'))
        );
    }

    public function testUnknownOrMissingModeFallsBackToAutomatic(): void
    {
        $this->assertSame(DpsLayoutService::MODE_AUTO, DpsLayoutService::normalizeMode('xyz'));
        $this->assertSame(DpsLayoutService::MODE_AUTO, DpsLayoutService::normalizeMode(null));
        $this->assertSame(
            DpsLayoutService::LAYOUT_PRE_NT009,
            $this->service->effectiveLayout(['environment' => 'producao'], self::at('2026-11-15 12:00:00'))
        );
    }

    public function testVersaoDpsPerLayout(): void
    {
        $this->assertSame('1.00', $this->service->versaoDps(DpsLayoutService::LAYOUT_PRE_NT009));
        $this->assertSame('1.01', $this->service->versaoDps(DpsLayoutService::LAYOUT_NT009));
    }

    public function testLayoutRejectionHintSuggestsOtherLayout(): void
    {
        $prodRestrita = 'Erro na emissão: [{"Codigo":"E1235","Descricao":"Falha Schema Xml",'
            . '"Complemento":"The element \'infDPS\' has invalid child element \'cLocEmi\'. '
            . 'List of possible elements expected: \'cMotivoEmisTI, finNFSe\'."}]';
        $hint = DpsLayoutService::layoutRejectionHint($prodRestrita, DpsLayoutService::LAYOUT_PRE_NT009);
        $this->assertNotNull($hint);
        $this->assertStringContainsString('espera o leiaute NT 009', $hint);

        $producao = 'Erro na emissão: [{"Codigo":"E1235",'
            . '"Complemento":"The element \'infDPS\' has invalid child element \'finNFSe\'."}]';
        $hint = DpsLayoutService::layoutRejectionHint($producao, DpsLayoutService::LAYOUT_NT009);
        $this->assertNotNull($hint);
        $this->assertStringContainsString('espera o leiaute Pré-NT 009', $hint);
    }

    public function testLayoutRejectionHintIgnoresOtherErrors(): void
    {
        $nt009 = DpsLayoutService::LAYOUT_NT009;
        $this->assertNull(DpsLayoutService::layoutRejectionHint('E1235 vServMoeda pattern', $nt009));
        $this->assertNull(DpsLayoutService::layoutRejectionHint('E0001 finNFSe', $nt009));
    }

    public function testLayoutRejectionHintRecognizesExpectedListWithFinNfse(): void
    {
        $msg = 'E1235: The element \'infDPS\' has invalid child element \'chNFSeRej\'. '
            . 'List of possible elements expected: \'cMotivoEmisTI, finNFSe\'.';
        $this->assertNotNull(DpsLayoutService::layoutRejectionHint($msg, DpsLayoutService::LAYOUT_PRE_NT009));
    }

    public function testLayoutRejectionHintIgnoresValueErrorOnCLocEmi(): void
    {
        // E1235 de VALOR: código IBGE inválido em cLocEmi não é problema de leiaute.
        $msg = 'Erro na emissão: [{"Codigo":"E1235","Descricao":"Falha Schema Xml",'
            . '"Complemento":"The \'http://www.sped.fazenda.gov.br/nfse:cLocEmi\' element is invalid - '
            . 'The value \'355030\' is invalid according to its datatype \'TSCodMunIBGE\' - '
            . 'The Pattern constraint failed."}]';
        $this->assertNull(DpsLayoutService::layoutRejectionHint($msg, DpsLayoutService::LAYOUT_NT009));
        $this->assertNull(DpsLayoutService::layoutRejectionHint($msg, DpsLayoutService::LAYOUT_PRE_NT009));

        $finNfseValor = 'E1235: The \'http://www.sped.fazenda.gov.br/nfse:finNFSe\' element is invalid - '
            . 'The value \'1\' is invalid according to its datatype \'TSRTCFinNFSe\' - '
            . 'The Enumeration constraint failed.';
        $this->assertNull(DpsLayoutService::layoutRejectionHint($finNfseValor, DpsLayoutService::LAYOUT_NT009));
    }
}
