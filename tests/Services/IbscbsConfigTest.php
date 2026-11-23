<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\IbscbsConfig;
use PHPUnit\Framework\TestCase;

final class IbscbsConfigTest extends TestCase
{
    public function testPadraoDesligadoECodigosDaHomologacao(): void
    {
        $this->assertFalse(IbscbsConfig::enabled([]));
        $this->assertFalse(IbscbsConfig::enabled(['ibscbs_enabled' => '0']));
        $this->assertFalse(IbscbsConfig::enabled(['ibscbs_enabled' => 0]));
        $this->assertTrue(IbscbsConfig::enabled(['ibscbs_enabled' => '1']));
        $this->assertTrue(IbscbsConfig::enabled(['ibscbs_enabled' => 1]));
        $this->assertSame([
            'cIndOp' => '100301',
            'cst' => '000',
            'cClassTrib' => '000001',
            'indDest' => '0',
        ], IbscbsConfig::codigos([]));
    }

    public function testValidaFormatoAoSalvar(): void
    {
        $this->assertSame([], IbscbsConfig::validate('0', '100301', '000', '000001', '0'));
        $this->assertSame([], IbscbsConfig::validate('1', '100301', '000', '000001', '1'));
        $this->assertNotEmpty(IbscbsConfig::validate('2', '100301', '000', '000001', '0'));
        $this->assertNotEmpty(IbscbsConfig::validate('1', '10030', '000', '000001', '0'));
        $this->assertNotEmpty(IbscbsConfig::validate('1', '100301', '00', '000001', '0'));
        $this->assertNotEmpty(IbscbsConfig::validate('1', '100301', '000', '00001', '0'));
        $this->assertNotEmpty(IbscbsConfig::validate('1', '100301', '000', '000001', '2'));
    }

    public function testMigrationCriaColunasComPadraoDesligado(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/054_alter_config_ibscbs.sql');
        $this->assertStringContainsString("ibscbs_enabled TINYINT NOT NULL DEFAULT 0", $sql);
        $this->assertStringContainsString("ibscbs_cindop VARCHAR(6) NOT NULL DEFAULT '100301'", $sql);
        $this->assertStringContainsString("ibscbs_cst VARCHAR(3) NOT NULL DEFAULT '000'", $sql);
        $this->assertStringContainsString("ibscbs_cclasstrib VARCHAR(6) NOT NULL DEFAULT '000001'", $sql);
        $this->assertStringContainsString("ibscbs_inddest VARCHAR(1) NOT NULL DEFAULT '0'", $sql);
    }
}
